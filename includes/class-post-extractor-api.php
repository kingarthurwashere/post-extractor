<?php
/**
 * REST API Controller — Post Extractor
 *
 * Namespace: post-extractor/v1
 *
 * All responses mirror the WP REST API v2 field shape so the Flutter app's
 * PostModel.fromWordPress() / CategoryModel.fromWordPress() parse them without
 * any changes. The plugin adds extra fields on top: sections[], acf{}, meta{}.
 *
 * Endpoints
 * ─────────────────────────────────────────────────────────────────────────────
 * GET  /post-extractor/v1/types
 *      All extractable post types (public + non-public CPTs; excludes system types).
 *
 * GET  /post-extractor/v1/posts
 *      Cross-type paginated feed. Default post types = all extractable types.
 *      status: publish (default), any, or comma list (draft,private,future,…).
 *
 * GET  /post-extractor/v1/posts/{id}
 *      Single post — WP REST shape + sections[], meta{}, acf{}.
 *
 * GET  /post-extractor/v1/categories
 *      Categories with post_count, per_page, orderby, order, hide_empty.
 *
 * GET  /post-extractor/v1/cpt-slugs
 *      All registered CPT slugs except post/page (not limited to show_in_rest).
 *
 * GET  /post-extractor/v1/cpt/{slug}
 *      Items for one CPT slug (mirrors getCustomPostTypeItems()).
 *
 * GET  /post-extractor/v1/cpt-sections
 *      All CPT slugs x items in one call (mirrors getCustomPostTypeSections()).
 *
 * GET  /post-extractor/v1/site-identity
 *      Site name, home URL, theme custom logo, and WordPress “Site Icon” at
 *      several sizes (App clients use [mark_url] for hub / list icons).
 *
 * POST /post-extractor/v1/submissions
 *      Citizen journalism pitch: JSON body (headline, summary, location, routed_desk,
 *      publication). Creates a [pe_citizen] post in "pending" for wp-admin.
 *
 * GET  /post-extractor/v1/submissions/(?P<id>\\d+)
 *      Status sync for the app: maps wp-admin workflow to pending/verified/rejected.
 *
 * GET  /post-extractor/v1/submissions?ids=1,2,3
 *      Same JSON array as batch (max 50).
 *
 * POST /post-extractor/v1/contributor-applications
 *      Apply to write: firstName, surname, email, phone, location, publication.
 *      Creates [pe_contrib_app] pending; editors approve in wp-admin.
 *
 * GET  /post-extractor/v1/contributor-applications/(?P<id>\\d+)
 *      Application status: pending|approved|rejected (for the app to poll).
 *
 * Story POST /submissions requires X-PE-Contributor-App-Id (or JSON contributorApplicationId)
 * and an approved application on the same site for the same publication.
 *
 * POST /post-extractor/v1/editorial/login
 *      NewsBEFA admin only: username + password (set in Settings → Post Extractor). Returns a session token.
 *
 * GET  /post-extractor/v1/editorial/contributor-applications
 * GET  /post-extractor/v1/editorial/citizen-submissions
 *      Require X-PE-Editorial-Token from login. Lists pending/approved items with site + publication context.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Post_Extractor_API {

    const NAMESPACE = 'post-extractor/v1';

    /**
     * Post types to omit from automatic extraction (core/system — not “news” content).
     * Does not apply when the client passes an explicit post_type filter.
     */
    private const INTERNAL_POST_TYPES = [
        'attachment',
        'revision',
        'nav_menu_item',
        'custom_css',
        'customize_changeset',
        'oembed_cache',
        'user_request',
        'wp_block',
        'wp_template',
        'wp_template_part',
        'wp_global_styles',
        'wp_navigation',
        'wp_font_family',
        'wp_font_face',
    ];

    /** Status values allowed in WP_Query (plus any). */
    private const QUERYABLE_STATUSES = [
        'publish',
        'pending',
        'draft',
        'future',
        'private',
        'inherit',
        'trash',
    ];

    public function register_routes(): void {

        register_rest_route( self::NAMESPACE, '/types', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_post_types' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        // Register the single-post route before the collection route so the REST
        // server never mis-matches paths like /posts/123 (see WP REST route order).
        register_rest_route( self::NAMESPACE, '/posts/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_single_post' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'id' => [
                    'required'          => true,
                    'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/posts', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_posts' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => $this->posts_args(),
        ] );

        register_rest_route( self::NAMESPACE, '/categories', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_categories' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'per_page'   => [ 'default' => 20,   'sanitize_callback' => 'absint' ],
                'page'       => [ 'default' => 1,    'sanitize_callback' => 'absint' ],
                'orderby'    => [ 'default' => 'count' ],
                'order'      => [ 'default' => 'desc' ],
                'hide_empty' => [ 'default' => true ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/cpt-slugs', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_cpt_slugs' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/cpt/(?P<slug>[a-z0-9_-]+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_cpt_items' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'slug'     => [ 'required' => true, 'sanitize_callback' => 'sanitize_key' ],
                'page'     => [
                    'default'           => 1,
                    'minimum'           => 1,
                    'sanitize_callback' => 'absint',
                ],
                'per_page' => [
                    'default'           => 20,
                    'minimum'           => 1,
                    'maximum'           => 100,
                    'sanitize_callback' => 'absint',
                ],
                'status'   => [ 'default' => 'publish' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/cpt-sections', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_cpt_sections' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'per_type'  => [ 'default' => 8, 'sanitize_callback' => 'absint' ],
                'max_types' => [ 'default' => 6, 'sanitize_callback' => 'absint' ],
                'status'    => [ 'default' => 'publish' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/site-identity', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_site_identity' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/submissions/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_citizen_submission' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'id' => [
                    'required'          => true,
                    'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/submissions', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'create_citizen_submission' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/submissions', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_citizen_submissions_batch' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'ids' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/contributor-applications/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_contributor_application' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'id' => [
                    'required'          => true,
                    'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/contributor-applications', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'create_contributor_application' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/editorial/login', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'post_editorial_login' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( self::NAMESPACE, '/editorial/logout', [
            'methods'             => [ WP_REST_Server::CREATABLE, WP_REST_Server::READABLE ],
            'callback'            => [ $this, 'post_editorial_logout' ],
            'permission_callback' => [ $this, 'check_editorial' ],
        ] );

        register_rest_route( self::NAMESPACE, '/editorial/contributor-applications', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_editorial_contributor_list' ],
            'permission_callback' => [ $this, 'check_editorial' ],
            'args'                => [
                'page'     => [ 'default' => 1,   'sanitize_callback' => 'absint' ],
                'per_page' => [ 'default' => 30,  'sanitize_callback' => 'absint' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/editorial/contributor-applications/(?P<id>\d+)/approve', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'post_editorial_contributor_approve' ],
            'permission_callback' => [ $this, 'check_editorial' ],
            'args'                => [
                'id' => [
                    'required'          => true,
                    'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/editorial/contributor-applications/(?P<id>\d+)/reject', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'post_editorial_contributor_reject' ],
            'permission_callback' => [ $this, 'check_editorial' ],
            'args'                => [
                'id' => [
                    'required'          => true,
                    'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/editorial/citizen-submissions', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_editorial_citizen_list' ],
            'permission_callback' => [ $this, 'check_editorial' ],
            'args'                => [
                'page'     => [ 'default' => 1,  'sanitize_callback' => 'absint' ],
                'per_page' => [ 'default' => 30, 'sanitize_callback' => 'absint' ],
            ],
        ] );
    }

    // =========================================================================
    // Permission
    // =========================================================================

    public function check_permission( WP_REST_Request $request ): bool|WP_Error {
        if ( current_user_can( 'edit_posts' ) ) {
            return true;
        }

        $rate = $this->enforce_rate_limit();
        if ( is_wp_error( $rate ) ) {
            return $rate;
        }

        $options = get_option( Post_Extractor_Settings::OPTION_KEY, [] );
        $api_key = isset( $options['api_key'] ) ? (string) $options['api_key'] : '';

        if ( $api_key === '' ) {
            return new WP_Error(
                'rest_forbidden',
                __( 'API key is not configured. Set one under Settings → Post Extractor.', 'post-extractor' ),
                [ 'status' => 401 ]
            );
        }

        // Header only — never accept ?api_key= (avoids query-string leaks in logs).
        $provided = (string) $request->get_header( 'X-PE-API-Key' );
        if ( $provided === '' ) {
            $provided = (string) $request->get_header( 'x-pe-api-key' );
        }

        if ( hash_equals( $api_key, $provided ) ) {
            return true;
        }

        return new WP_Error(
            'rest_forbidden',
            __( 'Invalid or missing API key. Send the X-PE-API-Key header only.', 'post-extractor' ),
            [ 'status' => 401 ]
        );
    }

    /**
     * Simple per-IP rate limit (transient window). Skipped for editors (handled above).
     */
    private function enforce_rate_limit(): bool|WP_Error {
        $options = get_option( Post_Extractor_Settings::OPTION_KEY, [] );
        $limit   = isset( $options['rate_limit_per_minute'] ) ? (int) $options['rate_limit_per_minute'] : 120;
        if ( $limit <= 0 ) {
            return true;
        }
        $limit = min( $limit, 10000 );

        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
        $key = 'post_extractor_rl_' . md5( $ip );

        $data = get_transient( $key );
        $now  = time();
        if ( ! is_array( $data ) || ! isset( $data['start'], $data['count'] ) || ( $now - (int) $data['start'] ) >= 60 ) {
            $data = [ 'start' => $now, 'count' => 0 ];
        }

        ++$data['count'];
        set_transient( $key, $data, 60 );

        if ( $data['count'] > $limit ) {
            return new WP_Error(
                'rest_too_many_requests',
                __( 'Too many requests. Try again in a minute.', 'post-extractor' ),
                [ 'status' => 429 ]
            );
        }

        return true;
    }

    // =========================================================================
    // Endpoint callbacks
    // =========================================================================

    public function get_post_types( WP_REST_Request $request ): WP_REST_Response {
        $slugs = $this->get_extractable_post_type_slugs();
        $types = [];

        foreach ( $slugs as $slug ) {
            $obj = get_post_type_object( $slug );
            if ( ! $obj ) {
                continue;
            }
            $types[] = [
                'slug'         => $slug,
                'name'         => $obj->name,
                'label'        => $obj->label,
                'singular'     => $obj->labels->singular_name ?? $obj->label,
                'hierarchical' => $obj->hierarchical,
                'supports'     => get_all_post_type_supports( $slug ),
                'taxonomies'   => get_object_taxonomies( $slug ),
                'rest_base'    => $obj->rest_base ?? $slug,
            ];
        }

        return rest_ensure_response( [ 'total' => count( $types ), 'types' => $types ] );
    }

    public function get_posts( WP_REST_Request $request ): WP_REST_Response {
        [ $per_page, $page ] = $this->normalize_pagination( $request, 10 );
        $status   = $this->parse_post_status_param( $request->get_param( 'status' ) );
        $search   = sanitize_text_field( $request->get_param( 'search' ) ?? '' );
        $sticky   = $request->get_param( 'sticky' );

        $post_type_param = $request->get_param( 'post_type' );
        $post_types = empty( $post_type_param )
            ? $this->get_extractable_post_type_slugs()
            : array_map( 'sanitize_key', (array) $post_type_param );

        $args = [
            'post_type'      => $post_types,
            'post_status'    => $status,
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => sanitize_key( $request->get_param( 'orderby' ) ?: 'date' ),
            'order'          => strtoupper( $request->get_param( 'order' ) ?: 'DESC' ) === 'ASC' ? 'ASC' : 'DESC',
        ];

        if ( ! empty( $search ) ) {
            $args['s'] = $search;
        }

        // Flutter sends: queryParams['categories'] = categoryId  (integer)
        $cat_id = $request->get_param( 'categories' );
        if ( $cat_id ) {
            $args['cat'] = (int) $cat_id;
        }

        // Flutter sends: queryParams['sticky'] = true  (featuredOnly)
        if ( filter_var( $sticky, FILTER_VALIDATE_BOOLEAN ) ) {
            $sticky_ids = get_option( 'sticky_posts', [] );
            $args['post__in'] = ! empty( $sticky_ids ) ? $sticky_ids : [ 0 ];
        }

        $tax_q = $this->build_tax_query( $request->get_param( 'tax' ) );
        if ( ! empty( $tax_q ) ) {
            $args['tax_query'] = $tax_q;
        }

        $query = new WP_Query( $args );

        $total       = (int) $query->found_posts;
        $total_pages = (int) $query->max_num_pages;
        $items       = array_map( fn( $p ) => $this->format_post( $p ), $query->posts );

        // Match WP REST / wp/v2 collections: beyond the last page return 200 with an
        // empty `posts` array and stable X-WP-Total / X-WP-TotalPages (never 404).
        if ( $total > 0 && $total_pages > 0 && $page > $total_pages ) {
            $items = [];
        }

        $response = rest_ensure_response( [
            'total'       => $total,
            'total_pages' => $total_pages,
            'page'        => $page,
            'per_page'    => $per_page,
            'posts'       => $items,
        ] );

        $response->header( 'X-WP-Total',      $total );
        $response->header( 'X-WP-TotalPages', $total_pages );

        return $response;
    }

    public function get_single_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $post = get_post( $request->get_param( 'id' ) );

        if ( ! $post || in_array( $post->post_status, [ 'trash', 'auto-draft' ], true ) ) {
            return new WP_Error(
                'rest_not_found',
                __( 'Post not found.', 'post-extractor' ),
                [ 'status' => 404 ]
            );
        }

        $meta = $this->build_meta();

        return rest_ensure_response( array_merge(
            $this->format_post( $post ),
            [
                'meta' => $meta->get_meta( $post->ID ),
                'acf'  => $meta->get_acf( $post->ID ),
            ]
        ) );
    }

    public function get_categories( WP_REST_Request $request ): WP_REST_Response {
        $per_page = (int) $request->get_param( 'per_page' );
        $page     = (int) $request->get_param( 'page' );

        $args = [
            'taxonomy'   => 'category',
            'number'     => $per_page,
            'offset'     => ( $page - 1 ) * $per_page,
            'orderby'    => sanitize_key( $request->get_param( 'orderby' ) ?: 'count' ),
            'order'      => strtoupper( $request->get_param( 'order' ) ?: 'DESC' ) === 'ASC' ? 'ASC' : 'DESC',
            'hide_empty' => filter_var( $request->get_param( 'hide_empty' ), FILTER_VALIDATE_BOOLEAN ),
        ];

        $terms = get_terms( $args );
        if ( is_wp_error( $terms ) ) {
            return rest_ensure_response( [] );
        }

        $data  = array_map( fn( $t ) => $this->format_term( $t ), $terms );
        $total = wp_count_terms( [ 'taxonomy' => 'category', 'hide_empty' => $args['hide_empty'] ] );

        $response = rest_ensure_response( $data );
        $response->header( 'X-WP-Total', is_wp_error( $total ) ? 0 : $total );

        return $response;
    }

    public function get_cpt_slugs( WP_REST_Request $request ): WP_REST_Response {
        return rest_ensure_response( $this->discover_cpt_slugs() );
    }

    public function get_cpt_items( WP_REST_Request $request ): WP_REST_Response {
        $slug = $request->get_param( 'slug' );
        [ $per_page, $page ] = $this->normalize_pagination( $request, 20 );
        $status = $this->parse_post_status_param( $request->get_param( 'status' ) );

        $query = new WP_Query( [
            'post_type'      => $slug,
            'post_status'    => $status,
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        $total       = (int) $query->found_posts;
        $total_pages = (int) $query->max_num_pages;
        $items       = array_map( fn( $p ) => $this->format_post( $p ), $query->posts );

        if ( $total > 0 && $total_pages > 0 && $page > $total_pages ) {
            $items = [];
        }

        $response = rest_ensure_response( [
            'slug'        => $slug,
            'label'       => $this->humanize( $slug ),
            'total'       => $total,
            'total_pages' => $total_pages,
            'items'       => $items,
        ] );

        $response->header( 'X-WP-Total',      $total );
        $response->header( 'X-WP-TotalPages', $total_pages );

        return $response;
    }

    /** Mirrors getCustomPostTypeSections() — returns all CPT slugs x items. */
    public function get_cpt_sections( WP_REST_Request $request ): WP_REST_Response {
        $per_type  = (int) $request->get_param( 'per_type' );
        $max_types = (int) $request->get_param( 'max_types' );
        $status    = $this->parse_post_status_param( $request->get_param( 'status' ) );
        $slugs     = array_slice( $this->discover_cpt_slugs(), 0, $max_types );
        $sections  = [];

        foreach ( $slugs as $slug ) {
            $query = new WP_Query( [
                'post_type'      => $slug,
                'post_status'    => $status,
                'posts_per_page' => $per_type,
                'orderby'        => 'date',
                'order'          => 'DESC',
            ] );

            if ( empty( $query->posts ) ) {
                continue;
            }

            $sections[] = [
                'slug'  => $slug,
                'label' => $this->humanize( $slug ),
                'items' => array_map( fn( $p ) => $this->format_post( $p ), $query->posts ),
            ];
        }

        return rest_ensure_response( $sections );
    }

    /**
     * WordPress “Site Icon” (Customizer) + theme custom logo — best URLs for mobile clients.
     * Response is cacheable (short TTL) and inexpensive to build.
     */
    public function get_site_identity( WP_REST_Request $request ): WP_REST_Response {
        $cache_key   = 'post_pe_site_id_v1';
        $transient   = get_transient( $cache_key );
        $bypass      = (string) $request->get_param( 'bypass_cache' ) === '1';
        if ( is_array( $transient ) && ! $bypass ) {
            $response = rest_ensure_response( $transient );
        } else {
            $data     = $this->build_site_identity_payload();
            $transient = $data;
            set_transient( $cache_key, $transient, HOUR_IN_SECONDS );
            $response = rest_ensure_response( $data );
        }

        $response->header( 'Cache-Control', 'public, max-age=600' );

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function build_site_identity_payload(): array {
        $home     = home_url( '/' );
        $site_url = (string) get_option( 'siteurl' );
        if ( $site_url === '' ) {
            $site_url = $home;
        }
        $name     = (string) get_bloginfo( 'name' );
        $home_u   = esc_url( $home );
        $site_u   = esc_url( $site_url );

        $custom_logo_url = '';
        $logo_id         = (int) get_theme_mod( 'custom_logo' );
        if ( $logo_id > 0 ) {
            $raw = wp_get_attachment_image_url( $logo_id, 'full' );
            if ( is_string( $raw ) && $raw !== '' ) {
                $custom_logo_url = esc_url( $raw );
            }
        }

        // Vector logos (SVG) are common in themes but mobile apps often expect bitmaps only.
        $custom_logo_for_mark = $custom_logo_url;
        if ( $logo_id > 0 ) {
            $mime = (string) get_post_mime_type( $logo_id );
            if ( $mime === 'image/svg+xml' || preg_match( '/\.svg(\?|#|$)/i', $custom_logo_for_mark ) ) {
                $custom_logo_for_mark = '';
            }
        }

        $favicon_guess = $home_u;
        if ( is_string( $favicon_guess ) && $favicon_guess !== '' ) {
            $favicon_guess = rtrim( $favicon_guess, '/' ) . '/favicon.ico';
        } else {
            $favicon_guess = $site_u . '/favicon.ico';
        }

        $sizes   = [ 32, 64, 128, 192, 256, 512 ];
        $icons   = [];
        $has_pe  = ( (int) get_option( 'site_icon' ) ) > 0;
        foreach ( $sizes as $px ) {
            $u = (string) get_site_icon_url( $px, '' );
            if ( $u !== '' ) {
                $icons[ (string) $px ] = $u;
            }
        }

        if ( ! $has_pe && $favicon_guess !== '' ) {
            $icons['favicon_ico'] = $favicon_guess;
        }

        $mark_url = $custom_logo_for_mark;
        $source   = 'custom_logo';
        if ( $mark_url === '' && isset( $icons['192'] ) ) {
            $mark_url = $icons['192'];
            $source   = 'site_icon';
        } elseif ( $mark_url === '' && isset( $icons['512'] ) ) {
            $mark_url = $icons['512'];
            $source   = 'site_icon';
        } elseif ( $mark_url === '' && isset( $icons['256'] ) ) {
            $mark_url = $icons['256'];
            $source   = 'site_icon';
        } elseif ( $mark_url === '' && ! empty( $icons ) ) {
            $mark_url = (string) reset( $icons );
            $source   = 'site_icon';
        } elseif ( $mark_url === '' && $favicon_guess !== '' ) {
            $mark_url = $favicon_guess;
            $source   = 'favicon_guess';
        }
        if ( $mark_url === '' ) {
            $source = 'none';
        }

        return [
            'name'         => $name,
            'home'         => $home_u,
            'siteurl'      => $site_u,
            'mark_url'     => $mark_url,
            'mark_source'  => $source,
            'custom_logo'  => $custom_logo_url === '' ? null : $custom_logo_url,
            'icons'        => $icons,
            'version'      => 1,
        ];
    }

    // =========================================================================
    // Formatters
    // =========================================================================

    /**
     * Format a WP_Post to match the WP REST API ?_embed=true shape.
     *
     * Flutter PostModel.fromWordPress() expects:
     *   id, slug, status, link, date, modified, sticky
     *   title['rendered'], content['rendered'], excerpt['rendered']
     *   _embedded['author'][0].name
     *   _embedded['wp:featuredmedia'][0].source_url / .media_details.sizes
     *   _embedded['wp:term'][n][] → categories / tags
     */
    private function format_post( WP_Post $post ): array {
        $rest_data = $this->get_core_rest_post_data( $post );
        if ( ! empty( $rest_data ) ) {
            $blocks = new Post_Extractor_Blocks();
            $rest_data['sections'] = $blocks->parse( $post->post_content );
            return $rest_data;
        }

        // Featured media ──────────────────────────────────────────────────────
        $thumbnail_id  = get_post_thumbnail_id( $post->ID );
        $featured_media_embed = [];

        if ( $thumbnail_id ) {
            $full   = wp_get_attachment_image_src( $thumbnail_id, 'full' );
            $medium = wp_get_attachment_image_src( $thumbnail_id, 'medium_large' );
            $thumb  = wp_get_attachment_image_src( $thumbnail_id, 'thumbnail' );
            $alt    = get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true );

            $featured_media_embed = [ [
                'id'         => $thumbnail_id,
                'source_url' => $full ? $full[0] : '',
                'alt_text'   => (string) $alt,
                'media_details' => [
                    'width'  => $full ? (int) $full[1] : 0,
                    'height' => $full ? (int) $full[2] : 0,
                    'sizes'  => array_filter( [
                        'full'         => $full   ? [ 'source_url' => $full[0],   'width' => $full[1],   'height' => $full[2]   ] : null,
                        'medium_large' => $medium ? [ 'source_url' => $medium[0], 'width' => $medium[1], 'height' => $medium[2] ] : null,
                        'thumbnail'    => $thumb  ? [ 'source_url' => $thumb[0],  'width' => $thumb[1],  'height' => $thumb[2]  ] : null,
                    ] ),
                ],
            ] ];
        }

        // Author ──────────────────────────────────────────────────────────────
        $author_id = (int) $post->post_author;
        $author_embed = [ [
            'id'          => $author_id,
            'name'        => get_the_author_meta( 'display_name', $author_id ),
            'slug'        => get_the_author_meta( 'user_nicename', $author_id ),
            'avatar_urls' => [ '96' => get_avatar_url( $author_id, [ 'size' => 96 ] ) ],
        ] ];

        // Taxonomy terms ──────────────────────────────────────────────────────
        $term_groups = [];
        foreach ( get_object_taxonomies( $post->post_type ) as $tax ) {
            $terms = get_the_terms( $post->ID, $tax );
            if ( $terms && ! is_wp_error( $terms ) ) {
                $term_groups[] = array_map( fn( $t ) => $this->format_term( $t ), $terms );
            }
        }

        // Sections (Gutenberg blocks) ─────────────────────────────────────────
        $blocks   = new Post_Extractor_Blocks();
        $sections = $blocks->parse( $post->post_content );

        return [
            // Standard WP REST fields
            'id'             => $post->ID,
            'date'           => get_the_date( 'c', $post->ID ),
            'date_gmt'       => get_the_date( 'c', $post->ID ),
            'modified'       => get_the_modified_date( 'c', $post->ID ),
            'modified_gmt'   => get_the_modified_date( 'c', $post->ID ),
            'slug'           => $post->post_name,
            'status'         => $post->post_status,
            'type'           => $post->post_type,
            'link'           => get_permalink( $post->ID ),
            'sticky'         => is_sticky( $post->ID ),

            // Rendered text objects (PostModel reads title['rendered'] etc.)
            'title'   => [ 'rendered' => get_the_title( $post->ID ) ],
            'content' => [ 'rendered' => apply_filters( 'the_content', $post->post_content ), 'protected' => false ],
            'excerpt' => [ 'rendered' => get_the_excerpt( $post ), 'protected' => false ],

            // Relation IDs
            'author'         => $author_id,
            'featured_media' => $thumbnail_id ?: 0,
            'categories'     => $this->get_term_ids( $post->ID, 'category' ),
            'tags'           => $this->get_term_ids( $post->ID, 'post_tag' ),

            // _embedded block — what Flutter reads when _embed=true
            '_embedded' => [
                'author'            => $author_embed,
                'wp:featuredmedia'  => $featured_media_embed,
                'wp:term'           => $term_groups,
            ],

            // Plugin extras
            'sections' => $sections,
        ];
    }

    /**
     * Build a single post payload using the core /wp/v2 controller shape so
     * custom REST fields (register_rest_field) are included automatically.
     */
    private function get_core_rest_post_data( WP_Post $post ): array {
        $post_type_obj = get_post_type_object( $post->post_type );
        if ( ! $post_type_obj || ! $post_type_obj->show_in_rest ) {
            return [];
        }

        $rest_base = $post_type_obj->rest_base ?: $post->post_type;
        $path      = sprintf( '/wp/v2/%s/%d', $rest_base, (int) $post->ID );
        $request   = new WP_REST_Request( 'GET', $path );
        $request->set_param( '_embed', 1 );
        $request->set_param( 'context', 'view' );

        $response = rest_do_request( $request );
        if ( is_wp_error( $response ) || ! ( $response instanceof WP_REST_Response ) ) {
            return [];
        }

        if ( $response->get_status() < 200 || $response->get_status() >= 300 ) {
            return [];
        }

        $server = rest_get_server();
        if ( ! $server ) {
            return [];
        }

        $data = $server->response_to_data( $response, true );
        return is_array( $data ) ? $data : [];
    }

    /**
     * Format WP_Term — mirrors /wp/v2/categories shape.
     * CategoryModel.fromWordPress() reads: id, name, slug, count, description, parent.
     */
    private function format_term( WP_Term $term ): array {
        return [
            'id'          => $term->term_id,
            'count'       => (int) $term->count,
            'description' => $term->description,
            'link'        => get_term_link( $term ),
            'name'        => $term->name,
            'slug'        => $term->slug,
            'taxonomy'    => $term->taxonomy,
            'parent'      => (int) $term->parent,
        ];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Clamp page / per_page like core REST collections (avoids WP_Query edge cases
     * when per_page is 0 or negative).
     *
     * @return int[] [ per_page, page ]
     */
    private function normalize_pagination( WP_REST_Request $request, int $default_per_page ): array {
        $per_raw = $request->get_param( 'per_page' );
        $page_raw = $request->get_param( 'page' );

        $per_page = absint( $per_raw );
        if ( $per_page < 1 ) {
            $per_page = $default_per_page;
        }
        $per_page = min( $per_page, 100 );

        $page = absint( $page_raw );
        if ( $page < 1 ) {
            $page = 1;
        }

        return [ $per_page, $page ];
    }

    /**
     * Registered CPT slugs (not post/page), including types that are not public or not in REST.
     */
    private function discover_cpt_slugs(): array {
        $slugs = array_values( array_diff( $this->get_extractable_post_type_slugs(), [ 'post', 'page' ] ) );
        sort( $slugs );
        return $slugs;
    }

    /** All registered post types suitable for extraction, minus internal/system slugs. */
    private function get_extractable_post_type_slugs(): array {
        $all = get_post_types( [], 'names' );
        return array_values( array_diff( $all, self::INTERNAL_POST_TYPES ) );
    }

    /**
     * @param mixed $raw Request status: omit / string / array (comma list or any).
     * @return string|array<string>
     */
    private function parse_post_status_param( mixed $raw ): string|array {
        if ( is_array( $raw ) ) {
            $parts = $raw;
        } else {
            $s = is_string( $raw ) ? trim( $raw ) : '';
            if ( $s === '' ) {
                return 'publish';
            }
            if ( strtolower( $s ) === 'any' ) {
                return 'any';
            }
            $parts = array_map( 'trim', explode( ',', $s ) );
        }

        $allowed = [];
        foreach ( (array) $parts as $p ) {
            $k = is_string( $p ) ? sanitize_key( $p ) : '';
            if ( $k !== '' && in_array( $k, self::QUERYABLE_STATUSES, true ) ) {
                $allowed[] = $k;
            }
        }

        if ( empty( $allowed ) ) {
            return 'publish';
        }

        return count( $allowed ) === 1 ? $allowed[0] : $allowed;
    }

    private function humanize( string $slug ): string {
        return ucwords( str_replace( [ '-', '_' ], ' ', $slug ) );
    }

    private function get_term_ids( int $post_id, string $taxonomy ): array {
        $terms = get_the_terms( $post_id, $taxonomy );
        if ( ! $terms || is_wp_error( $terms ) ) {
            return [];
        }
        return array_map( fn( $t ) => $t->term_id, $terms );
    }

    private function build_meta(): Post_Extractor_Meta {
        $opts = get_option( Post_Extractor_Settings::OPTION_KEY, [] );

        return new Post_Extractor_Meta(
            $this->parse_allowlist_tokens( (string) ( $opts['meta_keys_allowlist'] ?? '' ) ),
            $this->parse_allowlist_tokens( (string) ( $opts['acf_field_names_allowlist'] ?? '' ) )
        );
    }

    /**
     * One token per line or comma-separated. Only safe key characters allowed.
     *
     * @return string[]
     */
    private function parse_allowlist_tokens( string $raw ): array {
        $raw   = str_replace( [ "\r\n", "\r" ], "\n", $raw );
        $parts = preg_split( '/[\n,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $parts ) ) {
            return [];
        }

        $seen = [];
        foreach ( $parts as $p ) {
            $t = trim( (string) $p );
            if ( $t === '' || strlen( $t ) > 191 ) {
                continue;
            }
            if ( ! preg_match( '/^[a-zA-Z0-9_\-]+$/', $t ) ) {
                continue;
            }
            $seen[ $t ] = true;
        }

        return array_keys( $seen );
    }

    private function build_tax_query( mixed $param ): array {
        if ( empty( $param ) || ! is_array( $param ) ) {
            return [];
        }
        $q = [ 'relation' => 'AND' ];
        foreach ( $param as $taxonomy => $term ) {
            $q[] = [
                'taxonomy' => sanitize_key( $taxonomy ),
                'field'    => 'slug',
                'terms'    => array_map( 'sanitize_text_field', (array) $term ),
            ];
        }
        return $q;
    }

    // -------------------------------------------------------------------------
    // Citizen submissions (Post Extractor app → wp-admin)
    // -------------------------------------------------------------------------

    /**
     * Stricter per-IP cap for POST /submissions (in addition to check_permission’s window).
     */
    private function enforce_submission_create_rate_limit(): true|WP_Error {
        $options = get_option( Post_Extractor_Settings::OPTION_KEY, [] );
        $per_hr  = isset( $options['submission_create_per_hour'] ) ? (int) $options['submission_create_per_hour'] : 30;
        if ( $per_hr <= 0 ) {
            return true;
        }
        $per_hr = min( $per_hr, 500 );

        $ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
        $key = 'pe_cit_sub_hr_' . md5( $ip );

        $data = get_transient( $key );
        $now  = time();
        if ( ! is_array( $data ) || ! isset( $data['start'], $data['count'] ) || ( $now - (int) $data['start'] ) >= 3600 ) {
            $data = [ 'start' => $now, 'count' => 0 ];
        }

        ++$data['count'];
        set_transient( $key, $data, 3600 );

        if ( $data['count'] > $per_hr ) {
            return new WP_Error(
                'rest_too_many_requests',
                __( 'Too many story submissions. Try again later.', 'post-extractor' ),
                [ 'status' => 429 ]
            );
        }

        return true;
    }

    private function default_submission_author_id(): int {
        $admins = get_users(
            [
                'number'  => 1,
                'role'    => 'administrator',
                'fields'  => 'ID',
                'orderby' => 'ID',
                'order'   => 'ASC',
            ]
        );
        if ( ! empty( $admins ) && isset( $admins[0] ) && (int) $admins[0] > 0 ) {
            return (int) $admins[0];
        }
        $any = get_users( [ 'number' => 1, 'fields' => 'ID' ] );
        if ( ! empty( $any ) && isset( $any[0] ) && (int) $any[0] > 0 ) {
            return (int) $any[0];
        }
        return 1;
    }

    /**
     * @return string One of: pendingReview, verified, rejected (matches Dart SubmissionStatus.name).
     */
    private function map_citizen_app_status( WP_Post $post ): string {
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
                return 'pendingReview';
            }
        }
        $st = $post->post_status;
        if ( 'pending' === $st ) {
            return 'pendingReview';
        }
        if ( in_array( $st, [ 'publish', 'private', 'future' ], true ) ) {
            return 'verified';
        }
        if ( in_array( $st, [ 'trash' ], true ) ) {
            return 'rejected';
        }
        if ( in_array( $st, [ 'draft' ], true ) ) {
            return 'rejected';
        }
        // auto-draft, inherit, etc. — treat as pending until editor acts.
        return 'pendingReview';
    }

    /**
     * @return array<string, mixed>
     */
    private function build_citizen_submission_array( WP_Post $post ): array|WP_Error {
        if ( $post->post_type !== Post_Extractor_Citizen::POST_TYPE ) {
            return new WP_Error( 'rest_not_found', __( 'Submission not found.', 'post-extractor' ), [ 'status' => 404 ] );
        }

        $headline  = (string) get_the_title( $post->ID );
        $summary   = (string) $post->post_content;
        $location  = (string) get_post_meta( $post->ID, '_pe_location', true );
        $desk      = (string) get_post_meta( $post->ID, '_pe_routed_desk', true );
        $pub       = (string) get_post_meta( $post->ID, '_pe_publication', true );
        if ( $pub === '' ) {
            $pub = 'myAfrika';
        }
        $created   = (string) get_post_time( 'c', true, $post );
        if ( $created === '' ) {
            $created = gmdate( 'c' );
        }
        $app_id_meta = (int) get_post_meta( $post->ID, Post_Extractor_Citizen::META_CONTRIBUTOR_APP_ID, true );
        $src         = (string) get_post_meta( $post->ID, Post_Extractor_Citizen::META_SOURCE, true );

        $out = [
            'id'          => (int) $post->ID,
            'headline'    => $headline,
            'summary'     => $summary,
            'location'    => $location,
            'routedDesk'  => $desk !== '' ? $desk : 'General Desk',
            'publication' => $pub,
            'status'      => $this->map_citizen_app_status( $post ),
            'createdAt'   => $created,
            'source'      => $src !== '' ? $src : 'newsbepa',
        ];
        if ( $app_id_meta > 0 ) {
            $out['contributorApplicationId'] = $app_id_meta;
        }
        return $out;
    }

    public function create_citizen_submission( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $lim = $this->enforce_submission_create_rate_limit();
        if ( is_wp_error( $lim ) ) {
            return $lim;
        }

        $body = $request->get_json_params();
        if ( ! is_array( $body ) ) {
            $body = [];
        }

        $headline = isset( $body['headline'] ) ? sanitize_text_field( (string) $body['headline'] ) : '';
        if ( $headline === '' || strlen( $headline ) < 2 ) {
            return new WP_Error(
                'rest_invalid_param',
                __( 'A headline is required.', 'post-extractor' ),
                [ 'status' => 400 ]
            );
        }
        if ( strlen( $headline ) > 200 ) {
            $headline = (string) mb_substr( $headline, 0, 200 );
        }

        $summary = isset( $body['summary'] ) ? sanitize_textarea_field( (string) $body['summary'] ) : '';
        if ( $summary === '' || strlen( $summary ) < 2 ) {
            return new WP_Error(
                'rest_invalid_param',
                __( 'A story summary is required.', 'post-extractor' ),
                [ 'status' => 400 ]
            );
        }
        if ( strlen( $summary ) > 8000 ) {
            $summary = (string) mb_substr( $summary, 0, 8000 );
        }

        $location = isset( $body['location'] ) ? sanitize_text_field( (string) $body['location'] ) : '';
        if ( strlen( $location ) > 200 ) {
            $location = (string) mb_substr( $location, 0, 200 );
        }
        if ( $location === '' ) {
            return new WP_Error(
                'rest_invalid_param',
                __( 'Location is required.', 'post-extractor' ),
                [ 'status' => 400 ]
            );
        }

        $routed = isset( $body['routed_desk'] ) || isset( $body['routedDesk'] )
            ? ( isset( $body['routedDesk'] ) ? (string) $body['routedDesk'] : (string) ( $body['routed_desk'] ?? '' ) )
            : '';
        $routed  = sanitize_text_field( $routed );
        if ( strlen( $routed ) > 200 ) {
            $routed = (string) mb_substr( $routed, 0, 200 );
        }
        if ( $routed === '' ) {
            $routed = 'General Desk';
        }

        $raw_pub  = isset( $body['publication'] ) ? (string) $body['publication'] : 'myAfrika';
        $norm     = strtolower( str_replace( [ ' ', "\t" ], '', $raw_pub ) );
        $name_map = [
            'myafrika'         => 'myAfrika',
            'mykasi'           => 'myKasi',
            'mychitown'        => 'myChitown',
            'crossnetworktv'   => 'crossNetworkTV',
            'crosstv'          => 'crossNetworkTV',
        ];
        $pub_camel = $name_map[ $norm ] ?? 'myAfrika';

        $app_id = (int) $request->get_header( 'X-PE-Contributor-App-Id' );
        if ( $app_id < 1 ) {
            $h = (string) $request->get_header( 'x-pe-contributor-app-id' );
            if ( is_numeric( $h ) ) {
                $app_id = (int) $h;
            }
        }
        if ( $app_id < 1 && isset( $body['contributorApplicationId'] ) && is_numeric( (string) $body['contributorApplicationId'] ) ) {
            $app_id = (int) $body['contributorApplicationId'];
        }
        $app_check = $this->verify_approved_contributor_application( $app_id, $pub_camel );
        if ( is_wp_error( $app_check ) ) {
            return $app_check;
        }

        $post_id = wp_insert_post(
            [
                'post_type'    => Post_Extractor_Citizen::POST_TYPE,
                'post_status'  => 'pending',
                'post_title'   => $headline,
                'post_content' => $summary,
                'post_author'  => $this->default_submission_author_id(),
            ],
            true
        );

        if ( is_wp_error( $post_id ) ) {
            return new WP_Error(
                'rest_cant_create',
                $post_id->get_error_message(),
                [ 'status' => 500 ]
            );
        }

        update_post_meta( (int) $post_id, '_pe_location', $location );
        update_post_meta( (int) $post_id, '_pe_routed_desk', $routed );
        update_post_meta( (int) $post_id, '_pe_publication', $pub_camel );
        update_post_meta( (int) $post_id, Post_Extractor_Citizen::META_MODERATION, 'pending' );
        update_post_meta( (int) $post_id, Post_Extractor_Citizen::META_SOURCE, 'newsbepa' );
        if ( $app_id > 0 ) {
            update_post_meta( (int) $post_id, Post_Extractor_Citizen::META_CONTRIBUTOR_APP_ID, $app_id );
        }

        $post = get_post( (int) $post_id );
        if ( ! $post ) {
            return new WP_Error( 'rest_cant_create', __( 'Could not load submission.', 'post-extractor' ), [ 'status' => 500 ] );
        }

        $data = $this->build_citizen_submission_array( $post );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        $response = rest_ensure_response( $data );
        $response->set_status( 201 );
        return $response;
    }

    public function get_citizen_submission( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $id   = (int) $request->get_param( 'id' );
        $post = get_post( $id );
        if ( ! $post || in_array( $post->post_status, [ 'auto-draft' ], true ) ) {
            return new WP_Error( 'rest_not_found', __( 'Submission not found.', 'post-extractor' ), [ 'status' => 404 ] );
        }
        if ( (int) $post->ID !== $id ) {
            return new WP_Error( 'rest_not_found', __( 'Submission not found.', 'post-extractor' ), [ 'status' => 404 ] );
        }
        $data = $this->build_citizen_submission_array( $post );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        return rest_ensure_response( $data );
    }

    public function get_citizen_submissions_batch( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $raw = (string) $request->get_param( 'ids' );
        if ( $raw === '' ) {
            return new WP_Error(
                'rest_missing_param',
                __( 'The ids query parameter is required (comma-separated post IDs).', 'post-extractor' ),
                [ 'status' => 400 ]
            );
        }
        $parts = array_map( 'trim', explode( ',', $raw ) );
        $ids   = [];
        foreach ( $parts as $p ) {
            if ( $p === '' || ! is_numeric( $p ) ) {
                continue;
            }
            $ids[] = (int) $p;
        }
        $ids = array_values( array_unique( array_filter( $ids, fn( $n ) => $n > 0 ) ) );
        if ( empty( $ids ) ) {
            return new WP_Error(
                'rest_invalid_param',
                __( 'No valid numeric ids were provided.', 'post-extractor' ),
                [ 'status' => 400 ]
            );
        }
        if ( count( $ids ) > 50 ) {
            $ids = array_slice( $ids, 0, 50 );
        }

        $q = new WP_Query( [
            'post_type'              => Post_Extractor_Citizen::POST_TYPE,
            'post_status'            => 'any',
            'post__in'               => $ids,
            'orderby'                => 'post__in',
            'posts_per_page'         => count( $ids ),
            'no_found_rows'          => true,
            'update_post_meta_cache' => true,
        ] );

        $list = [];
        foreach ( $q->posts as $p ) {
            if ( ! ( $p instanceof WP_Post ) ) {
                continue;
            }
            $one = $this->build_citizen_submission_array( $p );
            if ( ! is_wp_error( $one ) ) {
                $list[] = $one;
            }
        }
        return rest_ensure_response( [ 'submissions' => $list ] );
    }

    // -------------------------------------------------------------------------
    // Contributor program applications
    // -------------------------------------------------------------------------

    private function map_publication_to_camel( string $raw ): string {
        $norm = strtolower( str_replace( [ ' ', "\t" ], '', $raw ) );
        $name_map = [
            'myafrika'         => 'myAfrika',
            'mykasi'           => 'myKasi',
            'mychitown'        => 'myChitown',
            'crossnetworktv'   => 'crossNetworkTV',
            'crosstv'          => 'crossNetworkTV',
        ];
        return $name_map[ $norm ] ?? 'myAfrika';
    }

    private function enforce_contributor_app_create_rate(): true|WP_Error {
        $ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
        $key = 'pe_contrib_app_hr_' . md5( $ip );
        $data = get_transient( $key );
        $now  = time();
        if ( ! is_array( $data ) || ! isset( $data['start'], $data['count'] ) || ( $now - (int) $data['start'] ) >= 3600 ) {
            $data = [ 'start' => $now, 'count' => 0 ];
        }
        ++$data['count'];
        set_transient( $key, $data, 3600 );
        if ( $data['count'] > 8 ) {
            return new WP_Error(
                'rest_too_many_requests',
                __( 'Too many application attempts. Try again in an hour.', 'post-extractor' ),
                [ 'status' => 429 ]
            );
        }
        return true;
    }

    /**
     * @return 'pending'|'approved'|'rejected'
     */
    private function map_contributor_application_status( WP_Post $post ): string {
        return Post_Extractor_Contributor_Moderation::map_status( $post );
    }

    private function build_contributor_application_array( WP_Post $post ): array|WP_Error {
        if ( $post->post_type !== Post_Extractor_Contributor_App::POST_TYPE ) {
            return new WP_Error( 'rest_not_found', __( 'Application not found.', 'post-extractor' ), [ 'status' => 404 ] );
        }
        $first  = (string) get_post_meta( $post->ID, Post_Extractor_Contributor_App::META_FIRST, true );
        $last   = (string) get_post_meta( $post->ID, Post_Extractor_Contributor_App::META_SURNAME, true );
        $name   = (string) get_post_meta( $post->ID, Post_Extractor_Contributor_App::META_FULL_NAME, true );
        if ( $name === '' && ( $first !== '' || $last !== '' ) ) {
            $name = trim( $first . ' ' . $last );
        }
        $email = (string) get_post_meta( $post->ID, Post_Extractor_Contributor_App::META_EMAIL, true );
        $phone = (string) get_post_meta( $post->ID, Post_Extractor_Contributor_App::META_PHONE, true );
        $loc   = (string) get_post_meta( $post->ID, Post_Extractor_Contributor_App::META_LOCATION, true );
        $pub   = (string) get_post_meta( $post->ID, Post_Extractor_Contributor_App::META_PUBLICATION, true );
        if ( $pub === '' ) {
            $pub = 'myAfrika';
        }
        $pjson = (string) get_post_meta( $post->ID, Post_Extractor_Contributor_App::META_PUBS_JSON, true );
        $pubs  = [];
        if ( $pjson !== '' ) {
            $dec = json_decode( $pjson, true );
            if ( is_array( $dec ) ) {
                $pubs = array_map( 'strval', $dec );
            }
        }
        $all_s = (int) get_post_meta( $post->ID, Post_Extractor_Contributor_App::META_ALL_SITES, true ) === 1;
        if ( $pubs === [] && $pjson === '' && $name !== '' ) {
            $pubs = [ $pub ];
        }
        $reason = (string) wp_strip_all_tags( (string) $post->post_content, true );
        $created = (string) get_post_time( 'c', true, $post );
        if ( $created === '' ) {
            $created = gmdate( 'c' );
        }
        $status  = $this->map_contributor_application_status( $post );
        $src   = (string) get_post_meta( $post->ID, Post_Extractor_Contributor_App::META_SOURCE, true );
        $rej   = (string) get_post_meta( $post->ID, Post_Extractor_Contributor_Moderation::META_REJECTION_REASON, true );
        $wpuid = (int) get_post_meta( $post->ID, Post_Extractor_Contributor_Moderation::META_LINKED_USER_ID, true );
        $list  = [
            'id'                 => (int) $post->ID,
            'name'               => $name,
            'firstName'          => $first,
            'surname'            => $last,
            'email'              => $email,
            'phone'              => $phone,
            'location'           => $loc,
            'reason'             => $reason,
            'publication'        => $pub,
            'publications'       => $pubs,
            'allPublications'    => $all_s,
            'status'             => $status,
            'submittedAt'        => $created,
            'source'             => $src !== '' ? $src : 'newsbepa',
            'rejectionReason'   => ( $status === 'rejected' && $rej !== '' ) ? $rej : null,
            'wordpressUserId'  => ( $status === 'approved' && $wpuid > 0 ) ? $wpuid : null,
        ];
        return $list;
    }

    public function create_contributor_application( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $lim = $this->enforce_contributor_app_create_rate();
        if ( is_wp_error( $lim ) ) {
            return $lim;
        }
        $body = $request->get_json_params();
        if ( ! is_array( $body ) ) {
            $body = [];
        }
        $name_one = isset( $body['name'] ) ? sanitize_text_field( (string) $body['name'] ) : '';
        $first    = isset( $body['firstName'] ) ? sanitize_text_field( (string) $body['firstName'] ) : '';
        $surname  = isset( $body['surname'] ) ? sanitize_text_field( (string) $body['surname'] ) : '';
        if ( $name_one === '' && ( $first !== '' || $surname !== '' ) ) {
            $name_one = trim( $first . ' ' . $surname );
        }
        if ( $name_one === '' || strlen( $name_one ) < 2 ) {
            return new WP_Error( 'rest_invalid_param', __( 'Your name is required.', 'post-extractor' ), [ 'status' => 400 ] );
        }
        if ( strlen( $name_one ) > 200 ) {
            $name_one = (string) mb_substr( $name_one, 0, 200 );
        }
        if ( $first === '' && $surname === '' ) {
            $name_parts = preg_split( '/\s+/', $name_one, 2, PREG_SPLIT_NO_EMPTY );
            $first  = (string) ( $name_parts[0] ?? '' );
            $surname = (string) ( $name_parts[1] ?? '' );
        }
        $email = isset( $body['email'] ) ? sanitize_email( (string) $body['email'] ) : '';
        if ( $email === '' || ! is_email( $email ) ) {
            return new WP_Error( 'rest_invalid_param', __( 'A valid email address is required.', 'post-extractor' ), [ 'status' => 400 ] );
        }
        $phone = isset( $body['phone'] ) ? preg_replace( '/[^0-9+()\-\s.]/', '', (string) $body['phone'] ) : '';
        if ( $phone === '' || strlen( $phone ) < 5 ) {
            return new WP_Error( 'rest_invalid_param', __( 'A valid phone number is required.', 'post-extractor' ), [ 'status' => 400 ] );
        }
        if ( strlen( $phone ) > 50 ) {
            $phone = (string) mb_substr( $phone, 0, 50 );
        }
        $location = isset( $body['location'] ) ? sanitize_text_field( (string) $body['location'] ) : '';
        if ( $location === '' || strlen( $location ) < 2 ) {
            return new WP_Error( 'rest_invalid_param', __( 'Location is required.', 'post-extractor' ), [ 'status' => 400 ] );
        }
        if ( strlen( $location ) > 200 ) {
            $location = (string) mb_substr( $location, 0, 200 );
        }
        $raw_reason = isset( $body['reason'] ) ? (string) $body['reason'] : ( isset( $body['whyJoin'] ) ? (string) $body['whyJoin'] : '' );
        if ( is_string( $raw_reason ) ) {
            $raw_reason = wp_strip_all_tags( $raw_reason, true );
        } else {
            $raw_reason = '';
        }
        if ( $raw_reason === '' || strlen( $raw_reason ) < 20 ) {
            return new WP_Error( 'rest_invalid_param', __( 'Please explain why you want to join (at least 20 characters).', 'post-extractor' ), [ 'status' => 400 ] );
        }
        if ( strlen( $raw_reason ) > 5000 ) {
            $raw_reason = (string) mb_substr( $raw_reason, 0, 5000 );
        }
        $all_sites = filter_var( $body['allPublications'] ?? false, FILTER_VALIDATE_BOOLEAN );
        $pubs_list = $body['publications'] ?? $body['publicationSlugs'] ?? null;
        $out_pubs  = [];
        if ( is_array( $pubs_list ) ) {
            foreach ( $pubs_list as $one ) {
                if ( is_string( $one ) && $one !== '' ) {
                    $out_pubs[] = $this->map_publication_to_camel( (string) $one );
                }
            }
        }
        if ( $all_sites && $out_pubs === [] ) {
            $out_pubs = [ 'myAfrika', 'myKasi', 'myChitown', 'crossNetworkTV' ];
        }
        $out_pubs = array_values( array_unique( $out_pubs ) );
        if ( $out_pubs === [] ) {
            return new WP_Error( 'rest_invalid_param', __( 'Select at least one publication, or all.', 'post-extractor' ), [ 'status' => 400 ] );
        }
        $raw_pub  = isset( $body['publication'] ) ? (string) $body['publication'] : ( $out_pubs[0] ?? 'myAfrika' );
        $pub_camel = $this->map_publication_to_camel( $raw_pub );
        if ( ! in_array( $pub_camel, $out_pubs, true ) ) {
            return new WP_Error( 'rest_invalid_param', __( 'The application endpoint must be one of the selected publications.', 'post-extractor' ), [ 'status' => 400 ] );
        }

        $pjson = wp_json_encode( $out_pubs, JSON_UNESCAPED_SLASHES );
        if ( $pjson === false ) {
            $pjson = '[]';
        }

        $title = trim( $name_one . ' — ' . $email );
        if ( strlen( $title ) > 200 ) {
            $title = (string) mb_substr( $title, 0, 197 ) . '…';
        }
        $post_id = wp_insert_post(
            [
                'post_type'   => Post_Extractor_Contributor_App::POST_TYPE,
                'post_status' => 'pending',
                'post_title'  => $title,
                'post_content'=> $raw_reason,
                'post_author' => $this->default_submission_author_id(),
            ],
            true
        );
        if ( is_wp_error( $post_id ) ) {
            return new WP_Error( 'rest_cant_create', $post_id->get_error_message(), [ 'status' => 500 ] );
        }
        $id = (int) $post_id;
        update_post_meta( $id, Post_Extractor_Contributor_App::META_FULL_NAME, $name_one );
        update_post_meta( $id, Post_Extractor_Contributor_App::META_FIRST, $first );
        update_post_meta( $id, Post_Extractor_Contributor_App::META_SURNAME, $surname );
        update_post_meta( $id, Post_Extractor_Contributor_App::META_EMAIL, $email );
        update_post_meta( $id, Post_Extractor_Contributor_App::META_PHONE, $phone );
        update_post_meta( $id, Post_Extractor_Contributor_App::META_LOCATION, $location );
        update_post_meta( $id, Post_Extractor_Contributor_App::META_PUBLICATION, $pub_camel );
        update_post_meta( $id, Post_Extractor_Contributor_App::META_PUBS_JSON, $pjson );
        update_post_meta( $id, Post_Extractor_Contributor_App::META_ALL_SITES, $all_sites ? 1 : 0 );
        update_post_meta( $id, Post_Extractor_Contributor_App::META_MODERATION, 'pending' );
        update_post_meta( $id, Post_Extractor_Contributor_App::META_SOURCE, 'newsbepa' );

        $post = get_post( $id );
        if ( ! $post ) {
            return new WP_Error( 'rest_cant_create', __( 'Could not load application.', 'post-extractor' ), [ 'status' => 500 ] );
        }
        $data = $this->build_contributor_application_array( $post );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        $res = rest_ensure_response( $data );
        $res->set_status( 201 );
        return $res;
    }

    public function get_contributor_application( WP_REST_Request $req ): WP_REST_Response|WP_Error {
        $id   = (int) $req->get_param( 'id' );
        $post = get_post( $id );
        if ( ! $post || in_array( $post->post_status, [ 'auto-draft' ], true ) ) {
            return new WP_Error( 'rest_not_found', __( 'Application not found.', 'post-extractor' ), [ 'status' => 404 ] );
        }
        if ( (int) $post->ID !== $id ) {
            return new WP_Error( 'rest_not_found', __( 'Application not found.', 'post-extractor' ), [ 'status' => 404 ] );
        }
        $data = $this->build_contributor_application_array( $post );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        return rest_ensure_response( $data );
    }

    /**
     * @param int    $app_id  Contributor application post id on this site.
     * @param string $pub_camel Expected Publication.name value (e.g. myKasi).
     */
    private function verify_approved_contributor_application( int $app_id, string $pub_camel ): true|WP_Error {
        if ( $app_id < 1 ) {
            return new WP_Error(
                'pe_no_contributor_app',
                __( 'A contributor program application is required. Apply in the app, then wait for editor approval before submitting a story.', 'post-extractor' ),
                [ 'status' => 403 ]
            );
        }
        $p = get_post( $app_id );
        if ( ! $p || $p->post_type !== Post_Extractor_Contributor_App::POST_TYPE ) {
            return new WP_Error( 'pe_invalid_contributor_app', __( 'Contributor account not found on this site.', 'post-extractor' ), [ 'status' => 403 ] );
        }
        if ( in_array( $p->post_status, [ 'trash' ], true ) ) {
            return new WP_Error( 'pe_contributor_app_inactive', __( 'This contributor application is not active.', 'post-extractor' ), [ 'status' => 403 ] );
        }
        if ( 'approved' !== $this->map_contributor_application_status( $p ) ) {
            return new WP_Error(
                'pe_contributor_not_approved',
                __( 'Your account is not approved to submit stories on this site yet, or the application was rejected. Wait for the editorial team, or re-apply in the app.', 'post-extractor' ),
                [ 'status' => 403 ]
            );
        }
        $app_pub = (string) get_post_meta( $p->ID, Post_Extractor_Contributor_App::META_PUBLICATION, true );
        if ( $app_pub === '' ) {
            $app_pub = 'myAfrika';
        }
        if ( $app_pub !== $pub_camel ) {
            return new WP_Error( 'pe_app_site_mismatch', __( 'The story’s target must match the site you applied to join.', 'post-extractor' ), [ 'status' => 403 ] );
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // NewsBEFA editorial preview (plugin-stored login; not WordPress user accounts)
    // -------------------------------------------------------------------------

    private const EDITORIAL_TOKEN_TTL = 28800; // 8 hours in seconds

    private function get_editorial_token_from_request( WP_REST_Request $request ): string {
        $h = (string) $request->get_header( 'X-PE-Editorial-Token' );
        if ( $h === '' ) {
            $h = (string) $request->get_header( 'x-pe-editorial-token' );
        }
        if ( $h === '' && $request->get_param( 'token' ) ) {
            $h = (string) $request->get_param( 'token' );
        }
        $h = trim( $h );
        if ( strlen( $h ) > 128 ) {
            $h = (string) mb_substr( $h, 0, 128 );
        }
        return $h;
    }

    private function editorial_transient_key( string $token ): string {
        return 'pe_nbedt_' . hash( 'sha256', $token );
    }

    private function editorial_client_ip(): string {
        if ( function_exists( 'rest_get_client_ip' ) ) {
            $ip = (string) rest_get_client_ip();
            if ( $ip !== '' ) {
                return $ip;
            }
        }
        return (string) ( $_SERVER['REMOTE_ADDR'] ?? '0' );
    }

    public function check_editorial( WP_REST_Request $request ): bool|WP_Error {
        $t = $this->get_editorial_token_from_request( $request );
        if ( $t === '' || strlen( $t ) < 32 ) {
            return new WP_Error( 'pe_editorial_auth', __( 'Editorial session required.', 'post-extractor' ), [ 'status' => 401 ] );
        }
        if ( get_transient( $this->editorial_transient_key( $t ) ) !== '1' ) {
            return new WP_Error( 'pe_editorial_auth', __( 'Invalid or expired session.', 'post-extractor' ), [ 'status' => 401 ] );
        }
        return true;
    }

    public function post_editorial_login( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ip     = $this->editorial_client_ip();
        $rkey   = 'pe_edul_' . md5( $ip );
        $n      = (int) get_transient( $rkey );
        if ( $n > 20 ) {
            return new WP_Error(
                'rest_too_many_requests',
                __( 'Too many login attempts. Try again later.', 'post-extractor' ),
                [ 'status' => 429 ]
            );
        }
        set_transient( $rkey, $n + 1, 15 * MINUTE_IN_SECONDS );

        $body = $request->get_json_params();
        if ( ! is_array( $body ) ) {
            $body = [];
        }
        $u = isset( $body['username'] ) ? sanitize_user( (string) $body['username'], true ) : '';
        $p = isset( $body['password'] ) ? (string) $body['password'] : '';
        if ( $u === '' || $p === '' ) {
            return new WP_Error( 'pe_editorial_invalid', __( 'Username and password are required.', 'post-extractor' ), [ 'status' => 400 ] );
        }
        $opt   = get_option( Post_Extractor_Settings::OPTION_KEY, [] );
        $ok_u  = (string) ( $opt['newsbepa_editorial_user'] ?? '' );
        $hash  = (string) ( $opt['newsbepa_editorial_hash'] ?? '' );
        if ( $ok_u === '' || $hash === '' ) {
            return new WP_Error(
                'pe_editorial_unconfigured',
                __( 'Editorial login is not configured for this site.', 'post-extractor' ),
                [ 'status' => 503 ]
            );
        }
        if ( $ok_u !== $u || ! wp_check_password( $p, $hash ) ) {
            return new WP_Error( 'pe_editorial_invalid', __( 'Invalid credentials.', 'post-extractor' ), [ 'status' => 401 ] );
        }
        $token = bin2hex( random_bytes( 32 ) );
        $ttl = (int) apply_filters( 'post_extractor_editorial_token_ttl', self::EDITORIAL_TOKEN_TTL );
        if ( $ttl < 300 ) {
            $ttl = 300;
        }
        if ( $ttl > 7 * DAY_IN_SECONDS ) {
            $ttl = 7 * DAY_IN_SECONDS;
        }
        set_transient( $this->editorial_transient_key( $token ), '1', $ttl );
        // Successful login: reset per-IP counter.
        delete_transient( $rkey );
        $payload = [
            'token'     => $token,
            'expiresIn' => $ttl,
            'siteName'  => (string) get_bloginfo( 'name', 'raw' ),
            'siteUrl'   => (string) home_url( '/' ),
        ];
        return rest_ensure_response( $payload );
    }

    public function post_editorial_logout( WP_REST_Request $request ): WP_REST_Response {
        $t = $this->get_editorial_token_from_request( $request );
        if ( $t !== '' ) {
            delete_transient( $this->editorial_transient_key( $t ) );
        }
        return rest_ensure_response( [ 'ok' => true ] );
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, perPage: int, totalPages: int, siteName: string, siteUrl: string}
     */
    private function run_editorial_paginated_cpt( string $post_type, callable $row_builder, int $page, int $per_page ): array {
        if ( $page < 1 ) {
            $page = 1;
        }
        if ( $per_page < 1 ) {
            $per_page = 30;
        }
        if ( $per_page > 100 ) {
            $per_page = 100;
        }
        $q = new WP_Query(
            [
                'post_type'              => $post_type,
                'post_status'            => 'any',
                'posts_per_page'         => $per_page,
                'paged'                  => $page,
                'orderby'                => 'date',
                'order'                  => 'DESC',
                'no_found_rows'          => false,
                'update_post_meta_cache' => true,
                'update_post_term_cache' => false,
            ]
        );
        $items = [];
        foreach ( $q->posts as $post ) {
            if ( ! ( $post instanceof WP_Post ) || $post->post_type !== $post_type ) {
                continue;
            }
            if ( in_array( $post->post_status, [ 'auto-draft' ], true ) ) {
                continue;
            }
            if ( in_array( $post->post_status, [ 'trash' ], true ) ) {
                continue;
            }
            $one = $row_builder( $post );
            if ( is_wp_error( $one ) ) {
                continue;
            }
            if ( is_array( $one ) ) {
                $one['wpEditUrl'] = (string) admin_url( 'post.php?post=' . (int) $post->ID . '&action=edit' );
            }
            $items[]         = $one;
        }
        $total   = (int) $q->found_posts;
        $pages   = (int) max( 1, (int) ceil( $total / $per_page ) );
        $site    = (string) get_bloginfo( 'name', 'raw' );
        $home    = (string) home_url( '/' );
        return [
            'items'      => $items,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $per_page,
            'totalPages' => $pages,
            'siteName'   => $site,
            'siteUrl'    => $home,
        ];
    }

    public function get_editorial_contributor_list( WP_REST_Request $req ): WP_REST_Response {
        $page     = (int) $req->get_param( 'page' );
        $per_page = (int) $req->get_param( 'per_page' );
        $out      = $this->run_editorial_paginated_cpt(
            Post_Extractor_Contributor_App::POST_TYPE,
            function ( WP_Post $post ) {
                return $this->build_contributor_application_array( $post );
            },
            $page,
            $per_page
        );
        return rest_ensure_response( $out );
    }

    public function post_editorial_contributor_approve( WP_REST_Request $req ): WP_REST_Response|WP_Error {
        $id = (int) $req->get_param( 'id' );
        $ok = Post_Extractor_Contributor_Moderation::approve( $id, 'rest' );
        if ( is_wp_error( $ok ) ) {
            return $this->contributor_moderation_to_rest_error( $ok );
        }
        $post = get_post( $id );
        if ( ! $post || $post->post_type !== Post_Extractor_Contributor_App::POST_TYPE ) {
            return new WP_Error( 'rest_not_found', __( 'Application not found.', 'post-extractor' ), [ 'status' => 404 ] );
        }
        $data = $this->build_contributor_application_array( $post );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        return rest_ensure_response( $data );
    }

    public function post_editorial_contributor_reject( WP_REST_Request $req ): WP_REST_Response|WP_Error {
        $id     = (int) $req->get_param( 'id' );
        $body   = $req->get_json_params();
        $reason = '';
        if ( is_array( $body ) && array_key_exists( 'reason', $body ) && $body['reason'] !== null ) {
            $reason = (string) $body['reason'];
        } else {
            $p = $req->get_param( 'reason' );
            if ( $p !== null && $p !== '' ) {
                $reason = (string) $p;
            }
        }
        $ok = Post_Extractor_Contributor_Moderation::reject( $id, $reason, 'rest' );
        if ( is_wp_error( $ok ) ) {
            return $this->contributor_moderation_to_rest_error( $ok );
        }
        $post = get_post( $id );
        if ( ! $post || $post->post_type !== Post_Extractor_Contributor_App::POST_TYPE ) {
            return new WP_Error( 'rest_not_found', __( 'Application not found.', 'post-extractor' ), [ 'status' => 404 ] );
        }
        $data = $this->build_contributor_application_array( $post );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        return rest_ensure_response( $data );
    }

    private function contributor_moderation_to_rest_error( WP_Error $e ): WP_Error {
        $code   = $e->get_error_code();
        $status = 400;
        if ( in_array( $code, [ 'pe_not_found' ], true ) ) {
            $status = 404;
        } elseif ( 'pe_inactive' === $code ) {
            $status = 410;
        } elseif ( 'pe_reject_reason' === $code || 'pe_already_approved' === $code || 'pe_already_rejected' === $code || 'pe_app_bad_email' === $code ) {
            $status = 400;
        }
        return new WP_Error( $code, $e->get_error_message(), [ 'status' => $status ] );
    }

    public function get_editorial_citizen_list( WP_REST_Request $req ): WP_REST_Response {
        $page     = (int) $req->get_param( 'page' );
        $per_page = (int) $req->get_param( 'per_page' );
        $out      = $this->run_editorial_paginated_cpt(
            Post_Extractor_Citizen::POST_TYPE,
            function ( WP_Post $post ) {
                return $this->build_citizen_submission_array( $post );
            },
            $page,
            $per_page
        );
        return rest_ensure_response( $out );
    }

    private function posts_args(): array {
        return [
            'page'       => [
                'default'           => 1,
                'minimum'           => 1,
                'sanitize_callback' => 'absint',
            ],
            'per_page'   => [
                'default'           => 10,
                'minimum'           => 1,
                'maximum'           => 100,
                'sanitize_callback' => 'absint',
            ],
            'post_type'  => [ 'default' => null ],
            'search'     => [ 'default' => '' ],
            'status'     => [ 'default' => 'publish' ],
            'orderby'    => [ 'default' => 'date' ],
            'order'      => [ 'default' => 'DESC' ],
            'categories' => [ 'default' => null,       'sanitize_callback' => 'absint' ],
            'sticky'     => [ 'default' => null ],
            'tax'        => [ 'default' => null ],
            '_embed'     => [ 'default' => false ],
        ];
    }
}
