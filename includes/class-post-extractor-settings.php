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
        $clean['require_auth'] = ! empty( $input['require_auth'] ) ? 1 : 0;

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
        $require_auth = $options['require_auth']  ?? 1;
        $base_url     = rest_url( 'post-extractor/v1' );

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
                </tbody>
            </table>

            <hr>

            <!-- ── Settings Form ─────────────────────────────────────── -->
            <h2><?php esc_html_e( 'Settings', 'post-extractor' ); ?></h2>
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
                                <?php esc_html_e( 'Send this key as the X-PE-API-Key header or ?api_key= query parameter.', 'post-extractor' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Require Authentication', 'post-extractor' ); ?></th>
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    name="<?php echo esc_attr( self::OPTION_KEY ); ?>[require_auth]"
                                    value="1"
                                    <?php checked( 1, $require_auth ); ?>
                                />
                                <?php esc_html_e( 'Require API key or logged-in editor for all requests', 'post-extractor' ); ?>
                            </label>
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
}
