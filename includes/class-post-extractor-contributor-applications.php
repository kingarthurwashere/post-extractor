<?php
/**
 * Contributor program applications stored in a custom table (not posts).
 *
 * @package post-extractor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Post_Extractor_Contributor_Applications {

	/**
	 * @return 'pending'|'approved'|'rejected'
	 */
	public static function map_status_from_row( array $row ): string {
		$raw = strtolower( (string) ( $row['moderation'] ?? 'pending' ) );
		if ( in_array( $raw, [ 'rejected', 'denied' ], true ) ) {
			return 'rejected';
		}
		if ( $raw === 'approved' ) {
			return 'approved';
		}
		return 'pending';
	}

	/**
	 * REST / app payload shape (matches former CPT-backed responses).
	 *
	 * @param array<string, mixed> $row DB row (assoc).
	 */
	public static function row_to_api_array( array $row, int $undo_window_seconds ): array {
		$id     = (int) ( $row['id'] ?? 0 );
		$first  = (string) ( $row['first_name'] ?? '' );
		$last   = (string) ( $row['surname'] ?? '' );
		$name   = (string) ( $row['full_name'] ?? '' );
		if ( $name === '' && ( $first !== '' || $last !== '' ) ) {
			$name = trim( $first . ' ' . $last );
		}
		$email = (string) ( $row['email'] ?? '' );
		$phone = (string) ( $row['phone'] ?? '' );
		$loc   = (string) ( $row['location'] ?? '' );
		$pub   = (string) ( $row['publication'] ?? '' );
		if ( $pub === '' ) {
			$pub = 'myAfrika';
		}
		$pjson = (string) ( $row['pubs_json'] ?? '' );
		$pubs  = [];
		if ( $pjson !== '' ) {
			$dec = json_decode( $pjson, true );
			if ( is_array( $dec ) ) {
				$pubs = array_map( 'strval', $dec );
			}
		}
		$all_s = (int) ( $row['all_sites'] ?? 0 ) === 1;
		if ( $pubs === [] && $pjson === '' && $name !== '' ) {
			$pubs = [ $pub ];
		}
		$reason  = (string) ( $row['reason'] ?? '' );
		$fb      = (string) ( $row['facebook_url'] ?? '' );
		$ig      = (string) ( $row['instagram_url'] ?? '' );
		$li      = (string) ( $row['linkedin_url'] ?? '' );
		$tw      = (string) ( $row['twitter_url'] ?? '' );
		$intro_aid = (int) ( $row['intro_video_attachment_id'] ?? 0 );
		$intro_url = null;
		if ( $intro_aid > 0 ) {
			$att_url = wp_get_attachment_url( $intro_aid );
			$intro_url = ( is_string( $att_url ) && $att_url !== '' ) ? $att_url : null;
		}
		$created = (string) ( $row['created_at'] ?? '' );
		if ( $created !== '' ) {
			$ts = strtotime( $created );
			$created = ( is_int( $ts ) && $ts > 0 ) ? gmdate( 'c', $ts ) : gmdate( 'c' );
		} else {
			$created = gmdate( 'c' );
		}
		$status = self::map_status_from_row( $row );
		$src    = (string) ( $row['source'] ?? '' );
		$rej    = (string) ( $row['rejection_reason'] ?? '' );
		$wpuid  = (int) ( $row['wp_user_id'] ?? 0 );
		$cancelled_by_applicant = (int) ( $row['cancelled_by_applicant'] ?? 0 ) === 1;
		$cancelled_at = (string) ( $row['cancelled_at'] ?? '' );
		$undo_until_iso = null;
		$can_undo_cancel = false;
		if ( $cancelled_by_applicant && $status === 'rejected' ) {
			$ts = strtotime( $cancelled_at );
			if ( is_int( $ts ) && $ts > 0 ) {
				$undo_until = $ts + $undo_window_seconds;
				$undo_until_iso = gmdate( 'c', $undo_until );
				$can_undo_cancel = time() <= $undo_until;
			}
		}
		return [
			'id'                   => $id,
			'name'                 => $name,
			'firstName'            => $first,
			'surname'              => $last,
			'email'                => $email,
			'phone'                => $phone,
			'location'             => $loc,
			'facebookUrl'          => $fb !== '' ? $fb : null,
			'instagramUrl'         => $ig !== '' ? $ig : null,
			'linkedinUrl'          => $li !== '' ? $li : null,
			'twitterUrl'           => $tw !== '' ? $tw : null,
			'reason'               => $reason,
			'introductionVideoUrl' => $intro_url,
			'publication'          => $pub,
			'publications'         => $pubs,
			'allPublications'      => $all_s,
			'status'               => $status,
			'submittedAt'          => $created,
			'source'               => $src !== '' ? $src : 'newsbepa',
			'rejectionReason'      => ( $status === 'rejected' && $rej !== '' ) ? $rej : null,
			'wordpressUserId'      => ( $status === 'approved' && $wpuid > 0 ) ? $wpuid : null,
			'cancelledByApplicant' => $cancelled_by_applicant,
			'cancelUndoUntil'      => $undo_until_iso,
			'canUndoCancel'        => $can_undo_cancel,
		];
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$t = Post_Extractor_DB::table_contributor_applications();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array{
	 *   first_name?: string,
	 *   surname?: string,
	 *   full_name?: string,
	 *   email?: string,
	 *   phone?: string,
	 *   location?: string,
	 *   facebook_url?: string,
	 *   instagram_url?: string,
	 *   linkedin_url?: string,
	 *   twitter_url?: string,
	 *   publication?: string,
	 *   pubs_json?: string,
	 *   all_sites?: int,
	 *   reason?: string,
	 *   intro_video_attachment_id?: int,
	 *   moderation?: string,
	 *   source?: string,
	 * } $data
	 * @return int|WP_Error New row id.
	 */
	public static function insert( array $data ): int|WP_Error {
		global $wpdb;
		$t = Post_Extractor_DB::table_contributor_applications();
		$now = gmdate( 'Y-m-d H:i:s' );
		$row = [
			'first_name'                  => (string) ( $data['first_name'] ?? '' ),
			'surname'                     => (string) ( $data['surname'] ?? '' ),
			'full_name'                   => (string) ( $data['full_name'] ?? '' ),
			'email'                       => (string) ( $data['email'] ?? '' ),
			'phone'                       => (string) ( $data['phone'] ?? '' ),
			'location'                    => (string) ( $data['location'] ?? '' ),
			'facebook_url'                => (string) ( $data['facebook_url'] ?? '' ),
			'instagram_url'               => (string) ( $data['instagram_url'] ?? '' ),
			'linkedin_url'                => (string) ( $data['linkedin_url'] ?? '' ),
			'twitter_url'                 => (string) ( $data['twitter_url'] ?? '' ),
			'publication'                 => (string) ( $data['publication'] ?? 'myAfrika' ),
			'pubs_json'                   => (string) ( $data['pubs_json'] ?? '[]' ),
			'all_sites'                   => (int) ( $data['all_sites'] ?? 0 ) ? 1 : 0,
			'reason'                      => (string) ( $data['reason'] ?? '' ),
			'intro_video_attachment_id'   => (int) ( $data['intro_video_attachment_id'] ?? 0 ),
			'moderation'                  => (string) ( $data['moderation'] ?? 'pending' ),
			'source'                      => (string) ( $data['source'] ?? 'newsbepa' ),
			'cancelled_by_applicant'      => 0,
			'created_at'                  => $now,
			'updated_at'                  => $now,
		];
		$ok = $wpdb->insert(
			$t,
			$row,
			[
				'%s', '%s', '%s', '%s', '%s', '%s',
				'%s', '%s', '%s', '%s',
				'%s', '%s', '%d',
				'%s', '%d',
				'%s', '%s', '%d', '%s', '%s',
			]
		);
		if ( ! $ok ) {
			return new WP_Error( 'pe_db_insert', __( 'Could not save application.', 'post-extractor' ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * @param array<string, mixed> $patch Column => value (only known columns applied).
	 */
	public static function update( int $id, array $patch ): bool {
		global $wpdb;
		$t = Post_Extractor_DB::table_contributor_applications();
		$allowed = [
			'first_name' => '%s',
			'surname' => '%s',
			'full_name' => '%s',
			'email' => '%s',
			'phone' => '%s',
			'location' => '%s',
			'facebook_url' => '%s',
			'instagram_url' => '%s',
			'linkedin_url' => '%s',
			'twitter_url' => '%s',
			'publication' => '%s',
			'pubs_json' => '%s',
			'all_sites' => '%d',
			'reason' => '%s',
			'intro_video_attachment_id' => '%d',
			'moderation' => '%s',
			'source' => '%s',
			'rejection_reason' => '%s',
			'wp_user_id' => '%d',
			'cancelled_by_applicant' => '%d',
			'cancelled_at' => '%s',
			'updated_at' => '%s',
		];
		$data = [];
		$formats = [];
		foreach ( $allowed as $col => $fmt ) {
			if ( array_key_exists( $col, $patch ) ) {
				$data[ $col ] = $patch[ $col ];
				$formats[] = $fmt;
			}
		}
		if ( $data === [] ) {
			return true;
		}
		if ( ! isset( $data['updated_at'] ) ) {
			$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );
			$formats[] = '%s';
		}
		return false !== $wpdb->update( $t, $data, [ 'id' => $id ], $formats, [ '%d' ] );
	}

	public static function count_pending(): int {
		global $wpdb;
		$t = Post_Extractor_DB::table_contributor_applications();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE moderation = 'pending'" );
	}

	public static function count_all(): int {
		global $wpdb;
		$t = Post_Extractor_DB::table_contributor_applications();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
	}

	/**
	 * Paginated list for wp-admin and editorial-style filtering.
	 *
	 * @return array{ rows: array<int, array<string, mixed>>, total: int }
	 */
	public static function list_filtered( int $page, int $per_page, string $status, string $q ): array {
		global $wpdb;
		$t = Post_Extractor_DB::table_contributor_applications();
		if ( $page < 1 ) {
			$page = 1;
		}
		if ( $per_page < 1 ) {
			$per_page = 30;
		}
		if ( $per_page > 100 ) {
			$per_page = 100;
		}
		$offset = ( $page - 1 ) * $per_page;

		$clauses = [ '1=1' ];
		$params  = [];

		if ( $status === 'pending' ) {
			$clauses[] = 'moderation = %s';
			$params[] = 'pending';
		} elseif ( $status === 'approved' ) {
			$clauses[] = 'moderation = %s';
			$params[] = 'approved';
		} elseif ( $status === 'rejected' ) {
			$clauses[] = 'moderation IN (%s,%s)';
			$params[] = 'rejected';
			$params[] = 'denied';
		}

		if ( $q !== '' ) {
			$like = '%' . $wpdb->esc_like( strtolower( $q ) ) . '%';
			$clauses[] = '(LOWER(full_name) LIKE %s OR LOWER(email) LIKE %s OR LOWER(publication) LIKE %s OR LOWER(pubs_json) LIKE %s OR LOWER(reason) LIKE %s OR LOWER(facebook_url) LIKE %s OR LOWER(instagram_url) LIKE %s OR LOWER(linkedin_url) LIKE %s OR LOWER(twitter_url) LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $clauses );
		$count_sql = "SELECT COUNT(*) FROM {$t} {$where_sql}";
		$total     = $params !== []
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
			: (int) $wpdb->get_var( $count_sql );

		$list_params   = $params;
		$list_params[] = $per_page;
		$list_params[] = $offset;
		$list_sql      = "SELECT * FROM {$t} {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$prepared      = $wpdb->prepare( $list_sql, $list_params );
		$rows          = $wpdb->get_results( $prepared, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			$rows = [];
		}
		return [ 'rows' => $rows, 'total' => $total ];
	}
}
