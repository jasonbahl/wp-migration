<?php
/*
 * Plugin Name: DFM Wordpress Migration
 * Plugin URI:  https://github.com/dfmedia/wp-migration
 * Description: Defer term counting for bulk loading of posts
 * Version:     0.1
 * Author:      Digital First Media
 * License:     GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// don't allow direct access to this file
if ( ! defined( 'ABSPATH' ) ) {
        die( 'Goodbye' );
}

/**
 * Defer term counting because we are bulk uploading
 *
*/
function dfm_defer_term_counting () {
        wp_defer_term_counting( true );
}

add_action( 'after_theme_setup', 'dfm_defer_term_counting' );

// kill our thumbnails for migration
add_filter( 'intermediate_image_sizes', '__return_empty_array' );

/**
 * This adds a coauthors field to the main post REST endpoint. 
 * On GET it outputs an array of authors and on POST/PUT, an array of author 
 * term ids will attach the authors to the article
 */
class Migration_Coauthors_Rest_Endpoint {

	/**
	 * Jason_Test_Cap_Fields constructor.
	 */
	public function __construct() {

		// adds support for dfm_author fields
		add_action( 'rest_api_init', array( $this, 'register_coauthors_field' ) );

	}

	/**
	 *
	 */
	function register_coauthors_field() {

		global $coauthors_plus;

		register_rest_field( 'post', 'coauthors', array(
			'get_callback' => array( $this, 'get_coauthors' ),
			'update_callback' => array( $this, 'update_coauthors' ),
			'schema' => null,
		) );

	}

	/**
	 * @param $post
	 * @param $field_name
	 * @param $request
	 */
	public function get_coauthors( $post, $field_name, $request ) {

		$output = '';

		if ( 'coauthors' === $field_name ) {

			$coauthors = get_coauthors( $post['id'] );
			$output = $coauthors;

		}

		return $output;

	}

	/**
	 * @param $value
	 * @param $post
	 * @param $field_name
	 */
	function update_coauthors( $value, $post, $field_name ) {

		global $coauthors_plus;

		$coauthor_post_objects = array();

		// If we're on the 'coauthors' fieldname
		if ( 'coauthors' === $field_name && ! empty( $value ) ) {

			// Loop through the author id's
			foreach ( $value as $author_id ) {

				// Get the author post id
				$coauthor_term = wpcom_vip_get_term_by( 'id', $author_id, 'author' );

				if ( ! empty( $coauthor_term->slug ) ) {
					$coauthor_post_objects[] = $coauthor_term->slug;
				}

			}

			// If there's a populated array of $coauthor_post_objects
			if ( ! empty( $coauthor_post_objects ) ){

				// Add the coauthors
				$coauthors_plus->add_coauthors( $post->ID, $coauthor_post_objects );

			}

		}

		return false;

	}

}

new Migration_Coauthors_Rest_Endpoint();
