<?php
/**
 * Approve / reject contributor applications: WordPress user on approve, email notices.
 *
 * @package post-extractor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Post_Extractor_Contributor_Moderation {

	public const META_REJECTION_REASON = '_pe_app_rejection_reason';
	public const META_LINKED_USER_ID  = '_pe_app_wp_user_id';

	/**
	 * @return 'pending'|'approved'|'rejected'
	 */
	public static function map_status( WP_Post $post ): string {
		if ( $post->post_type !== Post_Extractor_Contributor_App::POST_TYPE ) {
			return 'rejected';
		}
		$raw = (string) get_post_meta( $post->ID, Post_Extractor_Contributor_App::META_MODERATION, true );
		if ( in_array( $raw, [ 'rejected', 'denied' ], true ) ) {
			return 'rejected';
		}
		if ( in_array( $raw, [ 'approved' ], true ) ) {
			return 'approved';
		}
		if ( in_array( $raw, [ 'pending' ], true ) ) {
			return 'pending';
		}
		$st = $post->post_status;
		if ( 'pending' === $st ) {
			return 'pending';
		}
		if ( in_array( $st, [ 'publish', 'private', 'future' ], true ) ) {
			return 'approved';
		}
		if ( in_array( $st, [ 'trash' ], true ) ) {
			return 'rejected';
		}
		if ( in_array( $st, [ 'draft' ], true ) ) {
			return 'rejected';
		}
		return 'pending';
	}

	/**
	 * @return array{0: int, 1: string} user id, plain password (empty if none generated)
	 */
	public static function ensure_contributor_user( WP_Post $app ): array|WP_Error {
		$app_id  = (int) $app->ID;
		$email   = (string) get_post_meta( $app_id, Post_Extractor_Contributor_App::META_EMAIL, true );
		$email   = sanitize_email( $email );
		if ( $email === '' || ! is_email( $email ) ) {
			return new WP_Error( 'pe_app_bad_email', __( 'Application is missing a valid email address.', 'post-extractor' ) );
		}

		$existing = (int) get_post_meta( $app_id, self::META_LINKED_USER_ID, true );
		if ( $existing > 0 && get_userdata( $existing ) ) {
			return [ $existing, '' ];
		}

		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$uid = (int) $user->ID;
			$user->add_role( 'contributor' );
			return [ $uid, '' ];
		}

		$display = (string) get_post_meta( $app_id, Post_Extractor_Contributor_App::META_FULL_NAME, true );
		if ( $display === '' ) {
			$first = (string) get_post_meta( $app_id, Post_Extractor_Contributor_App::META_FIRST, true );
			$last  = (string) get_post_meta( $app_id, Post_Extractor_Contributor_App::META_SURNAME, true );
			$display = trim( $first . ' ' . $last );
		}
		if ( $display === '' ) {
			$display = __( 'Contributor', 'post-extractor' );
		}

		$local = (string) strstr( $email, '@', true );
		$local = $local === false ? 'contributor' : $local;
		$base  = sanitize_user( $local, true );
		if ( $base === '' ) {
			$base = 'contributor';
		}
		$login = $base;
		$n     = 0;
		while ( username_exists( $login ) ) {
			++$n;
			$login = $base . '-' . $n;
		}

		$pass = wp_generate_password( 22, true, true );
		$uid  = wp_create_user( $login, $pass, $email );
		if ( is_wp_error( $uid ) ) {
			return $uid;
		}
		$uid = (int) $uid;
		wp_update_user(
			[
				'ID'           => $uid,
				'display_name' => $display,
				'first_name'   => (string) get_post_meta( $app_id, Post_Extractor_Contributor_App::META_FIRST, true ),
				'last_name'    => (string) get_post_meta( $app_id, Post_Extractor_Contributor_App::META_SURNAME, true ),
			]
		);
		$user2 = new WP_User( $uid );
		$user2->set_role( 'contributor' );

		return [ $uid, $pass ];
	}

	/**
	 * @return true|WP_Error
	 */
	public static function approve( int $app_post_id, string $source = 'rest' ): true|WP_Error {
		$app = get_post( $app_post_id );
		if ( ! $app instanceof WP_Post || $app->post_type !== Post_Extractor_Contributor_App::POST_TYPE ) {
			return new WP_Error( 'pe_not_found', __( 'Application not found.', 'post-extractor' ) );
		}
		if ( in_array( $app->post_status, [ 'trash', 'auto-draft' ], true ) ) {
			return new WP_Error( 'pe_inactive', __( 'This application is not active.', 'post-extractor' ) );
		}
		$st = self::map_status( $app );
		if ( $st === 'rejected' ) {
			return new WP_Error( 'pe_already_rejected', __( 'A rejected application cannot be approved. Ask the candidate to re-apply.', 'post-extractor' ) );
		}
		if ( $st === 'approved' ) {
			return true;
		}

		$user_data = self::ensure_contributor_user( $app );
		if ( is_wp_error( $user_data ) ) {
			return $user_data;
		}
		[ $uid, $plain_pass ] = $user_data;

		update_post_meta( $app_post_id, self::META_LINKED_USER_ID, $uid );
		delete_post_meta( $app_post_id, self::META_REJECTION_REASON );
		update_post_meta( $app_post_id, Post_Extractor_Contributor_App::META_MODERATION, 'approved' );
		update_user_meta( $uid, 'pe_contributor_app_id', (string) $app_post_id );

		wp_update_post(
			[
				'ID'          => $app_post_id,
				'post_status' => 'publish',
			]
		);

		self::send_approved_email( $app, $uid, $plain_pass );
		do_action( 'post_extractor_contributor_approved', $app_post_id, $uid, $source );

		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function reject( int $app_post_id, string $reason, string $source = 'rest' ): true|WP_Error {
		$reason = trim( wp_strip_all_tags( (string) $reason, true ) );
		if ( $reason === '' || strlen( $reason ) < 3 ) {
			return new WP_Error( 'pe_reject_reason', __( 'Please provide a short reason (at least 3 characters) for the rejection.', 'post-extractor' ) );
		}
		if ( strlen( $reason ) > 2000 ) {
			$reason = (string) mb_substr( $reason, 0, 2000 );
		}

		$app = get_post( $app_post_id );
		if ( ! $app instanceof WP_Post || $app->post_type !== Post_Extractor_Contributor_App::POST_TYPE ) {
			return new WP_Error( 'pe_not_found', __( 'Application not found.', 'post-extractor' ) );
		}
		if ( in_array( $app->post_status, [ 'trash', 'auto-draft' ], true ) ) {
			return new WP_Error( 'pe_inactive', __( 'This application is not active.', 'post-extractor' ) );
		}
		$st = self::map_status( $app );
		if ( $st === 'approved' ) {
			return new WP_Error( 'pe_already_approved', __( 'An approved application cannot be rejected.', 'post-extractor' ) );
		}
		if ( $st === 'rejected' ) {
			// Idempotent: do not re-send email or change state.
			return true;
		}

		update_post_meta( $app_post_id, self::META_REJECTION_REASON, $reason );
		update_post_meta( $app_post_id, Post_Extractor_Contributor_App::META_MODERATION, 'rejected' );

		wp_update_post(
			[
				'ID'          => $app_post_id,
				'post_status' => 'draft',
			]
		);

		self::send_rejected_email( $app, $reason );
		do_action( 'post_extractor_contributor_rejected', $app_post_id, $reason, $source );

		return true;
	}

	private static function send_approved_email( WP_Post $app, int $user_id, string $plain_password ): void {
		$to = (string) get_post_meta( $app->ID, Post_Extractor_Contributor_App::META_EMAIL, true );
		$to = sanitize_email( $to );
		if ( $to === '' || ! is_email( $to ) ) {
			return;
		}
		$site  = (string) get_bloginfo( 'name', 'display' );
		$login = '';
		$user  = get_userdata( $user_id );
		if ( $user ) {
			$login = (string) $user->user_login;
		}
		$login_url = (string) wp_login_url();
		$subj = sprintf( /* translators: %s: site name */ __( '[%s] Your contributor application was approved', 'post-extractor' ), $site );
		$body = sprintf(
			// translators: 1: site name, 2: user login, 3: password block or "use your existing", 4: login URL
			__( "Hello,\n\nYour contributor application to %1\$s was approved. You can sign in to the website as a contributor to help manage your work.\n\n%2\$s\n\n%3\$s\n\nLog in: %4\$s\n", 'post-extractor' ),
			$site,
			$login !== '' ? sprintf( /* translators: %s: WordPress username */ __( 'WordPress username: %s', 'post-extractor' ), $login ) : '',
			$plain_password !== ''
			? sprintf( /* translators: %s: one-time password */ __( "One-time password (change after signing in): %s\n", 'post-extractor' ), $plain_password ) . "\n" . __( 'We recommend setting a new password under your profile on the site.', 'post-extractor' )
			: __( 'An account with your email address already exists on the site. Sign in with that address and the contributor role has been added to your user.', 'post-extractor' ),
			$login_url
		);
		$body = (string) apply_filters( 'post_extractor_contributor_approved_email_body', $body, (int) $app->ID, $user_id );
		wp_mail( $to, $subj, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );
	}

	private static function send_rejected_email( WP_Post $app, string $reason ): void {
		$to = (string) get_post_meta( $app->ID, Post_Extractor_Contributor_App::META_EMAIL, true );
		$to = sanitize_email( $to );
		if ( $to === '' || ! is_email( $to ) ) {
			return;
		}
		$site = (string) get_bloginfo( 'name', 'display' );
		$subj = sprintf( /* translators: %s: site name */ __( '[%s] Your contributor application was not approved', 'post-extractor' ), $site );
		$body = sprintf(
			// translators: 1: site name, 2: message from the editorial team
			__( "Hello,\n\nThank you for your interest in contributing to %1\$s. After review, the editorial team could not approve the application for now.\n\nMessage from the team:\n%2\$s\n", 'post-extractor' ),
			$site,
			$reason
		);
		$body = (string) apply_filters( 'post_extractor_contributor_rejected_email_body', $body, (int) $app->ID, $reason );
		wp_mail( $to, $subj, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );
	}
}
