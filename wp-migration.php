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
        return wp_defer_term_counting( true );
}

add_action( 'add_term_relationship', 'dfm_defer_term_counting' );

function dfm_defer_term_counting () {
        return wp_defer_term_counting( false );
}

add_action( 'set_object_terms', 'dfm_defer_term_counting' );


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

		// Start a fresh array
		$coauthor_post_slugs = array();

		// If we're on the 'coauthors' fieldname
		if ( 'coauthors' === $field_name && ! empty( $value ) ) {

			// Loop through the author id's
			foreach ( $value as $author_id ) {

				// Get the author post id
				$coauthor_term = wpcom_vip_get_term_by( 'id', $author_id, 'author' );

				// If the $coauthor_term is a proper term with a slug
				if ( ! empty( $coauthor_term->slug ) ) {

					// Remove "cap-" from the term slug
					$coauthor_post_slugs[] = str_ireplace( 'cap-', '', $coauthor_term->slug );

				}

			}

			// If there's a populated array of $coauthor_post_objects
			if ( ! empty( $coauthor_post_slugs ) ){

				// Add the coauthors
				$this->add_coauthors( $post->ID, $coauthor_post_slugs );

			}

		}

		return false;

	}

	/**
	 * Altered version of add_coauthors from Co Authors Plus plugin
	 * This allows term caching to be deferred which is necessary for
	 * bulk migrations
	 */
	function add_coauthors( $post_id, $coauthors, $append = false ) {

		global $current_user, $wpdb, $coauthors_plus;

		$post_id = (int) $post_id;
		$insert = false;

		// Best way to persist order
		if ( $append ) {
			$existing_coauthors = wp_list_pluck( get_coauthors( $post_id ), 'user_login' );
		} else {
			$existing_coauthors = array();
		}

		// A co-author is always required
		if ( empty( $coauthors ) ) {
			$coauthors = array( $current_user->user_login );
		}


		// Set the coauthors
		$coauthors = array_unique( array_merge( $existing_coauthors, $coauthors ) );
		$coauthor_objects = array();
		foreach ( $coauthors as &$author_name ) {

			$author = $coauthors_plus->get_coauthor_by( 'user_nicename', $author_name );
			$coauthor_objects[] = $author;
			$term = $coauthors_plus->update_author_term( $author );
			$author_name = $term->slug;
		}
		
		wp_defer_term_counting( true );
		
		wp_set_post_terms( $post_id, $coauthors, $coauthors_plus->coauthor_taxonomy, false );

		// If the original post_author is no longer assigned,
		// update to the first WP_User $coauthor
		$post_author_user = get_user_by( 'id', get_post( $post_id )->post_author );
		if ( empty( $post_author_user )
		     || ! in_array( $post_author_user->user_login, $coauthors ) ) {
			foreach ( $coauthor_objects as $coauthor_object ) {
				if ( 'wpuser' == $coauthor_object->type ) {
					$new_author = $coauthor_object;
					break;
				}
			}
			// Uh oh, no WP_Users assigned to the post
			if ( empty( $new_author ) ) {
				return false;
			}

			$wpdb->update( $wpdb->posts, array( 'post_author' => $new_author->ID ), array( 'ID' => $post_id ) );
			clean_post_cache( $post_id );
		}
		
		wp_defer_term_counting( false );

		return true;

	}

}

new Migration_Coauthors_Rest_Endpoint();
