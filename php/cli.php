<?php

WP_CLI::add_command( 'dfm-migration', 'DFM_Migration_CLI' );

class DFM_Migration_CLI extends WP_CLI {

	/**
	 * Imports a list of terms from a scv file
	 *
	 * ## OPTIONS
	 *
	 * <taxonomy>
	 * : The name of the taxonomy you want to upload your terms to.
	 *
	 * [--file=<filename>]
	 * : The path and name of the csv file you want to import your terms from
	 *
	 * ## EXAMPLES
	 *
	 * 		wp dfm-migration import-terms location --file=wp-contents/uploads/2016/12/locations.csv
	 *
	 * ## SPREADSHEET CONFIGURATION
	 *
	 * 		*******************************************************************************************************
	 * 		*************************************************** IMPORTANT NOTE ************************************
	 * 		*******************************************************************************************************
	 *
	 * 		The first row of the CSV will be skipped in the import. The items in each of the columns become the the
	 * 		array keys for each column in each row. The value that you set here is unimportant, as it is retrieved
	 * 		dynamically. Something like "term_1", "term_2", "term_3" etc... will work fine.
	 *
	 *
	 * This script reads the spreadsheet from left to right, so your highest terms in the hierarchy should be on the
	 * left, and then the children will go on the right. You can have as many levels as you would like, and this script
	 * will automatically set the parent as long as your spreadsheet reads from left to right. It will automatically
	 * skip terms that have already been imported, so no need to worry about duplicates.
	 *
	 * Another thing good to note is that if you need to upload non-hierarchical terms, you can just upload a csv with 1 column.
	 *
	 * @subcommand import-terms
	 *
	 * @param array $args non-flagged arguments
	 * @param array $assoc_args Flagged arguments
	 */
	public function import_terms( $args, $assoc_args ) {

		$taxonomy = $args[0];

		// Bail if a taxonomy isn't specified
		if ( empty( $taxonomy ) ) {
			WP_CLI::error( __( 'Please specify the taxonomy you would like to import your terms for', 'wp-migration' ) );
		}

		$filename = $assoc_args['file'];

		// Bail if a filename isn't specified, or the file doesn't exist
		if ( empty( $filename ) || ! file_exists( $filename ) ) {
			WP_CLI::error( __( 'Please specify the filename of the csv you are trying to import', 'wp-migration' ) );
		}

		// If the taxonomy doesn't exist, bail
		if ( false === get_taxonomy( $taxonomy ) ) {
			WP_CLI::error( sprintf( __( 'The taxonomy with the name %s does not exist, please use a taxonomy that does exist', 'wp-migration' ), $taxonomy ) );
		}

		// Use the wp-cli built in uitility to open up the csv file and position the pointer at the beginning of it.
		$terms = new \WP_CLI\Iterators\CSV( $filename );

		WP_CLI::success( __( 'Starting import process...', 'wp-migration' ) );
		$terms_added = 0;

		// Loop through each of the rows in the csv
		foreach ( $terms as $term_row ) {

			// dynamically get the array keys for the row. Essentially a way to reference each of the columns in the row
			$array_keys = array_keys( $term_row );
			$term_parent = '';

			$i = 0;

			// Loop through each of the columns within the current row we are in
			foreach ( $term_row as $term ) {

				// If the cell is empty skip it.
				if ( empty( $term ) ) {
					continue;
				}

				$parent_id = 0;

				// If we are on the first column of the row, we can skip this since there will be no parent
				if ( 0 !== $i ) {

					// Continue looking for a parent until we find one
					for ( $count = $i; $count > 0; ++$count ) {

						// Find the key for the previous column
						$term_parent_key = $array_keys[ ( $count - 1 ) ];

						// move array pointer back one key to find the parent term (if there is one)
						$term_parent = $term_row[ $term_parent_key ];

						if ( ! empty( $term_parent ) ) {
							break;
						}

					}

				}

				// If there's a parent term in the cell to the left, find the ID and pass it when creating the term
				if ( ! empty( $term_parent ) ) {

					// Retrieve the parent term object by the name in the cell so we can grab the ID.
					if ( function_exists( 'wpcom_vip_get_term_by' ) ) {
						$parent_obj = wpcom_vip_get_term_by( 'name', $term_parent, $taxonomy );
						$parent_id = $parent_obj->term_id;
					} else {
						$parent_obj = get_term_by( 'name', $term_parent, $taxonomy );
						$parent_id = $parent_obj->term_id;
					}

				}

				// Find out if the term already exists.
				if ( function_exists( 'wpcom_vip_term_exists' ) ) {
					$term_exists = wpcom_vip_term_exists( $term, $taxonomy, $parent_id );
				} else {
					$term_exists = term_exists( $term, $taxonomy, $parent_id );
				}

				// Don't do anything if the term already exists
				if ( ! $term_exists ) {

					// Attempt to insert the term.
					$result = wp_insert_term( $term, $taxonomy, array( 'parent' => $parent_id ) );

					if ( ! is_wp_error( $result ) ) {
						WP_CLI::success( sprintf( __( 'Successfully added the term: %1$s to the %2$s taxonomy with a parent of: %3$s', 'wp-migration' ), $term, $taxonomy, $term_parent ) );
						$terms_added++;
					} else {
						WP_CLI::warning( sprintf( __( 'Could not add term: %s error printed out below', 'wp-migration' ), $term ) );
						WP_CLI::warning( $result );
					}

				}

				$i++;

			}

		}

		// Woohoo! We made it!
		WP_CLI::success( sprintf( __( 'Successfully imported %d terms. See the taxonomy structure below', 'wp-migration' ), $terms_added ) );
		$term_tree = WP_CLI::runcommand( sprintf( 'term list %s --fields=term_id,name,parent', $taxonomy ) );
		echo esc_html( $term_tree );

	}

}
