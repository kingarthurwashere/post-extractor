<?php
/**
 * wp-admin moderation UI for citizen submissions.
 *
 * @package post-extractor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Post_Extractor_Citizen_Admin {

	public const NONCE = 'pe_citizen_moderation';
	private const META_REJECTION_REASON = '_pe_citizen_rejection_reason';

	/** @var bool */
	private static $hooks_registered = false;

	public static function init(): void {
		if ( self::$hooks_registered ) {
			return;
		}
		self::$hooks_registered = true;
		add_action( 'add_meta_boxes', [ self::class, 'add_box' ] );
		add_action( 'save_post_' . Post_Extractor_Citizen::POST_TYPE, [ self::class, 'save' ], 10, 2 );
		add_filter( 'post_row_actions', [ self::class, 'row_actions' ], 10, 2 );
		add_action( 'admin_init', [ self::class, 'handle_row_action_request' ] );
		add_action( 'admin_notices', [ self::class, 'admin_notice_result' ] );
	}

	public static function add_box(): void {
		add_meta_box(
			'pe_citizen_moderation',
			__( 'Submission decision', 'post-extractor' ),
			[ self::class, 'render_box' ],
			Post_Extractor_Citizen::POST_TYPE,
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
		if ( $post->post_type !== Post_Extractor_Citizen::POST_TYPE ) {
			return;
		}
		wp_nonce_field( self::NONCE, 'pe_citizen_moderation_nonce' );
		$st  = self::map_status( $post );
		$rej = (string) get_post_meta( $post->ID, self::META_REJECTION_REASON, true );

		echo '<p><strong>' . esc_html__( 'Status:', 'post-extractor' ) . '</strong> ';
		echo esc_html( $st );
		echo '</p>';

		if ( $st === 'rejected' && $rej !== '' ) {
			echo '<p class="description">' . esc_html( $rej ) . '</p>';
		}

		if ( $st !== 'pending' ) {
			echo '<p class="description">' . esc_html__( 'Decisions are final from this screen. Use REST/API tools only for advanced flows.', 'post-extractor' ) . '</p>';
			return;
		}
		?>
		<div style="margin:12px 0; border-top:1px solid #dcdcde; padding-top:10px;">
			<p>
				<button type="submit" class="button button-primary" name="pe_citizen_action" value="approve">
					<?php esc_html_e( 'Approve submission', 'post-extractor' ); ?>
				</button>
			</p>
			<p>
				<label for="pe_citizen_reject_reason"><strong><?php esc_html_e( 'Rejection note (optional)', 'post-extractor' ); ?></strong></label>
			</p>
			<textarea class="widefat" rows="3" name="pe_citizen_reject_reason" id="pe_citizen_reject_reason" placeholder="<?php esc_attr_e( 'Why this pitch was not accepted…', 'post-extractor' ); ?>"></textarea>
			<p>
				<button type="submit" class="button" name="pe_citizen_action" value="reject">
					<?php esc_html_e( 'Reject submission', 'post-extractor' ); ?>
				</button>
			</p>
		</div>
		<?php
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
		if ( ! isset( $_POST['pe_citizen_moderation_nonce'] ) || ! is_string( $_POST['pe_citizen_moderation_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pe_citizen_moderation_nonce'] ) ), self::NONCE ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! current_user_can( 'edit_post', (int) $post_id ) ) {
			return;
		}
		$act = '';
		if ( isset( $_POST['pe_citizen_action'] ) && is_string( $_POST['pe_citizen_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$act = sanitize_text_field( wp_unslash( $_POST['pe_citizen_action'] ) );
		}
		if ( $act !== 'approve' && $act !== 'reject' ) {
			return;
		}
		$p = $post;
		if ( ! ( $p instanceof WP_Post ) || (int) $p->ID !== (int) $post_id || $p->post_type !== Post_Extractor_Citizen::POST_TYPE ) {
			$p2 = get_post( (int) $post_id );
			if ( ! ( $p2 instanceof WP_Post ) || $p2->post_type !== Post_Extractor_Citizen::POST_TYPE ) {
				return;
			}
			$p = $p2;
		}
		if ( self::map_status( $p ) !== 'pending' ) {
			return;
		}
		if ( 'approve' === $act ) {
			update_post_meta( (int) $post_id, Post_Extractor_Citizen::META_MODERATION, 'approved' );
			delete_post_meta( (int) $post_id, self::META_REJECTION_REASON );
			wp_update_post(
				[
					'ID'          => (int) $post_id,
					'post_status' => 'publish',
				]
			);
			do_action( 'post_extractor_citizen_submission_approved', (int) $post_id, 'admin' );
			return;
		}
		$reason = '';
		if ( isset( $_POST['pe_citizen_reject_reason'] ) && is_string( $_POST['pe_citizen_reject_reason'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$reason = (string) wp_unslash( $_POST['pe_citizen_reject_reason'] );
		}
		$reason = trim( wp_strip_all_tags( $reason, true ) );
		if ( $reason === '' ) {
			$reason = __( 'Rejected by editorial review.', 'post-extractor' );
		}
		if ( strlen( $reason ) > 2000 ) {
			$reason = (string) mb_substr( $reason, 0, 2000 );
		}
		update_post_meta( (int) $post_id, Post_Extractor_Citizen::META_MODERATION, 'rejected' );
		update_post_meta( (int) $post_id, self::META_REJECTION_REASON, $reason );
		wp_update_post(
			[
				'ID'          => (int) $post_id,
				'post_status' => 'draft',
			]
		);
		do_action( 'post_extractor_citizen_submission_rejected', (int) $post_id, $reason, 'admin' );
	}

	/**
	 * @return 'pending'|'verified'|'rejected'
	 */
	private static function map_status( WP_Post $post ): string {
		$raw = get_post_meta( $post->ID, Post_Extractor_Citizen::META_MODERATION, true );
		if ( is_string( $raw ) && $raw !== '' ) {
			$k = sanitize_key( $raw );
			if ( in_array( $k, [ 'rejected', 'denied' ], true ) ) {
				return 'rejected';
			}
			if ( in_array( $k, [ 'approved', 'verified' ], true ) ) {
				return 'verified';
			}
			if ( in_array( $k, [ 'pending', 'pendingreview' ], true ) ) {
				return 'pending';
			}
		}
		$st = $post->post_status;
		if ( 'pending' === $st ) {
			return 'pending';
		}
		if ( in_array( $st, [ 'publish', 'private', 'future' ], true ) ) {
			return 'verified';
		}
		if ( in_array( $st, [ 'trash', 'draft' ], true ) ) {
			return 'rejected';
		}
		return 'pending';
	}

	/**
	 * @param array<string, string> $actions
	 * @param WP_Post               $post
	 * @return array<string, string>
	 */
	public static function row_actions( $actions, $post ): array {
		if ( ! is_array( $actions ) || ! ( $post instanceof WP_Post ) ) {
			return is_array( $actions ) ? $actions : [];
		}
		if ( $post->post_type !== Post_Extractor_Citizen::POST_TYPE ) {
			return $actions;
		}
		if ( self::map_status( $post ) !== 'pending' ) {
			return $actions;
		}
		$approve_url = wp_nonce_url(
			add_query_arg(
				[
					'pe_citizen_row_action' => 'approve',
					'post' => (int) $post->ID,
				],
				admin_url( 'edit.php?post_type=' . Post_Extractor_Citizen::POST_TYPE )
			),
			self::NONCE . '_row_' . (int) $post->ID
		);
		$reject_url = wp_nonce_url(
			add_query_arg(
				[
					'pe_citizen_row_action' => 'reject',
					'post' => (int) $post->ID,
				],
				admin_url( 'edit.php?post_type=' . Post_Extractor_Citizen::POST_TYPE )
			),
			self::NONCE . '_row_' . (int) $post->ID
		);
		$actions['pe_citizen_approve'] = '<a href="' . esc_url( $approve_url ) . '">' . esc_html__( 'Approve', 'post-extractor' ) . '</a>';
		$actions['pe_citizen_reject'] = '<a href="' . esc_url( $reject_url ) . '">' . esc_html__( 'Reject', 'post-extractor' ) . '</a>';
		return $actions;
	}

	public static function handle_row_action_request(): void {
		if ( ! is_admin() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		if ( ! isset( $_GET['pe_citizen_row_action'], $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$action = sanitize_text_field( (string) wp_unslash( $_GET['pe_citizen_row_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $action !== 'approve' && $action !== 'reject' ) {
			return;
		}
		$post_id = absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $post_id < 1 ) {
			return;
		}
		check_admin_referer( self::NONCE . '_row_' . $post_id );
		$post = get_post( $post_id );
		if ( ! ( $post instanceof WP_Post ) || $post->post_type !== Post_Extractor_Citizen::POST_TYPE ) {
			self::redirect_with_result( 'error', __( 'Submission not found.', 'post-extractor' ) );
		}
		if ( self::map_status( $post ) !== 'pending' ) {
			self::redirect_with_result( 'error', __( 'Only pending submissions can be actioned.', 'post-extractor' ) );
		}
		if ( $action === 'approve' ) {
			update_post_meta( $post_id, Post_Extractor_Citizen::META_MODERATION, 'approved' );
			delete_post_meta( $post_id, self::META_REJECTION_REASON );
			wp_update_post(
				[
					'ID' => $post_id,
					'post_status' => 'publish',
				]
			);
			do_action( 'post_extractor_citizen_submission_approved', $post_id, 'admin_row' );
			self::redirect_with_result( 'success', __( 'Submission approved.', 'post-extractor' ) );
		}
		update_post_meta( $post_id, Post_Extractor_Citizen::META_MODERATION, 'rejected' );
		update_post_meta( $post_id, self::META_REJECTION_REASON, __( 'Rejected by editorial review.', 'post-extractor' ) );
		wp_update_post(
			[
				'ID' => $post_id,
				'post_status' => 'draft',
			]
		);
		do_action( 'post_extractor_citizen_submission_rejected', $post_id, __( 'Rejected by editorial review.', 'post-extractor' ), 'admin_row' );
		self::redirect_with_result( 'success', __( 'Submission rejected.', 'post-extractor' ) );
	}

	private static function redirect_with_result( string $type, string $message ): void {
		$url = add_query_arg(
			[
				'post_type' => Post_Extractor_Citizen::POST_TYPE,
				'pe_citizen_msg' => rawurlencode( $message ),
				'pe_citizen_msg_type' => $type,
			],
			admin_url( 'edit.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	public static function admin_notice_result(): void {
		if ( ! is_admin() || ! isset( $_GET['pe_citizen_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$msg = sanitize_text_field( (string) wp_unslash( $_GET['pe_citizen_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $msg === '' ) {
			return;
		}
		$type = isset( $_GET['pe_citizen_msg_type'] ) // phpcs:ignore WordPress.Security.NonceVerification
			? sanitize_key( (string) wp_unslash( $_GET['pe_citizen_msg_type'] ) ) // phpcs:ignore WordPress.Security.NonceVerification
			: 'success';
		$klass = $type === 'error' ? 'notice notice-error is-dismissible' : 'notice notice-success is-dismissible';
		echo '<div class="' . esc_attr( $klass ) . '"><p>' . esc_html( $msg ) . '</p></div>';
	}
}

add_action( 'admin_init', [ Post_Extractor_Citizen_Admin::class, 'init' ] );

