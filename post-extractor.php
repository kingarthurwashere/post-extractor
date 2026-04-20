<?php
/**
 * Plugin Name: Post Extractor API
 * Plugin URI:  https://arthurnyasango.vercel.app/
 * Description: Extracts all post types with sections, custom fields, featured images, taxonomies, and Gutenberg blocks via REST API.
 * Version:     1.0.0
 * Author:      Kingarthurwashere
 * License:     GPL-2.0+
 * Text Domain: post-extractor
 * Requires at least: 5.5
 * Requires PHP:      8.0
 * Tested up to:      6.7
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'POST_EXTRACTOR_VERSION', '1.0.0' );
define( 'POST_EXTRACTOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'POST_EXTRACTOR_URL', plugin_dir_url( __FILE__ ) );

require_once POST_EXTRACTOR_DIR . 'includes/class-post-extractor-api.php';
require_once POST_EXTRACTOR_DIR . 'includes/class-post-extractor-blocks.php';
require_once POST_EXTRACTOR_DIR . 'includes/class-post-extractor-meta.php';
require_once POST_EXTRACTOR_DIR . 'includes/class-post-extractor-settings.php';

/**
 * Bootstrap the plugin.
 */
function post_extractor_init(): void {
    $api = new Post_Extractor_API();
    $api->register_routes();

    $settings = new Post_Extractor_Settings();
    $settings->init();
}
add_action( 'rest_api_init', 'post_extractor_init' );

// Also boot settings (admin menu) on admin_menu hook.
add_action( 'admin_menu', function () {
    $settings = new Post_Extractor_Settings();
    $settings->add_menu();
} );
