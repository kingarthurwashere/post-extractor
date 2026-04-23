<?php
/**
 * Admin settings UI for Post Extractor: content API, citizen & editorial, reference.
 *
 * @package post-extractor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Post_Extractor_Settings {

	const OPTION_KEY  = 'post_extractor_settings';
	const MENU_SLUG   = 'post-extractor-settings';
	const NONCE_KEY   = 'post_extractor_nonce';

	public function add_menu(): void {
		add_options_page(
			__( 'Post Extractor', 'post-extractor' ),
			__( 'Post Extractor', 'post-extractor' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_settings_page' ]
		);
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
	}

	public function register_settings(): void {
		register_setting( self::OPTION_KEY, self::OPTION_KEY, [
			'sanitize_callback' => [ $this, 'sanitize_options' ],
		] );
	}

	public function sanitize_options( $input ): array {
		if ( ! is_array( $input ) ) {
			return get_option( self::OPTION_KEY, [] );
		}

		$clean = [];

		$clean['api_key']      = sanitize_text_field( $input['api_key'] ?? '' );
		$clean['require_auth'] = 1;

		$clean['meta_keys_allowlist'] = isset( $input['meta_keys_allowlist'] )
			? sanitize_textarea_field( $input['meta_keys_allowlist'] )
			: '';

		$clean['acf_field_names_allowlist'] = isset( $input['acf_field_names_allowlist'] )
			? sanitize_textarea_field( $input['acf_field_names_allowlist'] )
			: '';

		$clean['rate_limit_per_minute'] = 120;
		if ( isset( $input['rate_limit_per_minute'] ) && $input['rate_limit_per_minute'] !== '' ) {
			$clean['rate_limit_per_minute'] = max( 0, min( 10000, absint( $input['rate_limit_per_minute'] ) ) );
		}

		$prev  = is_array( get_option( self::OPTION_KEY, [] ) ) ? get_option( self::OPTION_KEY, [] ) : [];
		$clean['newsbepa_editorial_user'] = '';
		if ( isset( $input['newsbepa_editorial_user'] ) && is_string( $input['newsbepa_editorial_user'] ) ) {
			$clean['newsbepa_editorial_user'] = sanitize_user( $input['newsbepa_editorial_user'], true );
		}
		if ( $clean['newsbepa_editorial_user'] !== '' && strlen( $clean['newsbepa_editorial_user'] ) > 60 ) {
			$clean['newsbepa_editorial_user'] = (string) mb_substr( $clean['newsbepa_editorial_user'], 0, 60 );
		}
		$new_pass = '';
		if ( ! empty( $input['newsbepa_editorial_new_password'] ) && is_string( $input['newsbepa_editorial_new_password'] ) ) {
			$new_pass = (string) $input['newsbepa_editorial_new_password'];
		}
		if ( $new_pass !== '' ) {
			if ( strlen( $new_pass ) < 6 ) {
				$clean['newsbepa_editorial_hash'] = (string) ( $prev['newsbepa_editorial_hash'] ?? '' );
			} else {
				$clean['newsbepa_editorial_hash'] = wp_hash_password( $new_pass );
			}
		} else {
			$clean['newsbepa_editorial_hash'] = (string) ( $prev['newsbepa_editorial_hash'] ?? '' );
		}

		return $clean;
	}

	public function enqueue_admin_assets( string $hook ): void {
		if ( 'settings_page_post-extractor-settings' !== $hook ) {
			return;
		}
		$base = plugin_dir_url( __FILE__ ) . '../';
		wp_enqueue_style(
			'post-extractor-admin',
			$base . 'assets/admin-settings.css',
			[],
			POST_EXTRACTOR_VERSION
		);
		wp_enqueue_script(
			'post-extractor-admin',
			$base . 'assets/admin-settings.js',
			[],
			POST_EXTRACTOR_VERSION,
			true
		);
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if (
			isset( $_POST['generate_api_key'] )
			&& check_admin_referer( self::NONCE_KEY )
		) {
			$options            = get_option( self::OPTION_KEY, [] );
			$options['api_key'] = wp_generate_password( 40, false );
			update_option( self::OPTION_KEY, $options );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'A new content API key was generated.', 'post-extractor' ) . '</p></div>';
		}

		$options        = get_option( self::OPTION_KEY, [] );
		$api_key    = $options['api_key'] ?? '';
		$base_url   = rest_url( 'post-extractor/v1' );
		$meta_allow = $options['meta_keys_allowlist'] ?? '';
		$acf_allow  = $options['acf_field_names_allowlist'] ?? '';
		$rate_limit     = isset( $options['rate_limit_per_minute'] ) ? (int) $options['rate_limit_per_minute'] : 120;
		$nbedit_user    = (string) ( $options['newsbepa_editorial_user'] ?? '' );
		$nbedit_has_pw  = ! empty( $options['newsbepa_editorial_hash'] );
		$wp_core_groups = $this->discover_wp_core_endpoint_groups();

		$pt_citizen = class_exists( 'Post_Extractor_Citizen' ) ? Post_Extractor_Citizen::POST_TYPE : 'pe_citizen';
		$pt_contrib = class_exists( 'Post_Extractor_Contributor_App' ) ? Post_Extractor_Contributor_App::POST_TYPE : 'pe_contrib_app';
		$cnt_cit    = wp_count_posts( $pt_citizen );
		$cnt_app    = wp_count_posts( $pt_contrib );
		$n_pend_c   = (int) ( is_object( $cnt_cit ) && isset( $cnt_cit->pending ) ? $cnt_cit->pending : 0 );
		$n_pend_a   = (int) ( is_object( $cnt_app ) && isset( $cnt_app->pending ) ? $cnt_app->pending : 0 );
		$n_all_c    = is_object( $cnt_cit ) ? (int) array_sum( (array) $cnt_cit ) : 0;
		$n_all_a    = is_object( $cnt_app ) ? (int) array_sum( (array) $cnt_app ) : 0;
		$url_cit    = admin_url( 'edit.php?post_type=' . $pt_citizen );
		$url_app    = admin_url( 'edit.php?post_type=' . $pt_contrib );

		?>
		<div id="pe-admin-root" class="wrap pe-admin" role="region" aria-label="<?php esc_attr_e( 'Post Extractor', 'post-extractor' ); ?>">
			<div class="pe-admin__head">
				<h1 style="display:flex;align-items:baseline;gap:0.6rem;flex-wrap:wrap;">
					<?php esc_html_e( 'Post Extractor', 'post-extractor' ); ?>
					<span class="pe-admin__ver"><?php echo esc_html( 'v' . POST_EXTRACTOR_VERSION ); ?></span>
				</h1>
				<p class="pe-admin__sub">
					<?php esc_html_e( 'Content REST for the mobile app and automation, plus a dedicated area for NewsBEFA citizen applications, story submissions, and app editorial list access—visually split below.', 'post-extractor' ); ?>
				</p>
			</div>

			<div class="pe-admin__tablist" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'post-extractor' ); ?>">
				<div class="pe-admin__tab">
					<a href="#pe-overview" class="nav-tab nav-tab-active" data-pe-tab="overview" id="pe-tab-overview" role="tab" aria-selected="true" aria-controls="pe-panel-overview">
						<span class="dashicons dashicons-admin-home" style="line-height:1.4;"></span>
						<?php esc_html_e( 'Overview', 'post-extractor' ); ?>
					</a>
				</div>
				<div class="pe-admin__tab">
					<a href="#pe-settings" class="nav-tab" data-pe-tab="settings" id="pe-tab-settings" role="tab" aria-selected="false" aria-controls="pe-panel-settings" tabindex="-1">
						<span class="dashicons dashicons-admin-settings" style="line-height:1.4;"></span>
						<?php esc_html_e( 'Settings', 'post-extractor' ); ?>
					</a>
				</div>
				<div class="pe-admin__tab">
					<a href="#pe-reference" class="nav-tab" data-pe-tab="reference" id="pe-tab-reference" role="tab" aria-selected="false" aria-controls="pe-panel-reference" tabindex="-1">
						<span class="dashicons dashicons-rest-api" style="line-height:1.4;"></span>
						<?php esc_html_e( 'API reference', 'post-extractor' ); ?>
					</a>
				</div>
			</div>

			<div class="pe-panel" id="pe-panel-overview" data-pe-panel="overview" role="tabpanel" aria-labelledby="pe-tab-overview">
				<div class="pe-panel__inner">
					<h2 class="pe-section-title">
						<span class="dashicons dashicons-chart-area" aria-hidden="true"></span>
						<?php esc_html_e( 'At a glance', 'post-extractor' ); ?>
					</h2>
					<div class="pe-stats" role="list">
						<div class="pe-stat pe-stat--posts" role="listitem">
							<span class="pe-stat__label"><?php esc_html_e( 'Base namespace', 'post-extractor' ); ?></span>
							<span class="pe-stat__val" style="font-size:0.9rem;">/wp-json/post-extractor/v1</span>
						</div>
						<div class="pe-stat pe-stat--submit" role="listitem">
							<span class="pe-stat__label"><?php esc_html_e( 'Pending citizen stories', 'post-extractor' ); ?></span>
							<span class="pe-stat__val"><?php echo (int) $n_pend_c; ?></span>
						</div>
						<div class="pe-stat pe-stat--app" role="listitem">
							<span class="pe-stat__label"><?php esc_html_e( 'Pending applications', 'post-extractor' ); ?></span>
							<span class="pe-stat__val"><?php echo (int) $n_pend_a; ?></span>
						</div>
					</div>
					<p class="pe-note">
						<?php
						printf(
						/* translators: 1: all citizen records, 2: all application records. */
							esc_html__( 'All-time items stored on this site: %1$s citizen / contributor stories, %2$s contributor program rows. Numbers include every status; moderate in the list tables when you are ready.', 'post-extractor' ),
							esc_html( (string) number_format_i18n( $n_all_c ) ),
							esc_html( (string) number_format_i18n( $n_all_a ) )
						);
						?>
					</p>
					<div class="pe-callout pe-callout--citizen">
						<strong><?php esc_html_e( 'Edit here vs. in the app', 'post-extractor' ); ?></strong>
						<?php esc_html_e( 'Use X-PE-API-Key in your integrations for reading posts and accepting submissions. Configure the separate NewsBEFA editorial username/password in Settings to allow list-only sign-in in the app. Approving an application or a story is always done in the tables below (or the block editor) — not in this settings page.', 'post-extractor' ); ?>
					</div>
					<div class="pe-quicklinks">
						<a class="button button-primary" href="<?php echo esc_url( $url_cit ); ?>">
							<span class="dashicons dashicons-clipboard" style="margin-top:2px;"></span>
							<?php esc_html_e( 'Open citizen submissions (wp-admin list)', 'post-extractor' ); ?>
						</a>
						<a class="button" href="<?php echo esc_url( $url_app ); ?>">
							<span class="dashicons dashicons-groups" style="margin-top:2px;"></span>
							<?php esc_html_e( 'Open contributor applications (wp-admin list)', 'post-extractor' ); ?>
						</a>
					</div>
				</div>
			</div>

			<div class="pe-panel" id="pe-panel-settings" data-pe-panel="settings" role="tabpanel" aria-labelledby="pe-tab-settings" hidden>
				<div class="pe-panel__inner" style="padding-top:0.5rem;">
					<p class="pe-note" style="margin:0 0 1.25rem;">
						<?php esc_html_e( 'Save once to update both cards. Hidden form fields in inactive tabs are still part of the same form and will be included when you press “Save all settings”.', 'post-extractor' ); ?>
					</p>
					<form method="post" action="options.php" id="pe-options-form">
						<?php settings_fields( self::OPTION_KEY ); ?>
						<div class="pe-form-row-split" style="align-items:stretch;">
							<div class="pe-panel__inner" style="box-shadow:0 1px 3px rgba(0,0,0,0.08);border-left:3px solid #8b5a2b; margin:0 0.5rem 0.5rem 0;">
								<h2 class="pe-section-title" style="margin-top:0;">
									<span class="dashicons dashicons-admin-post" aria-hidden="true"></span>
									<?php esc_html_e( '1 — Content & public API', 'post-extractor' ); ?>
								</h2>
								<p class="pe-note" style="margin-top:0;">
									<?php esc_html_e( 'Powers the mobile feed, headless fetches, and the X-PE-API-Key used for reading posts, site-identity, submissions, and more.', 'post-extractor' ); ?>
								</p>
								<table class="form-table" role="presentation" style="margin:0;">
									<tr>
										<th scope="row"><label for="pe_api_key"><?php esc_html_e( 'X-PE-API-Key', 'post-extractor' ); ?></label></th>
										<td>
											<input type="text" id="pe_api_key" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_key]" value="<?php echo esc_attr( $api_key ); ?>" class="large-text code" autocomplete="off" style="max-width:100%"/>
											<p class="description"><?php esc_html_e( 'Send as HTTP header only, not a query string.', 'post-extractor' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Client rate limit', 'post-extractor' ); ?></th>
										<td>
											<input type="number" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[rate_limit_per_minute]" value="<?php echo esc_attr( (string) $rate_limit ); ?>" class="small-text" min="0" max="10000" step="1" />
											<p class="description"><?php esc_html_e( 'Requests / IP / minute. Logged-in editors are not throttled. 0 = off.', 'post-extractor' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Post meta allowlist', 'post-extractor' ); ?></th>
										<td>
											<textarea name="<?php echo esc_attr( self::OPTION_KEY ); ?>[meta_keys_allowlist]" rows="5" class="large-text code" style="font-size:12px;"><?php echo esc_textarea( $meta_allow ); ?></textarea>
											<p class="description"><?php esc_html_e( 'One per line or CSV. Empty = no custom meta in single-post payloads.', 'post-extractor' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'ACF allowlist', 'post-extractor' ); ?></th>
										<td>
											<textarea name="<?php echo esc_attr( self::OPTION_KEY ); ?>[acf_field_names_allowlist]" rows="5" class="large-text code" style="font-size:12px;"><?php echo esc_textarea( $acf_allow ); ?></textarea>
											<p class="description"><?php esc_html_e( 'Top-level ACF names. Empty = do not return ACF.', 'post-extractor' ); ?></p>
										</td>
									</tr>
								</table>
							</div>
							<div class="pe-panel__inner" style="box-shadow:0 1px 3px rgba(0,0,0,0.08);border-left:3px solid #1d3f5c; margin:0 0 0.5rem 0.5rem;">
								<h2 class="pe-section-title" style="margin-top:0;">
									<span class="dashicons dashicons-smartphone" aria-hidden="true"></span>
									<?php esc_html_e( '2 — App editorial (lists in NewsBEFA)', 'post-extractor' ); ?>
								</h2>
								<p class="pe-note" style="margin-top:0;">
									<?php esc_html_e( 'Separate from a WordPress user. After signing in, the app sends X-PE-Editorial-Token for read-only editorial lists; moderation is still in wp-admin.', 'post-extractor' ); ?>
								</p>
								<table class="form-table" role="presentation" style="margin:0;">
									<tr>
										<th scope="row"><label for="pe_nbedit_user"><?php esc_html_e( 'App editorial username', 'post-extractor' ); ?></label></th>
										<td>
											<input type="text" id="pe_nbedit_user" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[newsbepa_editorial_user]" value="<?php echo esc_attr( $nbedit_user ); ?>" class="large-text" autocomplete="off" />
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="pe_nbedit_newpw"><?php esc_html_e( 'New app editorial password', 'post-extractor' ); ?></label></th>
										<td>
											<input type="password" id="pe_nbedit_newpw" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[newsbepa_editorial_new_password]" value="" class="large-text" autocomplete="new-password" minlength="6" />
											<p class="description">
												<?php
												echo $nbedit_has_pw
													? esc_html__( 'Leave blank to keep the stored hash, or type a new password to replace it.', 'post-extractor' )
													: esc_html__( 'Minimum 6 characters.', 'post-extractor' );
												?>
											</p>
										</td>
									</tr>
								</table>
							</div>
						</div>
						<?php submit_button( __( 'Save all settings', 'post-extractor' ) ); ?>
					</form>

					<h3 class="pe-section-title" style="font-size:14px;margin:1.5rem 0 0.4rem;">
						<span class="dashicons dashicons-backup" aria-hidden="true"></span>
						<?php esc_html_e( 'Rotate the content API key (separate action)', 'post-extractor' ); ?>
					</h3>
					<p class="description" style="margin:0 0 0.5rem;">
						<?php esc_html_e( 'Invalidates the current key everywhere it is used (app env, scripts, headless).', 'post-extractor' ); ?>
					</p>
					<form method="post" style="margin:0 0 1.25rem;">
						<?php wp_nonce_field( self::NONCE_KEY ); ?>
						<button type="submit" name="generate_api_key" class="button button-secondary" value="1">
							<?php esc_html_e( 'Generate new X-PE-API-Key', 'post-extractor' ); ?>
						</button>
					</form>

					<h3 class="pe-section-title" style="font-size:14px;">
						<span class="dashicons dashicons-editor-code" aria-hidden="true"></span>
						<?php esc_html_e( 'cURL: content (section 1)', 'post-extractor' ); ?>
					</h3>
					<div class="pe-code-block" style="margin-top:0.5rem;">
						<code><?php
						echo esc_html(
							'curl -H "X-PE-API-Key: ' . $api_key . "\" \\\n  " . rest_url( 'post-extractor/v1/types' ) . "\n\ncurl -H \"X-PE-API-Key: " . $api_key . "\" \\\n  \"" . rest_url( 'post-extractor/v1/posts' ) . '?per_page=5&page=1"'
						);
						?></code>
					</div>
				</div>
			</div>

			<div class="pe-panel" id="pe-panel-reference" data-pe-panel="reference" role="tabpanel" aria-labelledby="pe-tab-reference" hidden>
				<div class="pe-panel__inner">
					<h2 class="pe-section-title">
						<span class="dashicons dashicons-rest-api" aria-hidden="true"></span>
						<?php esc_html_e( 'Route map', 'post-extractor' ); ?>
					</h2>
					<p class="description" style="margin:0 0 1rem;">
						<?php esc_html_e( 'All Post Extractor routes are under this REST base URL:', 'post-extractor' ); ?>
						<code style="margin:0 0.25em;"><?php echo esc_url( $base_url ); ?></code>
						<?php esc_html_e( 'The tables group content and site identity (X-PE-API-Key), public contributor and story submission flows, then editorial list APIs (X-PE-Editorial-Token — set credentials on the Settings tab).', 'post-extractor' ); ?>
					</p>

					<h3 class="pe-section-title" style="font-size:12px;letter-spacing:0.04em;text-transform:uppercase;color:#646970;">
						<?php esc_html_e( 'A — Posts, identity, feeds (X-PE-API-Key)', 'post-extractor' ); ?>
					</h3>
					<table class="pe-table widefat">
						<thead><tr><th style="width:3.2rem;"><?php esc_html_e( 'M', 'post-extractor' ); ?></th><th><?php esc_html_e( 'Route & purpose', 'post-extractor' ); ?></th></tr></thead>
						<tbody>
							<tr>
								<td><span class="pe-badge pe-badge--get">GET</span></td>
								<td>
									<code>…/types</code> —
									<?php esc_html_e( 'Enumerates post types exposed to the app.', 'post-extractor' ); ?>
								</td>
							</tr>
							<tr>
								<td><span class="pe-badge pe-badge--get">GET</span></td>
								<td>
									<code>…/posts</code> —
									<?php esc_html_e( 'Paginated posts for feeds, search, and filters.', 'post-extractor' ); ?>
								</td>
							</tr>
							<tr>
								<td><span class="pe-badge pe-badge--get">GET</span></td>
								<td>
									<code>…/site-identity</code> —
									<?php esc_html_e( 'Branding for platform tiles, icons, and titles.', 'post-extractor' ); ?>
								</td>
							</tr>
							<tr>
								<td><span class="pe-badge pe-badge--get">GET</span></td>
								<td>
									<?php esc_html_e( 'Also: categories, CPT scaffolds, cpt/* routes — see the registered REST list on this install.', 'post-extractor' ); ?>
								</td>
							</tr>
						</tbody>
					</table>

					<h3 class="pe-section-title" style="font-size:12px;letter-spacing:0.04em;text-transform:uppercase;color:#646970;margin-top:1.4rem;">
						<?php esc_html_e( 'B — Applications & story submissions (public API + rules)', 'post-extractor' ); ?>
					</h3>
					<table class="pe-table widefat">
						<tbody>
							<tr>
								<td style="width:3.2rem;"><span class="pe-badge pe-badge--get">GET</span></td>
								<td><code>…/contributor-applications/{id}</code> — <?php esc_html_e( 'Poll a contributor application (NewsBEFA).', 'post-extractor' ); ?></td>
							</tr>
							<tr>
								<td><span class="pe-badge pe-badge--post">POST</span></td>
								<td><code>…/contributor-applications</code> — <?php esc_html_e( 'Apply from the app; creates a pending pe_contrib_app post.', 'post-extractor' ); ?></td>
							</tr>
							<tr>
								<td><span class="pe-badge pe-badge--post">POST</span></td>
								<td><code>…/submissions</code> — <?php esc_html_e( 'Submit a story; requires a contributor app id and approval.', 'post-extractor' ); ?></td>
							</tr>
						</tbody>
					</table>

					<h3 class="pe-section-title" style="font-size:12px;letter-spacing:0.04em;text-transform:uppercase;color:#646970;margin-top:1.4rem;">
						<?php esc_html_e( 'C — Editorial list APIs (X-PE-Editorial-Token, section 2 above)', 'post-extractor' ); ?>
					</h3>
					<table class="pe-table widefat" style="margin-bottom:1.2rem;">
						<tbody>
							<tr>
								<td style="width:3.2rem;"><span class="pe-badge pe-badge--post">POST</span></td>
								<td><code>…/editorial/login</code> — <?php esc_html_e( 'Exchange username + password (section 2) for a time-limited session token.', 'post-extractor' ); ?></td>
							</tr>
							<tr>
								<td><span class="pe-badge pe-badge--get">GET</span> / <span class="pe-badge pe-badge--post">POST</span></td>
								<td><code>…/editorial/logout</code></td>
							</tr>
							<tr>
								<td><span class="pe-badge pe-badge--get">GET</span></td>
								<td><code>…/editorial/contributor-applications</code> — <?php esc_html_e( 'Paginated pe_contrib_app for mobile screening.', 'post-extractor' ); ?></td>
							</tr>
							<tr>
								<td><span class="pe-badge pe-badge--get">GET</span></td>
								<td><code>…/editorial/citizen-submissions</code> — <?php esc_html_e( 'Paginated pe_citizen for mobile screening.', 'post-extractor' ); ?></td>
							</tr>
						</tbody>
					</table>

					<h2 class="pe-section-title" style="margin-top:0.3rem;">
						<span class="dashicons dashicons-external" aria-hidden="true"></span>
						<?php esc_html_e( 'WordPress core REST (wp/v2) on this site', 'post-extractor' ); ?>
					</h2>
					<?php if ( empty( $wp_core_groups ) ) : ?>
						<p><?php esc_html_e( 'No wp/v2 groups discovered.', 'post-extractor' ); ?></p>
					<?php else : ?>
						<?php foreach ( $wp_core_groups as $group_name => $rows ) : ?>
							<details class="pe-details">
								<summary><?php echo esc_html( strtoupper( (string) $group_name ) ); ?>
									(<?php echo (int) count( $rows ); ?>)
								</summary>
								<div class="pe-details__body">
									<table class="pe-table widefat" style="margin:0;">
										<thead>
										<tr>
											<th style="width:5.5rem;"><?php esc_html_e( 'Methods', 'post-extractor' ); ?></th>
											<th><?php esc_html_e( 'URL', 'post-extractor' ); ?></th>
										</tr>
										</thead>
										<tbody>
										<?php foreach ( $rows as $row ) : ?>
											<tr>
												<td><code style="font-size:10px;"><?php echo esc_html( $row['methods'] ); ?></code></td>
												<td><code style="font-size:10px;word-break:break-all;"><?php echo esc_url( $row['url'] ); ?></code></td>
											</tr>
										<?php endforeach; ?>
										</tbody>
									</table>
								</div>
							</details>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * @return array<string, array<int, array{methods: string, url: string}>>
	 */
	private function discover_wp_core_endpoint_groups(): array {
		$server = rest_get_server();
		if ( ! $server ) {
			return [];
		}

		$routes = $server->get_routes();
		$groups = [];

		foreach ( $routes as $route => $handlers ) {
			if ( ! is_string( $route ) || strpos( $route, '/wp/v2/' ) !== 0 ) {
				continue;
			}

			$methods = [];
			if ( is_array( $handlers ) ) {
				foreach ( $handlers as $handler ) {
					if ( ! is_array( $handler ) || empty( $handler['methods'] ) ) {
						continue;
					}
					$m = $handler['methods'];
					if ( is_array( $m ) ) {
						foreach ( array_keys( $m ) as $mk ) {
							$methods[] = strtoupper( (string) $mk );
						}
					} else {
						$methods[] = strtoupper( (string) $m );
					}
				}
			}

			$methods = array_values( array_unique( array_filter( $methods ) ) );
			sort( $methods );

			$resource = $this->extract_wp_v2_resource_group( $route );
			if ( ! isset( $groups[ $resource ] ) ) {
				$groups[ $resource ] = [];
			}

			$groups[ $resource ][] = [
				'methods' => empty( $methods ) ? 'GET' : implode( ', ', $methods ),
				'url'     => rest_url( ltrim( $route, '/' ) ),
			];
		}

		ksort( $groups );
		foreach ( $groups as $group_key => $rows ) {
			usort(
				$rows,
				static fn( array $a, array $b ): int => strcmp( $a['url'], $b['url'] )
			);
			$groups[ $group_key ] = $rows;
		}

		return $groups;
	}

	private function extract_wp_v2_resource_group( string $route ): string {
		$trimmed = trim( $route, '/' );
		$parts   = explode( '/', $trimmed );
		if ( count( $parts ) < 3 ) {
			return 'misc';
		}

		$resource = sanitize_key( (string) $parts[2] );
		return $resource !== '' ? $resource : 'misc';
	}
}

