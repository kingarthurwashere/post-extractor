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
        $raw    = get_post_meta( $post_id );
        $result = [];

        foreach ( $raw as $key => $values ) {
            if ( $this->is_internal_key( $key ) ) {
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
        // ACF Pro / ACF Free both expose get_fields() and get_field_groups().
        if ( ! function_exists( 'get_fields' ) ) {
            return [];
        }

        $field_values = get_fields( $post_id );
        if ( empty( $field_values ) || ! is_array( $field_values ) ) {
            return [];
        }

        // Enrich with field labels & types from registered field groups.
        $groups = $this->get_acf_field_groups( $post_id );

        return [
            'groups' => $groups,
            'values' => $field_values,
        ];
    }

    // ── Private ────────────────────────────────────────────────────────────

    /**
     * Return ACF field groups applicable to a post, with field meta.
     */
    private function get_acf_field_groups( int $post_id ): array {
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

            $groups[] = [
                'key'    => $group['key'],
                'title'  => $group['title'],
                'fields' => $this->format_acf_fields( $fields ?: [] ),
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
