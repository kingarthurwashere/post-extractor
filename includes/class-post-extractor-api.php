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
    }

    // =========================================================================
    // Permission
    // =========================================================================

    public function check_permission( WP_REST_Request $request ): bool|WP_Error {
        $options       = get_option( 'post_extractor_settings', [] );
        $api_key       = isset( $options['api_key'] ) ? (string) $options['api_key'] : '';
        // Default on when unset (matches settings UI).
        $require_auth  = isset( $options['require_auth'] ) ? (int) $options['require_auth'] : 1;

        if ( ! $require_auth ) {
            return true;
        }

        if ( current_user_can( 'edit_posts' ) ) {
            return true;
        }

        if ( $api_key === '' ) {
            return new WP_Error(
                'rest_forbidden',
                __( 'Authentication required.', 'post-extractor' ),
                [ 'status' => 401 ]
            );
        }

        $provided = $request->get_header( 'X-PE-API-Key' )
                    ?? $request->get_param( 'api_key' )
                    ?? '';

        if ( hash_equals( $api_key, (string) $provided ) ) {
            return true;
        }

        return new WP_Error(
            'rest_forbidden',
            __( 'Invalid or missing API key.', 'post-extractor' ),
            [ 'status' => 401 ]
        );
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

        $meta = new Post_Extractor_Meta();

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
            'api_key'    => [ 'default' => '' ],
        ];
    }
}
