<?php
/**
 * DB tables for production-grade monetization/analytics storage and contributor applications.
 *
 * @package post-extractor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Post_Extractor_DB {
	public const SCHEMA_VERSION = '3';
	public const OPTION_SCHEMA_VERSION = 'post_extractor_db_schema_version';

	public static function maybe_install(): void {
		$installed = (string) get_option( self::OPTION_SCHEMA_VERSION, '' );
		if ( $installed === self::SCHEMA_VERSION ) {
			return;
		}
		self::install();
		update_option( self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION, false );
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$entitlement = self::table_entitlements();
		$analytics   = self::table_analytics_daily();
		$earnings    = self::table_contributor_earnings();
		$contrib     = self::table_contributor_applications();

		$sql_entitlement = "CREATE TABLE {$entitlement} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			device_id VARCHAR(120) NOT NULL,
			active TINYINT(1) NOT NULL DEFAULT 0,
			platform VARCHAR(20) NOT NULL DEFAULT '',
			product_id VARCHAR(191) NOT NULL DEFAULT '',
			purchase_id VARCHAR(191) NOT NULL DEFAULT '',
			purchase_hash CHAR(64) NOT NULL DEFAULT '',
			expires_at DATETIME NULL,
			updated_at DATETIME NOT NULL,
			verified_mode VARCHAR(64) NOT NULL DEFAULT 'server_stub',
			PRIMARY KEY  (id),
			UNIQUE KEY device_id_unique (device_id),
			KEY active_idx (active),
			KEY updated_idx (updated_at)
		) {$charset};";

		$sql_analytics = "CREATE TABLE {$analytics} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_date DATE NOT NULL,
			event_name VARCHAR(64) NOT NULL,
			publication VARCHAR(64) NOT NULL,
			format VARCHAR(32) NOT NULL,
			event_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_daily (event_date, event_name, publication, format),
			KEY event_idx (event_name),
			KEY pub_idx (publication),
			KEY date_idx (event_date)
		) {$charset};";

		$sql_earnings = "CREATE TABLE {$earnings} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			application_id BIGINT UNSIGNED NOT NULL,
			amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
			currency VARCHAR(8) NOT NULL DEFAULT 'USD',
			note TEXT NULL,
			story_id BIGINT UNSIGNED NULL,
			credited_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY app_idx (application_id),
			KEY credited_idx (credited_at)
		) {$charset};";

		$sql_contrib = "CREATE TABLE {$contrib} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			first_name VARCHAR(191) NOT NULL DEFAULT '',
			surname VARCHAR(191) NOT NULL DEFAULT '',
			full_name VARCHAR(200) NOT NULL DEFAULT '',
			email VARCHAR(191) NOT NULL DEFAULT '',
			phone VARCHAR(50) NOT NULL DEFAULT '',
			location VARCHAR(200) NOT NULL DEFAULT '',
			facebook_url VARCHAR(500) NOT NULL DEFAULT '',
			instagram_url VARCHAR(500) NOT NULL DEFAULT '',
			linkedin_url VARCHAR(500) NOT NULL DEFAULT '',
			twitter_url VARCHAR(500) NOT NULL DEFAULT '',
			publication VARCHAR(64) NOT NULL DEFAULT 'myAfrika',
			pubs_json TEXT NOT NULL,
			all_sites TINYINT(1) NOT NULL DEFAULT 0,
			reason TEXT NOT NULL,
			intro_video_attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			moderation VARCHAR(32) NOT NULL DEFAULT 'pending',
			source VARCHAR(64) NOT NULL DEFAULT 'newsbepa',
			rejection_reason TEXT NOT NULL DEFAULT '',
			wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			cancelled_by_applicant TINYINT(1) NOT NULL DEFAULT 0,
			cancelled_at VARCHAR(64) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY moderation_idx (moderation),
			KEY email_idx (email(100)),
			KEY publication_idx (publication),
			KEY created_idx (created_at)
		) {$charset};";

		dbDelta( $sql_entitlement );
		dbDelta( $sql_analytics );
		dbDelta( $sql_earnings );
		dbDelta( $sql_contrib );

		self::maybe_migrate_contributor_applications_from_legacy_posts();
	}

	/**
	 * One-time move from pe_contrib_app posts (pre–schema v2) into wp_pe_contributor_applications.
	 */
	private static function maybe_migrate_contributor_applications_from_legacy_posts(): void {
		if ( (string) get_option( 'post_extractor_contrib_cpt_migrated_v2', '' ) === '1' ) {
			return;
		}
		if ( ! class_exists( 'Post_Extractor_Contributor_App' ) || ! class_exists( 'Post_Extractor_Contributor_Moderation' ) ) {
			return;
		}

		global $wpdb;
		$pt  = Post_Extractor_Contributor_App::POST_TYPE;
		$tbl = self::table_contributor_applications();

		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", $pt ) );
		if ( ! is_array( $ids ) ) {
			$ids = [];
		}

		foreach ( $ids as $pid_raw ) {
			$pid = (int) $pid_raw;
			if ( $pid < 1 ) {
				continue;
			}
			$post = get_post( $pid );
			if ( ! ( $post instanceof WP_Post ) || $post->post_type !== $pt ) {
				continue;
			}

			$mod = (string) get_post_meta( $pid, Post_Extractor_Contributor_App::META_MODERATION, true );
			if ( $mod === '' ) {
				if ( in_array( $post->post_status, [ 'publish', 'private', 'future' ], true ) ) {
					$mod = 'approved';
				} elseif ( in_array( $post->post_status, [ 'draft', 'trash' ], true ) ) {
					$mod = 'rejected';
				} else {
					$mod = 'pending';
				}
			}

			$created = get_post_time( 'Y-m-d H:i:s', true, $post );
			if ( ! is_string( $created ) || $created === '' ) {
				$created = gmdate( 'Y-m-d H:i:s' );
			}
			$modified = get_post_modified_time( 'Y-m-d H:i:s', true, $post );
			if ( ! is_string( $modified ) || $modified === '' ) {
				$modified = $created;
			}

			$row = [
				'id'                     => $pid,
				'first_name'             => (string) get_post_meta( $pid, Post_Extractor_Contributor_App::META_FIRST, true ),
				'surname'                => (string) get_post_meta( $pid, Post_Extractor_Contributor_App::META_SURNAME, true ),
				'full_name'              => (string) get_post_meta( $pid, Post_Extractor_Contributor_App::META_FULL_NAME, true ),
				'email'                  => (string) get_post_meta( $pid, Post_Extractor_Contributor_App::META_EMAIL, true ),
				'phone'                  => (string) get_post_meta( $pid, Post_Extractor_Contributor_App::META_PHONE, true ),
				'location'               => (string) get_post_meta( $pid, Post_Extractor_Contributor_App::META_LOCATION, true ),
				'publication'            => (string) get_post_meta( $pid, Post_Extractor_Contributor_App::META_PUBLICATION, true ),
				'pubs_json'              => (string) get_post_meta( $pid, Post_Extractor_Contributor_App::META_PUBS_JSON, true ),
				'all_sites'              => (int) get_post_meta( $pid, Post_Extractor_Contributor_App::META_ALL_SITES, true ) === 1 ? 1 : 0,
				'reason'                 => (string) wp_strip_all_tags( (string) $post->post_content, true ),
				'moderation'             => $mod,
				'source'                 => (string) get_post_meta( $pid, Post_Extractor_Contributor_App::META_SOURCE, true ),
				'rejection_reason'       => (string) get_post_meta( $pid, Post_Extractor_Contributor_Moderation::META_REJECTION_REASON, true ),
				'wp_user_id'             => (int) get_post_meta( $pid, Post_Extractor_Contributor_Moderation::META_LINKED_USER_ID, true ),
				'cancelled_by_applicant' => (int) get_post_meta( $pid, '_pe_cancelled_by_applicant', true ) === 1 ? 1 : 0,
				'cancelled_at'           => (string) get_post_meta( $pid, '_pe_cancelled_at', true ),
				'created_at'             => $created,
				'updated_at'             => $modified,
			];
			if ( $row['publication'] === '' ) {
				$row['publication'] = 'myAfrika';
			}
			if ( $row['pubs_json'] === '' ) {
				$row['pubs_json'] = '[]';
			}
			if ( $row['source'] === '' ) {
				$row['source'] = 'newsbepa';
			}

			$ok = $wpdb->insert(
				$tbl,
				$row,
				[
					'%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s',
				]
			);
			if ( false !== $ok ) {
				wp_delete_post( $pid, true );
			}
		}

		$max = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$tbl}" );
		if ( $max > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from internal helper only.
			$wpdb->query( 'ALTER TABLE `' . esc_sql( $tbl ) . '` AUTO_INCREMENT = ' . (string) ( (int) $max + 1 ) );
		}

		update_option( 'post_extractor_contrib_cpt_migrated_v2', '1', false );
	}

	public static function table_entitlements(): string {
		global $wpdb;
		return $wpdb->prefix . 'pe_premium_entitlements';
	}

	public static function table_analytics_daily(): string {
		global $wpdb;
		return $wpdb->prefix . 'pe_analytics_daily';
	}

	public static function table_contributor_earnings(): string {
		global $wpdb;
		return $wpdb->prefix . 'pe_contributor_earnings';
	}

	public static function table_contributor_applications(): string {
		global $wpdb;
		return $wpdb->prefix . 'pe_contributor_applications';
	}
}
