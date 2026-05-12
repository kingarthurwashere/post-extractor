<?php
/**
 * wp-admin: contributor applications — list, filters, approve/reject/delete (aligned with citizen submissions UX).
 *
 * @package post-extractor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Post_Extractor_Contributor_App_Admin {

	public const PAGE_SLUG = 'post-extractor-contributor-apps';
	public const NONCE     = 'pe_contrib_moderation';

	private static function cancel_undo_window_seconds(): int {
		$opts = get_option( 'post_extractor_settings', [] );
		$opts = is_array( $opts ) ? $opts : [];
		$raw  = isset( $opts['cancel_undo_window_seconds'] ) ? absint( (int) $opts['cancel_undo_window_seconds'] ) : 600;
		return max( 60, min( 86400, $raw ) );
	}

	/**
	 * Same editorial access as viewing citizen submissions list.
	 */
	private static function user_can_moderate(): bool {
		return current_user_can( 'edit_posts' );
	}

	private static function user_can_delete_row( array $row ): bool {
		$st = Post_Extractor_Contributor_Applications::map_status_from_row( $row );
		if ( $st === 'approved' ) {
			return current_user_can( 'manage_options' );
		}
		return current_user_can( 'delete_posts' );
	}

	/**
	 * @return array<string, string>
	 */
	private static function list_query_args_from_get(): array {
		$out = [];
		if ( isset( $_GET['pe_contrib_status'] ) && is_string( $_GET['pe_contrib_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$st = sanitize_key( wp_unslash( $_GET['pe_contrib_status'] ) );
			if ( in_array( $st, [ 'pending', 'approved', 'rejected' ], true ) ) {
				$out['pe_contrib_status'] = $st;
			}
		}
		if ( isset( $_GET['pe_contrib_q'] ) && is_string( $_GET['pe_contrib_q'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$q = sanitize_text_field( wp_unslash( $_GET['pe_contrib_q'] ) );
			if ( $q !== '' ) {
				$out['pe_contrib_q'] = $q;
			}
		}
		if ( isset( $_GET['paged'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$pg = absint( (string) $_GET['paged'] );
			if ( $pg > 1 ) {
				$out['paged'] = (string) $pg;
			}
		}
		return $out;
	}

	public static function url_list( array $extra = [] ): string {
		$base = array_merge( [ 'page' => self::PAGE_SLUG ], $extra );
		return (string) admin_url( add_query_arg( $base, 'admin.php' ) );
	}

	/**
	 * @param array<string, string> $preserve pe_contrib_status, pe_contrib_q from list filters.
	 */
	public static function url_detail( int $id, array $preserve = [] ): string {
		$args = array_merge( [ 'page' => self::PAGE_SLUG, 'app_id' => $id ], $preserve );
		return (string) admin_url( add_query_arg( $args, 'admin.php' ) );
	}

	public static function add_menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . Post_Extractor_Citizen::POST_TYPE,
			__( 'Contributor applications', 'post-extractor' ),
			__( 'Contributor applications', 'post-extractor' ),
			'edit_posts',
			self::PAGE_SLUG,
			[ self::class, 'render_page' ]
		);
	}

	/**
	 * GET row actions (same pattern as citizen submission row links).
	 */
	public static function handle_row_actions(): void {
		if ( ! is_admin() || ! self::user_can_moderate() ) {
			return;
		}
		if ( ! isset( $_GET['page'], $_GET['pe_contrib_row_action'], $_GET['app_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( (string) $_GET['page'] !== self::PAGE_SLUG ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$action = sanitize_key( (string) wp_unslash( (string) $_GET['pe_contrib_row_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$id     = absint( (string) $_GET['app_id'] ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $id < 1 || ! in_array( $action, [ 'approve', 'reject', 'delete' ], true ) ) {
			return;
		}
		check_admin_referer( self::NONCE . '_row_' . $id );

		$preserve = self::list_query_args_from_get();
		$back     = self::url_list( $preserve );

		$row = Post_Extractor_Contributor_Applications::get( $id );
		if ( $row === null ) {
			self::redirect_list_msg( $preserve, 'error', __( 'Application not found.', 'post-extractor' ) );
		}
		$st = Post_Extractor_Contributor_Applications::map_status_from_row( $row );

		if ( $action === 'delete' ) {
			if ( ! self::user_can_delete_row( $row ) ) {
				self::redirect_list_msg( $preserve, 'error', __( 'You are not allowed to delete this application.', 'post-extractor' ) );
			}
			$del = Post_Extractor_Contributor_Applications::delete_by_id( $id );
			if ( is_wp_error( $del ) ) {
				self::redirect_list_msg( $preserve, 'error', $del->get_error_message() );
			}
			self::redirect_list_msg( $preserve, 'success', __( 'Application deleted.', 'post-extractor' ) );
		}

		if ( $st !== 'pending' ) {
			self::redirect_list_msg( $preserve, 'error', __( 'Only pending applications can be approved or rejected from the list.', 'post-extractor' ) );
		}

		if ( $action === 'approve' ) {
			$ok = Post_Extractor_Contributor_Moderation::approve( $id, 'admin_row' );
			if ( is_wp_error( $ok ) ) {
				self::redirect_list_msg( $preserve, 'error', $ok->get_error_message() );
			}
			self::redirect_list_msg( $preserve, 'success', __( 'Application approved.', 'post-extractor' ) );
		}

		$default_reason = __( 'Rejected from the applications list.', 'post-extractor' );
		$ok             = Post_Extractor_Contributor_Moderation::reject( $id, $default_reason, 'admin_row' );
		if ( is_wp_error( $ok ) ) {
			self::redirect_list_msg( $preserve, 'error', $ok->get_error_message() );
		}
		self::redirect_list_msg( $preserve, 'success', __( 'Application rejected.', 'post-extractor' ) );
	}

	/**
	 * @param array<string, string> $preserve
	 */
	private static function redirect_list_msg( array $preserve, string $type, string $message ): void {
		$args = array_merge(
			$preserve,
			[
				'page'               => self::PAGE_SLUG,
				'pe_contrib_msg'     => $message,
				'pe_contrib_msg_typ' => $type,
			]
		);
		wp_safe_redirect( (string) admin_url( add_query_arg( $args, 'admin.php' ) ) );
		exit;
	}

	public static function render_page(): void {
		if ( ! self::user_can_moderate() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'post-extractor' ), '', [ 'response' => 403 ] );
		}
		if ( isset( $_POST['pe_contrib_action'], $_POST['pe_contrib_moderation_nonce'], $_POST['pe_contrib_app_id'] ) && is_string( $_POST['pe_contrib_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			if ( wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['pe_contrib_moderation_nonce'] ) ), self::NONCE ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$id = absint( (string) $_POST['pe_contrib_app_id'] );
				$act = sanitize_text_field( wp_unslash( (string) $_POST['pe_contrib_action'] ) );
				if ( $id > 0 && ( $act === 'approve' || $act === 'reject' ) ) {
					$row = Post_Extractor_Contributor_Applications::get( $id );
					if ( $row !== null && Post_Extractor_Contributor_Moderation::map_status_from_row( $row ) === 'pending' ) {
						if ( 'approve' === $act ) {
							$ok = Post_Extractor_Contributor_Moderation::approve( $id, 'admin' );
						} else {
							$raw = '';
							if ( isset( $_POST['pe_contrib_reject_reason'] ) && is_string( $_POST['pe_contrib_reject_reason'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
								$raw = (string) wp_unslash( $_POST['pe_contrib_reject_reason'] );
							}
							$ok = Post_Extractor_Contributor_Moderation::reject( $id, $raw, 'admin' );
						}
						$preserve = self::list_query_args_from_get();
						if ( is_wp_error( $ok ) ) {
							wp_safe_redirect(
								add_query_arg(
									[
										'pe_contrib_err' => rawurlencode( (string) $ok->get_error_code() . ':' . (string) $ok->get_error_message() ),
									] + array_merge( [ 'page' => self::PAGE_SLUG ], $preserve ),
									admin_url( 'admin.php' )
								)
							);
							exit;
						}
						wp_safe_redirect( self::url_detail( $id, $preserve ) );
						exit;
					}
				}
			}
		}

		$app_id = isset( $_GET['app_id'] ) ? absint( (string) $_GET['app_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $app_id > 0 ) {
			self::render_detail( $app_id );
			return;
		}
		self::render_list();
	}

	private static function render_list(): void {
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( (string) $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$status = 'all';
		if ( isset( $_GET['pe_contrib_status'] ) && is_string( $_GET['pe_contrib_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$s = sanitize_key( wp_unslash( $_GET['pe_contrib_status'] ) );
			if ( in_array( $s, [ 'all', 'pending', 'approved', 'rejected' ], true ) ) {
				$status = $s;
			}
		}
		$q = '';
		if ( isset( $_GET['pe_contrib_q'] ) && is_string( $_GET['pe_contrib_q'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$q = strtolower( trim( sanitize_text_field( wp_unslash( $_GET['pe_contrib_q'] ) ) ) );
		}

		$preserve = self::list_query_args_from_get();
		if ( $status !== 'all' ) {
			$preserve['pe_contrib_status'] = $status;
		}
		if ( $q !== '' ) {
			$preserve['pe_contrib_q'] = $q;
		}
		if ( $paged > 1 ) {
			$preserve['paged'] = (string) $paged;
		}

		$undo  = self::cancel_undo_window_seconds();
		$res   = Post_Extractor_Contributor_Applications::list_filtered( $paged, 25, $status, $q );
		$rows  = $res['rows'];
		$total = (int) $res['total'];
		$pages = (int) max( 1, (int) ceil( $total / 25 ) );

		$citizen_url = admin_url( 'edit.php?post_type=' . Post_Extractor_Citizen::POST_TYPE );

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Contributor applications', 'post-extractor' ) . '</h1>';
		echo ' <a href="' . esc_url( $citizen_url ) . '" class="page-title-action">' . esc_html__( 'Citizen Submissions', 'post-extractor' ) . '</a>';
		echo '<hr class="wp-header-end" />';

		echo '<p class="description">' . esc_html__( 'Same workflow as citizen stories: filter the list, open details for context, or use Accept / Reject on each row. Delete removes the row permanently (earnings lines for this application id are cleared).', 'post-extractor' ) . '</p>';

		echo '<form method="get" class="pe-contrib-filters" style="margin:12px 0;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		echo '<label><span class="screen-reader-text">' . esc_html__( 'Status', 'post-extractor' ) . '</span>';
		echo '<select name="pe_contrib_status">';
		foreach (
			[
				'all'       => __( 'All statuses', 'post-extractor' ),
				'pending'   => __( 'Pending', 'post-extractor' ),
				'approved'  => __( 'Approved', 'post-extractor' ),
				'rejected'  => __( 'Rejected', 'post-extractor' ),
			] as $val => $lab
		) {
			echo '<option value="' . esc_attr( $val ) . '"' . selected( $status, $val, false ) . '>' . esc_html( $lab ) . '</option>';
		}
		echo '</select></label>';
		echo '<label>' . esc_html__( 'Search', 'post-extractor' ) . ' <input type="search" name="pe_contrib_q" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'Name, email, publication…', 'post-extractor' ) . '" /></label>';
		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'post-extractor' ) . '</button>';
		if ( $status !== 'all' || $q !== '' ) {
			echo ' <a class="button" href="' . esc_url( self::url_list() ) . '">' . esc_html__( 'Reset', 'post-extractor' ) . '</a>';
		}
		echo '</form>';

		if ( $rows === [] ) {
			echo '<p>' . esc_html__( 'No applications match this filter.', 'post-extractor' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'ID', 'post-extractor' ) . '</th>';
		echo '<th>' . esc_html__( 'Name', 'post-extractor' ) . '</th>';
		echo '<th>' . esc_html__( 'Email', 'post-extractor' ) . '</th>';
		echo '<th>' . esc_html__( 'Publication', 'post-extractor' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'post-extractor' ) . '</th>';
		echo '<th>' . esc_html__( 'Submitted', 'post-extractor' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'post-extractor' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id   = (int) ( $row['id'] ?? 0 );
			$api  = Post_Extractor_Contributor_Applications::row_to_api_array( $row, $undo );
			$name = (string) ( $api['name'] ?? '' );
			$em   = (string) ( $api['email'] ?? '' );
			$pub  = (string) ( $api['publication'] ?? '' );
			$st   = (string) ( $api['status'] ?? '' );
			$sub  = (string) ( $api['submittedAt'] ?? '' );

			$detail = esc_url( self::url_detail( $id, self::list_query_args_from_get() ) );

			echo '<tr>';
			echo '<td>' . esc_html( (string) $id ) . '</td>';
			echo '<td>' . esc_html( $name ) . '</td>';
			echo '<td>' . esc_html( $em ) . '</td>';
			echo '<td>' . esc_html( $pub ) . '</td>';
			echo '<td>' . esc_html( $st ) . '</td>';
			echo '<td>' . esc_html( $sub ) . '</td>';
			echo '<td style="white-space:normal;">';
			echo '<a class="button button-small" href="' . $detail . '">' . esc_html__( 'Details', 'post-extractor' ) . '</a> ';

			if ( $st === 'pending' ) {
				$approve_url = wp_nonce_url(
					self::url_list( array_merge( $preserve, [
						'pe_contrib_row_action' => 'approve',
						'app_id'                => $id,
					] ) ),
					self::NONCE . '_row_' . $id
				);
				$reject_url = wp_nonce_url(
					self::url_list( array_merge( $preserve, [
						'pe_contrib_row_action' => 'reject',
						'app_id'                => $id,
					] ) ),
					self::NONCE . '_row_' . $id
				);
				echo '<a class="button button-small button-primary" href="' . esc_url( $approve_url ) . '">' . esc_html__( 'Accept', 'post-extractor' ) . '</a> ';
				echo '<a class="button button-small" href="' . esc_url( $reject_url ) . '">' . esc_html__( 'Reject', 'post-extractor' ) . '</a> ';
			}

			if ( self::user_can_delete_row( $row ) ) {
				$del_url = wp_nonce_url(
					self::url_list( array_merge( $preserve, [
						'pe_contrib_row_action' => 'delete',
						'app_id'                => $id,
					] ) ),
					self::NONCE . '_row_' . $id
				);
				$confirm = esc_attr__( 'Delete this application permanently?', 'post-extractor' );
				echo '<a class="button button-small" href="' . esc_url( $del_url ) . '" onclick="return window.confirm(' . json_encode( $confirm ) . ');">' . esc_html__( 'Delete', 'post-extractor' ) . '</a>';
			}

			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			for ( $i = 1; $i <= $pages; $i++ ) {
				$cls = $i === $paged ? ' class="button button-primary" style="margin:2px;"' : ' class="button" style="margin:2px;"';
				$url = esc_url( self::url_list( array_merge( $preserve, [ 'paged' => $i ] ) ) );
				echo '<a' . $cls . ' href="' . $url . '">' . esc_html( (string) $i ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</div></div>';
		}
		echo '</div>';
	}

	private static function render_detail( int $id ): void {
		$row = Post_Extractor_Contributor_Applications::get( $id );
		if ( $row === null ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Application not found.', 'post-extractor' ) . '</p>';
			echo '<p><a href="' . esc_url( self::url_list( self::list_query_args_from_get() ) ) . '">' . esc_html__( 'Back to list', 'post-extractor' ) . '</a></p></div>';
			return;
		}
		$preserve = self::list_query_args_from_get();
		$undo     = self::cancel_undo_window_seconds();
		$api      = Post_Extractor_Contributor_Applications::row_to_api_array( $row, $undo );
		$st       = (string) ( $api['status'] ?? '' );

		echo '<div class="wrap">';
		echo '<p><a href="' . esc_url( self::url_list( $preserve ) ) . '">← ' . esc_html__( 'All applications', 'post-extractor' ) . '</a>';
		echo ' · <a href="' . esc_url( admin_url( 'edit.php?post_type=' . Post_Extractor_Citizen::POST_TYPE ) ) . '">' . esc_html__( 'Citizen Submissions', 'post-extractor' ) . '</a></p>';
		echo '<h1>' . esc_html__( 'Application preview', 'post-extractor' ) . ' #' . esc_html( (string) $id ) . '</h1>';

		echo '<div class="card" style="max-width:920px;padding:16px 20px;">';
		echo '<h2 style="margin-top:0;">' . esc_html__( 'Applicant details', 'post-extractor' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::kv_row( __( 'Status', 'post-extractor' ), $st );
		self::kv_row( __( 'Name', 'post-extractor' ), (string) ( $api['name'] ?? '' ) );
		self::kv_row( __( 'Email', 'post-extractor' ), (string) ( $api['email'] ?? '' ) );
		self::kv_row( __( 'Phone', 'post-extractor' ), (string) ( $api['phone'] ?? '' ) );
		self::kv_row( __( 'Location', 'post-extractor' ), (string) ( $api['location'] ?? '' ) );
		$vid = isset( $api['introductionVideoUrl'] ) && is_string( $api['introductionVideoUrl'] ) ? $api['introductionVideoUrl'] : '';
		self::kv_link_row( __( 'Introduction video', 'post-extractor' ), $vid );
		self::kv_link_row( __( 'Facebook', 'post-extractor' ), (string) ( $api['facebookUrl'] ?? '' ) );
		self::kv_link_row( __( 'Instagram', 'post-extractor' ), (string) ( $api['instagramUrl'] ?? '' ) );
		self::kv_link_row( __( 'LinkedIn', 'post-extractor' ), (string) ( $api['linkedinUrl'] ?? '' ) );
		self::kv_link_row( __( 'X (Twitter)', 'post-extractor' ), (string) ( $api['twitterUrl'] ?? '' ) );
		self::kv_row( __( 'Primary publication', 'post-extractor' ), (string) ( $api['publication'] ?? '' ) );
		$pubs = isset( $api['publications'] ) && is_array( $api['publications'] ) ? implode( ', ', $api['publications'] ) : '';
		self::kv_row( __( 'Publications', 'post-extractor' ), $pubs );
		self::kv_row( __( 'All publications', 'post-extractor' ), ! empty( $api['allPublications'] ) ? __( 'Yes', 'post-extractor' ) : __( 'No', 'post-extractor' ) );
		self::kv_row( __( 'Submitted', 'post-extractor' ), (string) ( $api['submittedAt'] ?? '' ) );
		self::kv_row( __( 'Source', 'post-extractor' ), (string) ( $api['source'] ?? '' ) );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Reason / motivation', 'post-extractor' ) . '</h2>';
		echo '<div style="white-space:pre-wrap;border:1px solid #c3c4c7;background:#fff;padding:12px;border-radius:4px;">' . esc_html( (string) ( $api['reason'] ?? '' ) ) . '</div>';

		if ( $st === 'rejected' && ! empty( $api['rejectionReason'] ) ) {
			echo '<h2>' . esc_html__( 'Rejection message', 'post-extractor' ) . '</h2>';
			echo '<p>' . esc_html( (string) $api['rejectionReason'] ) . '</p>';
		}
		if ( $st === 'approved' && ! empty( $api['wordpressUserId'] ) ) {
			$uid = (int) $api['wordpressUserId'];
			$lnk = get_edit_user_link( $uid );
			echo '<p><strong>' . esc_html__( 'WordPress user', 'post-extractor' ) . ':</strong> ';
			if ( is_string( $lnk ) && $lnk !== '' ) {
				echo '<a href="' . esc_url( $lnk ) . '">ID ' . esc_html( (string) $uid ) . '</a>';
			} else {
				echo esc_html( 'ID ' . (string) $uid );
			}
			echo '</p>';
		}
		echo '</div>';

		if ( $st === 'pending' ) {
			echo '<form method="post" style="margin-top:20px;max-width:920px;">';
			wp_nonce_field( self::NONCE, 'pe_contrib_moderation_nonce' );
			echo '<input type="hidden" name="pe_contrib_app_id" value="' . esc_attr( (string) $id ) . '" />';
			echo '<p><button type="submit" class="button button-primary" name="pe_contrib_action" value="approve">';
			esc_html_e( 'Accept and create contributor user', 'post-extractor' );
			echo '</button></p>';
			echo '<p><label for="pe_contrib_reject_reason"><strong>' . esc_html__( 'Rejection message to applicant (plain text, emailed)', 'post-extractor' ) . '</strong></label></p>';
			echo '<textarea class="widefat" rows="3" name="pe_contrib_reject_reason" id="pe_contrib_reject_reason" placeholder="' . esc_attr__( 'At least 3 characters…', 'post-extractor' ) . '"></textarea>';
			echo '<p><button type="submit" class="button" name="pe_contrib_action" value="reject" onclick="return (function(){ var t=document.getElementById(\'pe_contrib_reject_reason\'); if(!t||!t.value||t.value.trim().length<3){ alert(' . json_encode( __( 'Please enter a rejection message (3+ characters).', 'post-extractor' ) ) . '); return false; } return true; })();">';
			esc_html_e( 'Reject and notify by email', 'post-extractor' );
			echo '</button></p>';
			echo '</form>';
		}

		if ( self::user_can_delete_row( $row ) ) {
			$del_url = wp_nonce_url(
				self::url_list( array_merge( $preserve, [
					'pe_contrib_row_action' => 'delete',
					'app_id'                => $id,
				] ) ),
				self::NONCE . '_row_' . $id
			);
			$confirm = esc_attr__( 'Delete this application permanently?', 'post-extractor' );
			echo '<p style="margin-top:16px;"><a class="button-link-delete" href="' . esc_url( $del_url ) . '" onclick="return window.confirm(' . json_encode( $confirm ) . ');">' . esc_html__( 'Delete application', 'post-extractor' ) . '</a></p>';
		}

		echo '</div>';
	}

	private static function kv_row( string $label, string $value ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}

	private static function kv_link_row( string $label, string $url ): void {
		if ( $url === '' ) {
			self::kv_row( $label, '—' );
			return;
		}
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $url ) . '</a></td></tr>';
	}

	public static function admin_notices(): void {
		if ( ! is_admin() ) {
			return;
		}
		if ( ! isset( $_GET['page'] ) || (string) $_GET['page'] !== self::PAGE_SLUG ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( isset( $_GET['pe_contrib_err'] ) && is_string( $_GET['pe_contrib_err'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$msg = sanitize_text_field( (string) wp_unslash( (string) $_GET['pe_contrib_err'] ) );
			if ( $msg !== '' ) {
				$pos = (int) strpos( $msg, ':', 1 );
				$out = ( $pos > 0 && $pos < strlen( $msg ) - 1 ) ? substr( $msg, $pos + 1 ) : $msg;
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $out ) . '</p></div>';
			}
		}
		if ( isset( $_GET['pe_contrib_msg'] ) && is_string( $_GET['pe_contrib_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$msg = sanitize_text_field( (string) wp_unslash( (string) $_GET['pe_contrib_msg'] ) );
			if ( $msg !== '' ) {
				$type = isset( $_GET['pe_contrib_msg_typ'] ) ? sanitize_key( (string) wp_unslash( (string) $_GET['pe_contrib_msg_typ'] ) ) : 'success'; // phpcs:ignore WordPress.Security.NonceVerification
				$cls  = $type === 'error' ? 'notice notice-error is-dismissible' : 'notice notice-success is-dismissible';
				echo '<div class="' . esc_attr( $cls ) . '"><p>' . esc_html( $msg ) . '</p></div>';
			}
		}
	}
}

add_action( 'admin_menu', [ Post_Extractor_Contributor_App_Admin::class, 'add_menu' ], 99 );
add_action( 'admin_init', [ Post_Extractor_Contributor_App_Admin::class, 'handle_row_actions' ], 1 );
add_action( 'admin_notices', [ Post_Extractor_Contributor_App_Admin::class, 'admin_notices' ] );
