<?php
/**
 * wp-admin: approve or reject contributor applications, show linked WordPress user.
 *
 * @package post-extractor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Post_Extractor_Contributor_App_Admin {

	public const NONCE = 'pe_contrib_moderation';

	/** @var bool */
	private static $hooks_registered = false;

	public static function init(): void {
		if ( self::$hooks_registered ) {
			return;
		}
		self::$hooks_registered = true;
		add_action( 'add_meta_boxes', [ self::class, 'add_box' ] );
		add_action( 'save_post_' . Post_Extractor_Contributor_App::POST_TYPE, [ self::class, 'save' ], 10, 2 );
	}

	public static function add_box(): void {
		add_meta_box(
			'pe_contrib_moderation',
			__( 'Application decision', 'post-extractor' ),
			[ self::class, 'render_box' ],
			Post_Extractor_Contributor_App::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * @param WP_Post $post
	 */
	public static function render_box( $post ): void {
		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}
		if ( $post->post_type !== Post_Extractor_Contributor_App::POST_TYPE ) {
			return;
		}
		wp_nonce_field( self::NONCE, 'pe_contrib_moderation_nonce' );
		$st  = Post_Extractor_Contributor_Moderation::map_status( $post );
		$rej = (string) get_post_meta( $post->ID, Post_Extractor_Contributor_Moderation::META_REJECTION_REASON, true );
		$uid = (int) get_post_meta( $post->ID, Post_Extractor_Contributor_Moderation::META_LINKED_USER_ID, true );

		echo '<p><strong>' . esc_html__( 'Status:', 'post-extractor' ) . '</strong> ';
		echo esc_html( $st );
		echo '</p>';

		if ( $st === 'rejected' && $rej !== '' ) {
			echo '<p class="description">' . esc_html( $rej ) . '</p>';
		}
		if ( $st === 'approved' && $uid > 0 ) {
			$u = get_userdata( $uid );
			if ( $u ) {
				$url = (string) get_edit_user_link( $uid );
				/* translators: 1: username, 2: user profile URL */
				echo '<p><strong>' . esc_html__( 'WordPress user:', 'post-extractor' ) . '</strong> ';
				if ( $url !== '' ) {
					echo '<a href="' . esc_url( $url ) . '">' . esc_html( (string) $u->user_login ) . '</a> (ID ' . (int) $uid . ')</p>';
				} else {
					echo esc_html( (string) $u->user_login ) . ' (ID ' . (int) $uid . ')</p>';
				}
			} else {
				printf(
					'<p><strong>%1$s</strong> %2$d</p>',
					esc_html__( 'Linked user id (user missing):', 'post-extractor' ),
					$uid
				);
			}
		}

		if ( $st !== 'pending' ) {
			echo '<p class="description">' . esc_html__( 'Decisions are final. Use the REST API to change state if you use advanced tools.', 'post-extractor' ) . '</p>';
		} else {
			?>
		<div style="margin:12px 0; border-top:1px solid #dcdcde; padding-top:10px;">
			<p>
				<button type="submit" class="button button-primary" name="pe_contrib_action" value="approve">
					<?php esc_html_e( 'Approve and create contributor user', 'post-extractor' ); ?>
				</button>
			</p>
			<p>
				<label for="pe_contrib_reject_reason"><strong><?php esc_html_e( 'Rejection message to applicant (plain text, emailed)', 'post-extractor' ); ?></strong></label>
			</p>
			<textarea class="widefat" rows="3" name="pe_contrib_reject_reason" id="pe_contrib_reject_reason" placeholder="<?php esc_attr_e( 'At least 3 characters…', 'post-extractor' ); ?>"></textarea>
			<p>
				<button type="submit" class="button" name="pe_contrib_action" value="reject" onclick="return (function(){ var t=document.getElementById('pe_contrib_reject_reason'); if(!t||!t.value||t.value.trim().length<3){ alert(<?php echo json_encode( __( 'Please enter a rejection message (3+ characters).', 'post-extractor' ) ); ?>); return false; } return true; })();">
					<?php esc_html_e( 'Reject and notify by email', 'post-extractor' ); ?>
				</button>
			</p>
		</div>
			<?php
		}
	}

	/**
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	public static function save( $post_id, $post, $update = true ): void {
		unset( $update );
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( (int) $post_id > 0 && false !== wp_is_post_revision( (int) $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['pe_contrib_moderation_nonce'] ) || ! is_string( $_POST['pe_contrib_moderation_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pe_contrib_moderation_nonce'] ) ), self::NONCE ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! current_user_can( 'edit_post', (int) $post_id ) ) {
			return;
		}
		$act = '';
		if ( isset( $_POST['pe_contrib_action'] ) && is_string( $_POST['pe_contrib_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$act = sanitize_text_field( wp_unslash( $_POST['pe_contrib_action'] ) );
		}
		if ( $act !== 'approve' && $act !== 'reject' ) {
			return;
		}
		$p = $post;
		if ( ! ( $p instanceof WP_Post ) || (int) $p->ID !== (int) $post_id || $p->post_type !== Post_Extractor_Contributor_App::POST_TYPE ) {
			$p2 = get_post( (int) $post_id );
			if ( ! ( $p2 instanceof WP_Post ) || $p2->post_type !== Post_Extractor_Contributor_App::POST_TYPE ) {
				return;
			}
			$p = $p2;
		}
		$st = Post_Extractor_Contributor_Moderation::map_status( $p );
		if ( $st !== 'pending' ) {
			return;
		}
		if ( 'approve' === $act ) {
			$ok = Post_Extractor_Contributor_Moderation::approve( (int) $post_id, 'admin' );
		} else {
			$raw = '';
			if ( isset( $_POST['pe_contrib_reject_reason'] ) && is_string( $_POST['pe_contrib_reject_reason'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$raw = (string) wp_unslash( $_POST['pe_contrib_reject_reason'] );
			}
			$ok = Post_Extractor_Contributor_Moderation::reject( (int) $post_id, $raw, 'admin' );
		}
		if ( is_wp_error( $ok ) ) {
			add_filter(
				'redirect_post_location',
				static function ( $url ) use ( $ok ) {
					$base = is_string( $url ) ? $url : '';
					return add_query_arg(
						'pe_contrib_err',
						rawurlencode( (string) $ok->get_error_code() . ':' . (string) $ok->get_error_message() ),
						$base
					);
				}
			);
			return;
		}
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

add_action( 'admin_init', [ Post_Extractor_Contributor_App_Admin::class, 'init' ] );
add_action( 'admin_notices', [ Post_Extractor_Contributor_App_Admin::class, 'admin_notice_error' ] );
