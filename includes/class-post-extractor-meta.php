<?php
/**
 * Meta & ACF helper for Post Extractor.
 *
 * Retrieves:
 *   1. All public custom fields (post meta) — excluding WordPress-internal keys.
 *   2. ACF (Advanced Custom Fields) field groups and their values, if ACF is active.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Post_Extractor_Meta {

    /**
     * @param string[] $meta_key_allowlist   Exact post meta keys allowed in API output. Empty = expose none.
     * @param string[] $acf_field_allowlist  Top-level ACF field names allowed. Empty = expose none.
     */
    public function __construct(
        private array $meta_key_allowlist = [],
        private array $acf_field_allowlist = [],
    ) {}

    /**
     * Internal meta key prefixes / patterns to skip.
     * Extend this list if you have other internal prefixes to hide.
     */
    private const SKIP_PREFIXES = [
        '_edit_',
        '_encloseme',
        '_pingme',
        '_wp_',
        '_yoast_',
        '_rank_math_',
        '_aioseop_',
    ];

    /**
     * Get filtered post meta (public custom fields).
     *
     * @param int $post_id
     * @return array<string, mixed>
     */
    public function get_meta( int $post_id ): array {
        if ( empty( $this->meta_key_allowlist ) ) {
            return [];
        }

        $allowed = array_flip( $this->meta_key_allowlist );
        $raw     = get_post_meta( $post_id );
        $result  = [];

        foreach ( $raw as $key => $values ) {
            if ( ! isset( $allowed[ $key ] ) || $this->is_internal_key( $key ) ) {
                continue;
            }

            // Unserialize and unwrap single-value arrays.
            $processed = array_map( fn( $v ) => maybe_unserialize( $v ), $values );
            $result[ $key ] = count( $processed ) === 1 ? $processed[0] : $processed;
        }

        return $result;
    }

    /**
     * Get ACF field groups & field values for a post.
     * Returns an empty array if ACF is not active.
     *
     * @param int $post_id
     * @return array<string, mixed>
     */
    public function get_acf( int $post_id ): array {
        if ( empty( $this->acf_field_allowlist ) ) {
            return [];
        }

        // ACF Pro / ACF Free both expose get_fields() and get_field_groups().
        if ( ! function_exists( 'get_fields' ) ) {
            return [];
        }

        $allowed_flip = array_flip( $this->acf_field_allowlist );
        $field_values = get_fields( $post_id );
        if ( empty( $field_values ) || ! is_array( $field_values ) ) {
            return [];
        }

        $filtered_values = [];
        foreach ( $field_values as $name => $value ) {
            if ( is_string( $name ) && isset( $allowed_flip[ $name ] ) ) {
                $filtered_values[ $name ] = $value;
            }
        }

        if ( empty( $filtered_values ) ) {
            return [];
        }

        $groups = $this->get_acf_field_groups_filtered( $post_id, $allowed_flip );

        return [
            'groups' => $groups,
            'values' => $filtered_values,
        ];
    }

    // ── Private ────────────────────────────────────────────────────────────

    /**
     * Return ACF field groups applicable to a post (only allowlisted fields).
     *
     * @param array<string, true> $allowed_flip
     */
    private function get_acf_field_groups_filtered( int $post_id, array $allowed_flip ): array {
        if ( ! function_exists( 'acf_get_field_groups' ) ) {
            return [];
        }

        $post = get_post( $post_id );
        $groups_raw = acf_get_field_groups( [
            'post_id'   => $post_id,
            'post_type' => ( $post instanceof WP_Post ) ? $post->post_type : '',
        ] );

        $groups = [];
        foreach ( $groups_raw as $group ) {
            $fields = function_exists( 'acf_get_fields' )
                ? acf_get_fields( $group['key'] )
                : [];

            $fields = $fields ?: [];
            $fields = array_values( array_filter(
                $fields,
                static function ( $field ) use ( $allowed_flip ): bool {
                    $name = isset( $field['name'] ) && is_string( $field['name'] ) ? $field['name'] : '';

                    return $name !== '' && isset( $allowed_flip[ $name ] );
                }
            ) );

            if ( empty( $fields ) ) {
                continue;
            }

            $groups[] = [
                'key'    => $group['key'],
                'title'  => $group['title'],
                'fields' => $this->format_acf_fields( $fields ),
            ];
        }

        return $groups;
    }

    /**
     * Recursively map ACF field definitions to a clean structure.
     *
     * @param array $fields
     * @return array
     */
    private function format_acf_fields( array $fields ): array {
        return array_map( function ( $field ) {
            $mapped = [
                'key'   => $field['key']   ?? '',
                'name'  => $field['name']  ?? '',
                'label' => $field['label'] ?? '',
                'type'  => $field['type']  ?? 'text',
            ];

            // Sub-fields (repeater, flexible content, group).
            if ( ! empty( $field['sub_fields'] ) ) {
                $mapped['sub_fields'] = $this->format_acf_fields( $field['sub_fields'] );
            }

            // Flexible content layouts.
            if ( ! empty( $field['layouts'] ) ) {
                $mapped['layouts'] = array_map( function ( $layout ) {
                    return [
                        'key'        => $layout['key']   ?? '',
                        'name'       => $layout['name']  ?? '',
                        'label'      => $layout['label'] ?? '',
                        'sub_fields' => $this->format_acf_fields( $layout['sub_fields'] ?? [] ),
                    ];
                }, $field['layouts'] );
            }

            return $mapped;
        }, $fields );
    }

    /**
     * Return true if a meta key should be excluded from the public output.
     *
     * @param string $key
     * @return bool
     */
    private function is_internal_key( string $key ): bool {
        foreach ( self::SKIP_PREFIXES as $prefix ) {
            if ( str_starts_with( $key, $prefix ) ) {
                return true;
            }
        }

        return false;
    }
}
