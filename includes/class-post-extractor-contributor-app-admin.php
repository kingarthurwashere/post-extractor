<?php
/**
 * wp-admin: contributor applications preview (custom table — not the post editor).
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

	public static function add_menu(): void {
		// Must use options-general.php: add_options_page() registers under Settings with that
		// parent only — post-extractor-settings is a child slug, not a valid add_submenu_page parent.
		add_submenu_page(
			'options-general.php',
			__( 'Contributor applications', 'post-extractor' ),
			__( 'Contributor applications', 'post-extractor' ),
			'manage_options',
			self::PAGE_SLUG,
			[ self::class, 'render_page' ]
		);
	}

	public static function url_list(): string {
		return (string) admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	public static function url_detail( int $id ): string {
		return (string) admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&app_id=' . (int) $id );
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
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
						if ( is_wp_error( $ok ) ) {
							wp_safe_redirect(
								add_query_arg(
									'pe_contrib_err',
									rawurlencode( (string) $ok->get_error_code() . ':' . (string) $ok->get_error_message() ),
									self::url_list()
								)
							);
							exit;
						}
						wp_safe_redirect( self::url_detail( $id ) );
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
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( (string) $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$undo  = self::cancel_undo_window_seconds();
		$res  = Post_Extractor_Contributor_Applications::list_filtered( $paged, 25, 'all', '' );
		$rows = $res['rows'];
		$total = (int) $res['total'];
		$pages = (int) max( 1, (int) ceil( $total / 25 ) );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Contributor applications', 'post-extractor' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Applications from the mobile app are stored here (not as WordPress posts). Citizen story submissions remain under their own post type.', 'post-extractor' ) . '</p>';

		if ( $rows === [] ) {
			echo '<p>' . esc_html__( 'No applications yet.', 'post-extractor' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'ID', 'post-extractor' ) . '</th>';
		echo '<th>' . esc_html__( 'Name', 'post-extractor' ) . '</th>';
		echo '<th>' . esc_html__( 'Email', 'post-extractor' ) . '</th>';
		echo '<th>' . esc_html__( 'Publication', 'post-extractor' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'post-extractor' ) . '</th>';
		echo '<th>' . esc_html__( 'Submitted', 'post-extractor' ) . '</th>';
		echo '<th>' . esc_html__( 'Preview', 'post-extractor' ) . '</th>';
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
			$u    = esc_url( self::url_detail( $id ) );
			echo '<tr>';
			echo '<td>' . esc_html( (string) $id ) . '</td>';
			echo '<td>' . esc_html( $name ) . '</td>';
			echo '<td>' . esc_html( $em ) . '</td>';
			echo '<td>' . esc_html( $pub ) . '</td>';
			echo '<td>' . esc_html( $st ) . '</td>';
			echo '<td>' . esc_html( $sub ) . '</td>';
			echo '<td><a class="button button-small" href="' . $u . '">' . esc_html__( 'Details', 'post-extractor' ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			for ( $i = 1; $i <= $pages; $i++ ) {
				$cls = $i === $paged ? ' class="button button-primary" style="margin:2px;"' : ' class="button" style="margin:2px;"';
				$url = esc_url( add_query_arg( 'paged', (string) $i, self::url_list() ) );
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
			echo '<p><a href="' . esc_url( self::url_list() ) . '">' . esc_html__( 'Back to list', 'post-extractor' ) . '</a></p></div>';
			return;
		}
		$undo = self::cancel_undo_window_seconds();
		$api = Post_Extractor_Contributor_Applications::row_to_api_array( $row, $undo );
		$st  = (string) ( $api['status'] ?? '' );

		echo '<div class="wrap">';
		echo '<p><a href="' . esc_url( self::url_list() ) . '">← ' . esc_html__( 'All applications', 'post-extractor' ) . '</a></p>';
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
			esc_html_e( 'Approve and create contributor user', 'post-extractor' );
			echo '</button></p>';
			echo '<p><label for="pe_contrib_reject_reason"><strong>' . esc_html__( 'Rejection message to applicant (plain text, emailed)', 'post-extractor' ) . '</strong></label></p>';
			echo '<textarea class="widefat" rows="3" name="pe_contrib_reject_reason" id="pe_contrib_reject_reason" placeholder="' . esc_attr__( 'At least 3 characters…', 'post-extractor' ) . '"></textarea>';
			echo '<p><button type="submit" class="button" name="pe_contrib_action" value="reject" onclick="return (function(){ var t=document.getElementById(\'pe_contrib_reject_reason\'); if(!t||!t.value||t.value.trim().length<3){ alert(' . json_encode( __( 'Please enter a rejection message (3+ characters).', 'post-extractor' ) ) . '); return false; } return true; })();">';
			esc_html_e( 'Reject and notify by email', 'post-extractor' );
			echo '</button></p>';
			echo '</form>';
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

	public static function admin_notice_error(): void {
		if ( ! is_admin() || ! isset( $_GET['pe_contrib_err'] ) || ! is_string( $_GET['pe_contrib_err'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$msg = sanitize_text_field( (string) wp_unslash( (string) $_GET['pe_contrib_err'] ) );
		if ( $msg === '' ) {
			return;
		}
		$pos = (int) strpos( $msg, ':', 1 );
		$out = ( $pos > 0 && $pos < strlen( $msg ) - 1 ) ? substr( $msg, $pos + 1 ) : $msg;
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $out ) . '</p></div>';
	}
}

// admin_menu runs before admin_init — never register this submenu from admin_init or it never appears.
add_action( 'admin_menu', [ Post_Extractor_Contributor_App_Admin::class, 'add_menu' ], 25 );
add_action( 'admin_notices', [ Post_Extractor_Contributor_App_Admin::class, 'admin_notice_error' ] );
