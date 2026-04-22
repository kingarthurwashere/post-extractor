<?php
/**
 * Admin Settings Page for Post Extractor.
 *
 * Adds a simple settings page under Settings → Post Extractor where admins can:
 *   - Generate / view the API key.
 *   - Choose whether to require authentication.
 *   - Optionally restrict which post types are exposed.
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
            __( 'Post Extractor API', 'post-extractor' ),
            __( 'Post Extractor', 'post-extractor' ),
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_settings_page' ]
        );
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
        // Authentication cannot be disabled (security).
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

        return $clean;
    }

    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Generate a new API key on request.
        if (
            isset( $_POST['generate_api_key'] )
            && check_admin_referer( self::NONCE_KEY )
        ) {
            $options             = get_option( self::OPTION_KEY, [] );
            $options['api_key']  = wp_generate_password( 40, false );
            update_option( self::OPTION_KEY, $options );
            echo '<div class="notice notice-success"><p>' . esc_html__( 'New API key generated.', 'post-extractor' ) . '</p></div>';
        }

        $options      = get_option( self::OPTION_KEY, [] );
        $api_key      = $options['api_key']      ?? '';
        $base_url     = rest_url( 'post-extractor/v1' );
        $meta_allow   = $options['meta_keys_allowlist'] ?? '';
        $acf_allow    = $options['acf_field_names_allowlist'] ?? '';
        $rate_limit   = isset( $options['rate_limit_per_minute'] ) ? (int) $options['rate_limit_per_minute'] : 120;
        $wp_core_groups = $this->discover_wp_core_endpoint_groups();

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Post Extractor API', 'post-extractor' ); ?></h1>

            <!-- ── API Endpoints ─────────────────────────────────────── -->
            <h2><?php esc_html_e( 'Available Endpoints', 'post-extractor' ); ?></h2>
            <table class="widefat striped" style="max-width:780px">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Method', 'post-extractor' ); ?></th>
                        <th><?php esc_html_e( 'Endpoint', 'post-extractor' ); ?></th>
                        <th><?php esc_html_e( 'Description', 'post-extractor' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>GET</code></td>
                        <td><code><?php echo esc_url( $base_url . '/types' ); ?></code></td>
                        <td><?php esc_html_e( 'List extractable post types (including non-public CPTs)', 'post-extractor' ); ?></td>
                    </tr>
                    <tr>
                        <td><code>GET</code></td>
                        <td><code><?php echo esc_url( $base_url . '/posts' ); ?></code></td>
                        <td><?php esc_html_e( 'Paginated posts (all types). Supports: page, per_page, post_type, search, status, orderby, order, tax[]', 'post-extractor' ); ?></td>
                    </tr>
                    <tr>
                        <td><code>GET</code></td>
                        <td><code><?php echo esc_url( $base_url . '/posts/{id}' ); ?></code></td>
                        <td><?php esc_html_e( 'Single post with sections, meta, ACF, taxonomies, featured image', 'post-extractor' ); ?></td>
                    </tr>
                    <tr>
                        <td><code>GET</code></td>
                        <td><code><?php echo esc_url( $base_url . '/site-identity' ); ?></code></td>
                        <td><?php esc_html_e( 'Site name, URLs, theme custom logo, Site Icon (multiple sizes), and mark_url for mobile apps', 'post-extractor' ); ?></td>
                    </tr>
                </tbody>
            </table>

            <h2 style="margin-top:20px"><?php esc_html_e( 'WordPress Core REST Endpoints (wp/v2)', 'post-extractor' ); ?></h2>
            <p class="description" style="max-width:780px">
                <?php esc_html_e( 'These endpoints are provided by WordPress core and active plugins/themes on this site.', 'post-extractor' ); ?>
            </p>
            <?php if ( empty( $wp_core_groups ) ) : ?>
                <table class="widefat striped" style="max-width:780px">
                    <tbody>
                        <tr>
                            <td><?php esc_html_e( 'No wp/v2 endpoints found.', 'post-extractor' ); ?></td>
                        </tr>
                    </tbody>
                </table>
            <?php else : ?>
                <?php foreach ( $wp_core_groups as $group_name => $rows ) : ?>
                    <h3 style="margin-top:16px"><?php echo esc_html( strtoupper( $group_name ) ); ?></h3>
                    <table class="widefat striped" style="max-width:780px; margin-bottom:12px">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Method(s)', 'post-extractor' ); ?></th>
                                <th><?php esc_html_e( 'Endpoint', 'post-extractor' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $rows as $row ) : ?>
                                <tr>
                                    <td><code><?php echo esc_html( $row['methods'] ); ?></code></td>
                                    <td><code><?php echo esc_url( $row['url'] ); ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endforeach; ?>
            <?php endif; ?>

            <hr>

            <!-- ── Settings Form ─────────────────────────────────────── -->
            <h2><?php esc_html_e( 'Settings', 'post-extractor' ); ?></h2>
            <p class="description" style="max-width:780px">
                <?php esc_html_e( 'All Post Extractor REST requests require a valid API key via the X-PE-API-Key HTTP header only. Logged-in users with edit_posts may call endpoints without the key.', 'post-extractor' ); ?>
            </p>
            <form method="post" action="options.php">
                <?php settings_fields( self::OPTION_KEY ); ?>
                <table class="form-table" style="max-width:780px">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'API Key', 'post-extractor' ); ?></th>
                        <td>
                            <input
                                type="text"
                                name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_key]"
                                value="<?php echo esc_attr( $api_key ); ?>"
                                class="regular-text"
                                autocomplete="off"
                            />
                            <p class="description">
                                <?php esc_html_e( 'Send only as the X-PE-API-Key header. Query-string keys are not accepted.', 'post-extractor' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Rate limit', 'post-extractor' ); ?></th>
                        <td>
                            <input
                                type="number"
                                name="<?php echo esc_attr( self::OPTION_KEY ); ?>[rate_limit_per_minute]"
                                value="<?php echo esc_attr( (string) $rate_limit ); ?>"
                                class="small-text"
                                min="0"
                                max="10000"
                                step="1"
                            />
                            <p class="description">
                                <?php esc_html_e( 'Max requests per IP per minute for API-key clients. Use 0 to disable. Editors are not rate-limited.', 'post-extractor' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Post meta allowlist', 'post-extractor' ); ?></th>
                        <td>
                            <textarea
                                name="<?php echo esc_attr( self::OPTION_KEY ); ?>[meta_keys_allowlist]"
                                rows="6"
                                class="large-text code"
                            ><?php echo esc_textarea( $meta_allow ); ?></textarea>
                            <p class="description">
                                <?php esc_html_e( 'One meta key per line or comma-separated. Only these keys are returned on single-post requests. Letters, numbers, hyphen, underscore only. Empty = expose no custom post meta.', 'post-extractor' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'ACF field allowlist', 'post-extractor' ); ?></th>
                        <td>
                            <textarea
                                name="<?php echo esc_attr( self::OPTION_KEY ); ?>[acf_field_names_allowlist]"
                                rows="6"
                                class="large-text code"
                            ><?php echo esc_textarea( $acf_allow ); ?></textarea>
                            <p class="description">
                                <?php esc_html_e( 'Top-level ACF field names (as in get_fields()) to expose. Same format as meta allowlist. Empty = expose no ACF data.', 'post-extractor' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <!-- ── Generate Key ──────────────────────────────────────── -->
            <form method="post">
                <?php wp_nonce_field( self::NONCE_KEY ); ?>
                <p>
                    <button type="submit" name="generate_api_key" class="button button-secondary">
                        <?php esc_html_e( 'Generate New API Key', 'post-extractor' ); ?>
                    </button>
                    <span class="description" style="margin-left:8px">
                        <?php esc_html_e( 'Warning: this will invalidate the current key.', 'post-extractor' ); ?>
                    </span>
                </p>
            </form>

            <hr>

            <!-- ── Quick Usage Example ───────────────────────────────── -->
            <h2><?php esc_html_e( 'Usage Example', 'post-extractor' ); ?></h2>
            <pre style="background:#f6f7f7;padding:16px;max-width:780px;overflow:auto"><code><?php
echo esc_html(
    "# List all post types\n" .
    "curl -H \"X-PE-API-Key: {$api_key}\" \\\n" .
    "     " . rest_url( 'post-extractor/v1/types' ) . "\n\n" .

    "# Get paginated posts (page 1, 5 per page)\n" .
    "curl -H \"X-PE-API-Key: {$api_key}\" \\\n" .
    "     \"" . rest_url( 'post-extractor/v1/posts' ) . "?per_page=5&page=1\"\n\n" .

    "# Filter by post type and taxonomy\n" .
    "curl -H \"X-PE-API-Key: {$api_key}\" \\\n" .
    "     \"" . rest_url( 'post-extractor/v1/posts' ) . "?post_type=product&tax[product_cat]=shirts\"\n\n" .

    "# Single post with sections\n" .
    "curl -H \"X-PE-API-Key: {$api_key}\" \\\n" .
    "     " . rest_url( 'post-extractor/v1/posts/1' )
);
            ?></code></pre>
        </div>
        <?php
    }

    /**
     * Discover registered wp/v2 routes from the active REST server and group
     * them by top-level resource (posts, users, categories, etc.).
     *
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
