<?php
/**
 * Gutenberg block parser — converts raw post_content into structured sections.
 *
 * Each "section" maps to one Gutenberg block and contains:
 *   - type        : block name  (e.g. "core/paragraph", "core/image")
 *   - html        : rendered HTML for that block
 *   - attrs       : block attributes (e.g. textAlign, level, url …)
 *   - inner_text  : plain-text strip of the html (handy for search/indexing)
 *   - children    : nested inner blocks (recursive)
 *   - order       : position index in the post
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Post_Extractor_Blocks {

    /**
     * Parse post_content and return an array of section objects.
     *
     * @param string $post_content Raw post content (may contain block comments).
     * @return array<int, array<string, mixed>>
     */
    public function parse( string $post_content ): array {
        // WordPress ships parse_blocks() since 5.0.
        if ( ! function_exists( 'parse_blocks' ) ) {
            return $this->fallback_parse( $post_content );
        }

        $blocks   = parse_blocks( $post_content );
        $sections = [];
        $order    = 0;

        foreach ( $blocks as $block ) {
            $section = $this->format_block( $block, $order );

            // Skip empty classic-editor freeform blocks with no meaningful content.
            if ( $section['type'] === 'core/freeform' && trim( strip_tags( $section['html'] ) ) === '' ) {
                continue;
            }

            $sections[] = $section;
            $order++;
        }

        return $sections;
    }

    // ── Private ────────────────────────────────────────────────────────────

    /**
     * Recursively format a single parsed block.
     */
    private function format_block( array $block, int $order ): array {
        $type  = $block['blockName'] ?? 'core/freeform';
        $attrs = $block['attrs']     ?? [];
        $html  = isset( $block['innerHTML'] ) ? trim( $block['innerHTML'] ) : '';

        // For blocks that render via save(), apply_filters gives the full output.
        // For dynamic blocks we render via render_block().
        $rendered_html = $this->render_block( $block );

        $section = [
            'order'      => $order,
            'type'       => $type,
            'label'      => $this->human_label( $type ),
            'html'       => $rendered_html,
            'inner_text' => wp_strip_all_tags( $rendered_html ),
            'attrs'      => $this->sanitize_attrs( $attrs, $type ),
            'children'   => [],
        ];

        // Recurse into innerBlocks.
        if ( ! empty( $block['innerBlocks'] ) ) {
            $child_order = 0;
            foreach ( $block['innerBlocks'] as $inner ) {
                $section['children'][] = $this->format_block( $inner, $child_order++ );
            }
        }

        return $section;
    }

    /**
     * Render a block to HTML.
     * render_block() handles both static and dynamic (PHP) blocks.
     */
    private function render_block( array $block ): string {
        if ( function_exists( 'render_block' ) ) {
            try {
                return trim( (string) render_block( $block ) );
            } catch ( \Throwable $e ) {
                // Broken block render callbacks (third-party blocks) must not take down REST/API responses.
                return trim( (string) ( $block['innerHTML'] ?? '' ) );
            }
        }

        // Pre-5.5 fallback.
        return trim( (string) ( $block['innerHTML'] ?? '' ) );
    }

    /**
     * Enrich block attrs with type-specific extras (image URLs, link targets …).
     */
    private function sanitize_attrs( array $attrs, string $block_type ): array {
        switch ( $block_type ) {

            case 'core/image':
                if ( ! empty( $attrs['id'] ) ) {
                    $src = wp_get_attachment_image_src( $attrs['id'], 'full' );
                    if ( $src ) {
                        $attrs['src']    = $src[0];
                        $attrs['width']  = $src[1];
                        $attrs['height'] = $src[2];
                        $attrs['alt']    = get_post_meta( $attrs['id'], '_wp_attachment_image_alt', true );
                    }
                }
                break;

            case 'core/gallery':
                if ( ! empty( $attrs['ids'] ) && is_array( $attrs['ids'] ) ) {
                    $attrs['images'] = array_map( function ( $img_id ) {
                        $src = wp_get_attachment_image_src( $img_id, 'large' );
                        return [
                            'id'  => $img_id,
                            'url' => $src ? $src[0] : '',
                            'alt' => get_post_meta( $img_id, '_wp_attachment_image_alt', true ),
                        ];
                    }, $attrs['ids'] );
                }
                break;

            case 'core/video':
            case 'core/audio':
                if ( ! empty( $attrs['id'] ) ) {
                    $attrs['src'] = wp_get_attachment_url( $attrs['id'] );
                }
                break;

            case 'core/buttons':
            case 'core/button':
                // Ensure URL is present.
                if ( ! isset( $attrs['url'] ) ) {
                    $attrs['url'] = '';
                }
                break;
        }

        return $attrs;
    }

    /**
     * Convert "core/heading" → "Heading", "acme/hero-banner" → "Hero Banner" etc.
     */
    private function human_label( string $block_name ): string {
        $parts = explode( '/', $block_name, 2 );
        $slug  = end( $parts );
        return ucwords( str_replace( '-', ' ', $slug ) );
    }

    /**
     * Fallback for sites without parse_blocks() (WP < 5.0 — extremely rare).
     * Splits on HTML block comments and returns simple sections.
     */
    private function fallback_parse( string $content ): array {
        // Strip block comment delimiters and treat the whole content as one section.
        $clean = preg_replace( '/<!--\s*wp:[^>]+-->/', '', $content );
        $clean = preg_replace( '/<!--\s*\/wp:[^>]+-->/', '', $clean );
        $clean = trim( $clean );

        return [ [
            'order'      => 0,
            'type'       => 'core/freeform',
            'label'      => 'Content',
            'html'       => apply_filters( 'the_content', $clean ),
            'inner_text' => wp_strip_all_tags( $clean ),
            'attrs'      => [],
            'children'   => [],
        ] ];
    }
}
