<?php
/**
 * Citizen / contributor submissions (hidden CPT for wp-admin only).
 *
 * The Post Extractor app POSTs to /post-extractor/v1/submissions; editors
 * approve or reject in WordPress — not via Firebase.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Post_Extractor_Citizen {

	/** @var string */
	public const POST_TYPE = 'pe_citizen';

	/** @var string Meta key: pending|approved|rejected (optional; falls back to post_status). */
	public const META_MODERATION = '_pe_moderation';

	/** @var string */
	public const META_SOURCE = '_pe_submission_source';

	/** @var int Approved contributor application pe_contrib_app post id (when set). */
	public const META_CONTRIBUTOR_APP_ID = '_pe_contributor_app_id';

	public static function register_post_type(): void {
		$labels = [
			'name'          => _x( 'Citizen Submissions', 'post type general name', 'post-extractor' ),
			'singular_name' => _x( 'Citizen Submission', 'post type singular', 'post-extractor' ),
			'add_new'       => __( 'Add New', 'post-extractor' ),
			'add_new_item'  => __( 'Add New Submission', 'post-extractor' ),
			'edit_item'     => __( 'Edit Submission', 'post-extractor' ),
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
				'show_in_nav_menus'   => false,
				'menu_position'       => 26,
				'menu_icon'           => 'dashicons-testimonial',
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'hierarchical'        => false,
				'has_archive'         => false,
				'query_var'           => false,
				'show_in_rest'        => false,
				'supports'            => [ 'title', 'editor' ],
			]
		);
	}
}

add_action( 'init', [ Post_Extractor_Citizen::class, 'register_post_type' ], 5 );
