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
