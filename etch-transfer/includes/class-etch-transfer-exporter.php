<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Etch_Transfer_Exporter {

    /**
     * Export a single page/CPT post by ID.
     */
    public static function export( $post_id ) {
        global $wpdb;

        if ( $post_id <= 0 ) {
            return new WP_Error( 'invalid_id', 'Invalid post ID.' );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'not_found', "No post found with ID {$post_id}." );
        }

        $meta        = self::get_post_meta( $post_id );
        $style_ids   = self::extract_style_ids( $post->post_content );
        $page_styles = self::get_styles_for_ids( $style_ids );

        return [
            'etch_export_version' => '1.0',
            'exported_at'         => current_time( 'mysql' ),
            'source_site'         => get_bloginfo( 'url' ),
            'source_post_id'      => $post_id,
            'source_post_type'    => $post->post_type,
            'source_post_name'    => $post->post_name,
            'post_title'          => $post->post_title,
            'post_status'         => $post->post_status,
            'post_content'        => $post->post_content,
            'meta'                => $meta,
            'etch_styles'         => $page_styles,
            'style_ids_found'     => $style_ids,
        ];
    }

    /**
     * Export multiple items (templates, patterns, components) by ID.
     * Returns a multi-export bundle.
     */
    public static function export_multi( $ids ) {
        if ( empty( $ids ) || ! is_array( $ids ) ) {
            return new WP_Error( 'no_ids', 'No items selected for export.' );
        }

        $items       = [];
        $all_styles  = [];
        $errors      = [];

        foreach ( $ids as $post_id ) {
            $post_id = intval( $post_id );
            $post    = get_post( $post_id );

            if ( ! $post ) {
                $errors[] = "Post ID {$post_id} not found.";
                continue;
            }

            $meta      = self::get_post_meta( $post_id );
            $style_ids = self::extract_style_ids( $post->post_content );
            $styles    = self::get_styles_for_ids( $style_ids );

            // Merge styles into global bundle
            foreach ( $styles as $id => $style ) {
                $all_styles[ $id ] = $style;
            }

            $items[] = [
                'source_post_id'   => $post_id,
                'source_post_type' => $post->post_type,
                'source_post_name' => $post->post_name,
                'post_title'       => $post->post_title,
                'post_status'      => $post->post_status,
                'post_content'     => $post->post_content,
                'meta'             => $meta,
                'style_ids_found'  => $style_ids,
            ];
        }

        if ( empty( $items ) ) {
            return new WP_Error( 'no_items', 'No valid items found to export.' );
        }

        return [
            'etch_export_version' => '1.0',
            'etch_export_type'    => 'multi',
            'exported_at'         => current_time( 'mysql' ),
            'source_site'         => get_bloginfo( 'url' ),
            'item_count'          => count( $items ),
            'items'               => $items,
            'etch_styles'         => $all_styles,
            'errors'              => $errors,
        ];
    }

    // ── Shared helpers ────────────────────────────────────────────────────────

    public static function get_post_meta( $post_id ) {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d",
                $post_id
            ),
            ARRAY_A
        );
    }

    public static function get_styles_for_ids( $style_ids ) {
        $all_styles  = get_option( 'etch_styles', [] );
        $page_styles = [];

        if ( is_string( $all_styles ) ) {
            $all_styles = maybe_unserialize( $all_styles );
        }

        foreach ( $style_ids as $id ) {
            if ( isset( $all_styles[ $id ] ) ) {
                $page_styles[ $id ] = $all_styles[ $id ];
            }
        }

        return $page_styles;
    }

    public static function extract_style_ids( $content ) {
        $ids = [];
        preg_match_all( '/"styles"\s*:\s*\[([^\]]*)\]/', $content, $matches );
        foreach ( $matches[1] as $match ) {
            preg_match_all( '/"([^"]+)"/', $match, $string_matches );
            foreach ( $string_matches[1] as $id ) {
                $ids[] = $id;
            }
        }
        return array_values( array_unique( $ids ) );
    }
}
