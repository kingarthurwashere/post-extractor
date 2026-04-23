<?php
/**
 * Contributor program applications (wp-admin only; not public).
 *
 * Users apply from the app; editors approve in WordPress before they may submit stories.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Post_Extractor_Contributor_App {

	public const POST_TYPE = 'pe_contrib_app';

	public const META_FIRST = '_pe_app_first_name';
	public const META_SURNAME = '_pe_app_surname';
	public const META_EMAIL = '_pe_app_email';
	public const META_PHONE = '_pe_app_phone';
	public const META_LOCATION = '_pe_app_location';
	public const META_PUBLICATION = '_pe_app_publication';
	public const META_MODERATION = '_pe_app_moderation';
	public const META_SOURCE = '_pe_app_source';
	/** Full name (or combined first + last from legacy). */
	public const META_FULL_NAME = '_pe_app_name';
	/** Comma/JSON of Publication.name for display (all sites user asked for in this row). */
	public const META_PUBS_JSON   = '_pe_app_publications_json';
	public const META_ALL_SITES = '_pe_app_all_sites';

	public static function register_post_type(): void {
		$labels = [
			'name'          => _x( 'Contributor applications', 'post type general', 'post-extractor' ),
			'singular_name' => _x( 'Contributor application', 'post type singular', 'post-extractor' ),
			'add_new'       => __( 'Add New', 'post-extractor' ),
			'add_new_item'  => __( 'Add application', 'post-extractor' ),
			'edit_item'     => __( 'Edit application', 'post-extractor' ),
		];

		register_post_type(
			self::POST_TYPE,
			[
				'labels'              => $labels,
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_admin_bar'   => false,
				'menu_position'       => 27,
				'menu_icon'           => 'dashicons-id',
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'supports'            => [ 'title', 'editor' ],
				'has_archive'         => false,
				'query_var'           => false,
				'show_in_rest'        => false,
			]
		);
	}
}

add_action( 'init', [ Post_Extractor_Contributor_App::class, 'register_post_type' ], 5 );
