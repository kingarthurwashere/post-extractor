<?php
/**
 * DB tables for production-grade monetization/analytics storage.
 *
 * @package post-extractor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Post_Extractor_DB {
	public const SCHEMA_VERSION = '1';
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

		dbDelta( $sql_entitlement );
		dbDelta( $sql_analytics );
		dbDelta( $sql_earnings );
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
}

