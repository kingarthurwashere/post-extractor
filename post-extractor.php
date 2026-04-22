<?php
/**
 * Plugin Name: Post Extractor API
 * Plugin URI:  https://arthurnyasango.vercel.app/
 * Description: Extracts all post types with sections, custom fields, featured images, taxonomies, and Gutenberg blocks via REST API.
 * Version:     1.1.1
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

define( 'POST_EXTRACTOR_VERSION', '1.1.1' );
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
}
add_action( 'rest_api_init', 'post_extractor_init' );

// Bust /site-identity cache when Site Icon, URL, or Customizer (e.g. logo) changes.
add_action(
	'update_option',
	static function ( string $option, $old, $new ): void {
		unset( $old, $new );
		if ( in_array( $option, [ 'site_icon', 'siteurl' ], true ) ) {
			delete_transient( 'post_pe_site_id_v1' );
		}
	},
	10,
	3
);
add_action(
	'customize_save_after',
	static function (): void {
		delete_transient( 'post_pe_site_id_v1' );
	}
);

// Register settings on admin_init so options.php whitelists post_extractor_settings.
// (Do not tie this to rest_api_init — if that hook does not run, saving the settings form fails.)
add_action(
    'admin_init',
    static function (): void {
        $settings = new Post_Extractor_Settings();
        $settings->register_settings();
    }
);

// Also boot settings (admin menu) on admin_menu hook.
add_action( 'admin_menu', function () {
    $settings = new Post_Extractor_Settings();
    $settings->add_menu();
} );
