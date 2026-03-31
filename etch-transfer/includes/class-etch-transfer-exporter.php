<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Etch_Transfer_Exporter {

    /**
     * Export multiple items by ID, plus WS Forms, global stylesheets, and loops.
     */
    public static function export_multi( $ids, $wsf_ids = [], $gss_ids = [], $loop_ids = [] ) {
        if ( empty( $ids ) && empty( $wsf_ids ) && empty( $gss_ids ) && empty( $loop_ids ) ) {
            return new WP_Error( 'no_ids', 'No items selected for export.' );
        }

        $items      = [];
        $all_styles = [];
        $errors     = [];

        // ── Standard post items ───────────────────────────────────────────────
        foreach ( (array) $ids as $post_id ) {
            $post_id = intval( $post_id );
            $post    = get_post( $post_id );

            if ( ! $post ) {
                $errors[] = "Post ID {$post_id} not found.";
                continue;
            }

            $meta      = self::get_post_meta( $post_id );
            $style_ids = self::extract_style_ids( $post->post_content );
            $styles    = self::get_styles_for_ids( $style_ids );

            foreach ( $styles as $id => $style ) {
                $all_styles[ $id ] = $style;
            }

            // For wp_template, capture the wp_theme taxonomy term so the
            // importer knows which theme this template belongs to on the source.
            $template_theme = null;
            if ( $post->post_type === 'wp_template' ) {
                $terms = wp_get_object_terms( $post_id, 'wp_theme', [ 'fields' => 'names' ] );
                if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                    $template_theme = $terms[0];
                }
            }

            $items[] = [
                'source_post_id'            => $post_id,
                'source_post_type'          => $post->post_type,
                'source_post_name'          => $post->post_name,
                'post_title'                => $post->post_title,
                'post_status'               => $post->post_status,
                'post_content'              => $post->post_content,
                'post_content_filtered'     => $post->post_content_filtered,
                'post_excerpt'              => $post->post_excerpt,
                'meta'                      => $meta,
                'style_ids_found'           => $style_ids,
                'template_theme'            => $template_theme,
            ];
        }

        // ── WS Form items ─────────────────────────────────────────────────────
        $wsf_forms = [];
        foreach ( (array) $wsf_ids as $form_id ) {
            $form_id = intval( $form_id );
            $result  = self::export_wsform( $form_id );
            if ( is_wp_error( $result ) ) {
                $errors[] = "WS Form ID {$form_id}: " . $result->get_error_message();
                continue;
            }
            $wsf_forms[] = $result;
        }

        // ── Global Stylesheets ────────────────────────────────────────────────
        $global_stylesheets = [];
        if ( ! empty( $gss_ids ) ) {
            $all_gss = get_option( 'etch_global_stylesheets', [] );
            if ( is_string( $all_gss ) ) $all_gss = maybe_unserialize( $all_gss );
            if ( is_array( $all_gss ) ) {
                foreach ( $gss_ids as $sheet_id ) {
                    if ( isset( $all_gss[ $sheet_id ] ) ) {
                        $global_stylesheets[ $sheet_id ] = $all_gss[ $sheet_id ];
                    }
                }
            }
        }

        // ── Etch Loops ────────────────────────────────────────────────────────
        $loops = [];
        if ( ! empty( $loop_ids ) ) {
            $all_loops = get_option( 'etch_loops', [] );
            if ( is_string( $all_loops ) ) $all_loops = maybe_unserialize( $all_loops );
            if ( is_array( $all_loops ) ) {
                foreach ( $loop_ids as $loop_id ) {
                    if ( isset( $all_loops[ $loop_id ] ) ) {
                        $loops[ $loop_id ] = $all_loops[ $loop_id ];
                    }
                }
            }
        }

        if ( empty( $items ) && empty( $wsf_forms ) && empty( $global_stylesheets ) && empty( $loops ) ) {
            return new WP_Error( 'no_items', 'No valid items found to export.' );
        }

        return [
            'etch_export_version'     => '1.0',
            'etch_export_type'        => 'multi',
            'exported_at'             => current_time( 'mysql' ),
            'source_site'             => get_bloginfo( 'url' ),
            'source_theme'            => get_stylesheet(),
            'item_count'              => count( $items ) + count( $wsf_forms ) + count( $global_stylesheets ) + count( $loops ),
            'items'                   => $items,
            'wsf_forms'               => $wsf_forms,
            'etch_styles'             => $all_styles,
            'etch_global_stylesheets' => $global_stylesheets,
            'etch_loops'              => $loops,
            'errors'                  => $errors,
        ];
    }

    /**
     * Export a single WS Form using WS Form's own db_read(), mirroring db_download_json().
     */
    private static function export_wsform( $form_id ) {
        if ( ! class_exists( 'WS_Form_Form' ) ) {
            return new WP_Error( 'no_wsf', 'WS_Form_Form class not found.' );
        }

        try {
            $ws_form_form     = new WS_Form_Form();
            $ws_form_form->id = $form_id;

            $form_object = $ws_form_form->db_read( true, true );

            if ( ! $form_object ) {
                return new WP_Error( 'wsf_read_failed', "Could not read form ID {$form_id}." );
            }

            unset( $form_object->checksum );
            unset( $form_object->published_checksum );

            $form_object->identifier            = WS_FORM_IDENTIFIER;
            $form_object->version               = WS_FORM_VERSION;
            $form_object->time                  = time();
            $form_object->status                = 'draft';
            $form_object->count_submit          = 0;
            $form_object->meta->tab_index       = 0;
            $form_object->meta->export_object   = 'form';
            $form_object->meta->style_id        = 0;
            $form_object->meta->style_id_conv   = 0;

            $form_object->checksum = md5( wp_json_encode( $form_object ) );

            return [
                'label'          => $form_object->label,
                'source_form_id' => $form_id,
                'form_object'    => $form_object,
            ];

        } catch ( Exception $e ) {
            return new WP_Error( 'wsf_exception', $e->getMessage() );
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

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
