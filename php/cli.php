<?php

WP_CLI::add_command( 'dfm-migration', 'DFM_Migration_CLI' );

class DFM_Migration_CLI extends WP_CLI {

	/**
	 * Imports a list of terms from a csv file
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
	
	/*
	 * Imports users from a CSV file and populates additional info in the guest_author post_meta
	 *
	 * NOTE: This is to be used on stage environments only and is not intended for use on VIP Production environments
	 *
	 * @see: https://github.com/wp-cli/wp-cli/blob/master/php/commands/user.php#L656
	 *
	 * @subcommand import-users-with-additional-info
	 
	 * ## OPTIONS
	 *
	 * ## EXAMPLES
	 *
	 * wp dfm-migration import_users_with_additional_info /path/to/csv.csv
	 *
	 */
	public function import_users_with_additional_info( $args, $assoc_args ) {

		$filename = $args[0];

		if ( 0 === stripos( $filename, 'http://' ) || 0 === stripos( $filename, 'https://' ) ) {

			$response = wp_remote_head( $filename );
			$response_code = (string) wp_remote_retrieve_response_code( $response );

			if ( in_array( $response_code[0], array( 4, 5 ), true ) ) {

				WP_CLI::error( "Couldn't access remote CSV file (HTTP {$response_code} response)." );

			}

		} else if ( ! file_exists( $filename ) ) {

			WP_CLI::error( sprintf( 'Missing file: %s', $filename ) );

		}

		// Get the CSV Contents
		$new_users = new \WP_CLI\Iterators\CSV( $filename );

		// Show the initial message of how many users are importing
		WP_CLI::success( __( 'Importing users...', 'mason' ) );

		// Iterate over the CSV
		foreach ( $new_users as $i => $new_user ) {

			// Capture the role in a variable for later use
			$new_user_role = $new_user['role'];

			// Set Default user attributes
			$defaults = array(
				'role' => 'subscriber',
				'user_pass' => wp_generate_password(),
				'user_registered' => strftime( '%F %T', time() ),
				'display_name' => false,
				'user_login' => esc_html( $new_user['email'] ),
				'user_email' => esc_html($new_user['email'] ),
			);

			// Merger the default data with the $new_user data
			$new_user = array_merge( $defaults, $new_user );

			// Set the $new_user_role
			$new_user['role'] = ( ! empty( get_role( $new_user_role )->name ) ) ? get_role( $new_user_role )->name : 'subscriber';

			// User already exists and we just need to add them to the site if they aren't already there
			$existing_user = get_user_by( 'email', $new_user['email'] );

			// Try and find the user by user_login
			if ( ! $existing_user ) {

				$existing_user = get_user_by( 'login', $new_user['user_login'] );

			}

			// If the user already exists
			if ( $existing_user ) {

				// Set the $user_id as the ID of the existing user
				$user_id = $existing_user->ID;
				WP_CLI::line( __( 'Existing user was found: ', 'mason' ) . $existing_user->ID . ' : ' . $existing_user->display_name );

			// Create the user
			} else {

				WP_CLI::line( __( 'No Existing user was found for ', 'mason' ) . $new_user['first_name'] . ' ' . $new_user['last_name'] );
				WP_CLI::line( __( 'Creating a new user...', 'mason' ) );

				unset( $new_user['ID'] ); // Unset else it will just return the ID

				// Create a new user
				$user_id = wp_insert_user( $new_user );

				// Show the new user message
				WP_CLI::success( 'New User Created. ID: ' . $user_id );

			}

			// If No User ID is available (no existing user was found or no user was created)
			if ( empty( $user_id ) ) {

				WP_CLI::warning( 'No user was found or created for ' . $new_user['first_name'] . ' ' . $new_user['last_name'] );

			} else {

				// Access coauthors plus global
				global $coauthors_plus;

				// Check if there's already a coauthor connected to this author
				// Note: coauthors get created automatically when new users are created by a sweet
				// hook Ryan Kanner created over in inc/users.php
				// So we should always have an $existing_coauthor get returned at this point
				$existing_coauthor = $coauthors_plus->get_coauthor_by( 'user_login', $new_user['user_login'] );
				$guest_author_id = ( ! empty( $existing_coauthor->ID ) ) ? $existing_coauthor->ID : '';

				// If no Guest Author was found for the $new_user
				if ( empty( $guest_author_id ) ) {

					// Something must've failed with the coauthor creation hook, so display a warning so we know what author needs their data updated still
					WP_CLI::warning( __( 'Uh oh. No Guest Author was found for: ', 'mason' ) . $new_user['first_name'] . ' ' . $new_user['last_name'] );
					WP_CLI::warning( __( 'Their author profile will not be updated by this import.', 'mason' ) . $new_user['first_name'] . ' ' . $new_user['last_name'] );

				} else {

					WP_CLI::line( __( 'Updating coauthor profile meta', 'mason' ) );

					// Map the spreadsheet data to the Co Author post_meta fields
					$guest_author_meta = array(
						'cap-first_name' => esc_html( $new_user['first_name'] ),
						'cap-last_name' => esc_html( $new_user['last_name'] ),
						'cap-job_title' => esc_html( $new_user['title'] ),
						'cap-phone' => esc_html( $new_user['phone'] ),
						'cap-website' => esc_html( get_bloginfo( 'url' ),
						'cap-user_email' => esc_html( $new_user['email'] ),
						'cap-twitter' => esc_html( $new_user['twitter'] ),
						'cap-description' => esc_html( $new_user['bio'] ),
						'cap_full_bio' => esc_html(  $new_user['bio'] ),
					);

					// Loop through the guest_author_meta
					foreach ( $guest_author_meta as $key => $value ) {

						if ( ! empty( $key ) && ! empty( $value ) ) {

							// Update the new_guest_author meta
							update_post_meta( $guest_author_id, $key, $value );

							// Display a message for what data was set for the author
							WP_CLI::line( 'set guest author ' . $guest_author_id . ' ' . $key . ' as ' . $value );

						}

					}

					WP_CLI::line( __( 'Profile meta updated...', 'mason' ) );

				}

			}

			// Display success for the imported user
			WP_CLI::success( __( 'User and Guest Author Profile imported for ', 'mason' ) . $new_user['first_name'] . ' ' . $new_user['last_name'] );

		}

		// Show the final success message
		WP_CLI::success( 'Done!' );
	}

}
