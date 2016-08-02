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
