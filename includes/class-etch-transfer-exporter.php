<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Etch_Transfer_Exporter {

    public static function export_multi( $ids, $wsf_ids = [], $gss_ids = [], $loop_ids = [], $options = [] ) {
        if ( empty( $ids ) && empty( $wsf_ids ) && empty( $gss_ids ) && empty( $loop_ids ) ) {
            return new WP_Error( 'no_ids', 'No items selected for export.' );
        }

        $include_media      = ! empty( $options['include_media'] );
        $include_dependents = isset( $options['include_dependents'] ) ? (bool) $options['include_dependents'] : true;

        $items      = [];
        $all_styles = [];
        $errors     = [];
        $media_map  = [];
        $seen_ids   = array_flip( array_map( 'intval', (array) $ids ) );

        // Auto-expand component dependencies
        if ( $include_dependents ) {
            $dep_ids = self::collect_component_dependencies( $ids );
            foreach ( $dep_ids as $dep_id ) {
                if ( ! isset( $seen_ids[ $dep_id ] ) ) {
                    $ids[]               = $dep_id;
                    $seen_ids[ $dep_id ] = true;
                }
            }
        }

        foreach ( (array) $ids as $post_id ) {
            $post_id = intval( $post_id );
            $post    = get_post( $post_id );
            if ( ! $post ) { $errors[] = "Post ID {$post_id} not found."; continue; }

            $meta      = self::get_post_meta( $post_id );
            $style_ids = self::extract_style_ids( $post->post_content );
            $styles    = self::get_styles_for_ids( $style_ids );
            foreach ( $styles as $id => $style ) { $all_styles[ $id ] = $style; }

            // wp_template: capture theme taxonomy term
            $template_theme = null;
            if ( $post->post_type === 'wp_template' ) {
                $terms = wp_get_object_terms( $post_id, 'wp_theme', [ 'fields' => 'names' ] );
                if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) $template_theme = $terms[0];
            }

            // Component name map
            $component_name_map = [];
            $all_refs = array_merge(
                self::extract_component_refs( $post->post_content ),
                self::extract_component_refs( $post->post_content_filtered ?? '' )
            );
            foreach ( array_unique( $all_refs ) as $ref_id ) {
                $ref_post = get_post( $ref_id );
                if ( $ref_post ) $component_name_map[ (string) $ref_id ] = $ref_post->post_title;
            }

            // Taxonomy terms assigned to this post
            $post_taxonomies = self::export_post_taxonomies( $post_id, $post->post_type );

            // Media — scan both post_content and post_content_filtered so that
            // images embedded in component markup (stored in post_content_filtered)
            // are captured alongside those in the raw block content.
            $media_ids = [];
            if ( $include_media ) {
                $media_ids = array_values( array_unique( array_merge(
                    self::extract_media_ids( $post->post_content ),
                    self::extract_media_ids( $post->post_content_filtered ?? '' )
                ) ) );
                foreach ( $media_ids as $att_id ) {
                    if ( ! isset( $media_map[ $att_id ] ) ) {
                        $encoded = self::encode_attachment( $att_id );
                        if ( $encoded ) $media_map[ $att_id ] = $encoded;
                    }
                }
            }

            $is_component = (bool) get_post_meta( $post_id, 'etch_component_html_key', true );

            $items[] = [
                'source_post_id'        => $post_id,
                'source_post_type'      => $post->post_type,
                'source_post_name'      => $post->post_name,
                'post_title'            => $post->post_title,
                'post_status'           => $post->post_status,
                'post_content'          => $post->post_content,
                'post_content_filtered' => $post->post_content_filtered,
                'post_excerpt'          => $post->post_excerpt,
                'meta'                  => $meta,
                'style_ids_found'       => $style_ids,
                'template_theme'        => $template_theme,
                'component_name_map'    => $component_name_map,
                'is_component'          => $is_component,
                'is_dependency'         => isset( $seen_ids[ $post_id ] ) && ! in_array( $post_id, array_map( 'intval', (array) $ids ), true ),
                'media_ids'             => $media_ids,
                'post_taxonomies'       => $post_taxonomies,
            ];
        }

        // Auto-collect WSForms referenced in page content
        $wsf_ids = array_values( array_unique( array_map( 'intval', (array) $wsf_ids ) ) );
        if ( $include_dependents ) {
            foreach ( $items as $item ) {
                foreach ( self::extract_wsform_ids( $item['post_content'] ?? '' ) as $fid ) {
                    if ( ! in_array( $fid, $wsf_ids, true ) ) $wsf_ids[] = $fid;
                }
            }
        }

        $wsf_forms = [];
        foreach ( (array) $wsf_ids as $form_id ) {
            $form_id = intval( $form_id );
            $result  = self::export_wsform( $form_id );
            if ( is_wp_error( $result ) ) { $errors[] = "WS Form ID {$form_id}: " . $result->get_error_message(); continue; }
            $wsf_forms[] = $result;
        }

        // Global Stylesheets
        $global_stylesheets = [];
        if ( ! empty( $gss_ids ) ) {
            $all_gss = get_option( 'etch_global_stylesheets', [] );
            if ( is_string( $all_gss ) ) $all_gss = maybe_unserialize( $all_gss );
            if ( is_array( $all_gss ) ) {
                foreach ( $gss_ids as $sheet_id ) {
                    if ( isset( $all_gss[ $sheet_id ] ) ) $global_stylesheets[ $sheet_id ] = $all_gss[ $sheet_id ];
                }
            }
        }

        // Etch Loops — explicitly selected + auto-detected from content
        $loop_ids = array_values( array_unique( array_map( 'sanitize_key', (array) $loop_ids ) ) );
        if ( $include_dependents ) {
            foreach ( $items as $item ) {
                foreach ( self::extract_loop_ids( $item['post_content'] ?? '' ) as $lid ) {
                    if ( ! in_array( $lid, $loop_ids, true ) ) $loop_ids[] = $lid;
                }
            }
        }

        $loops = [];
        if ( ! empty( $loop_ids ) ) {
            $all_loops = get_option( 'etch_loops', [] );
            if ( is_string( $all_loops ) ) $all_loops = maybe_unserialize( $all_loops );
            if ( is_array( $all_loops ) ) {
                foreach ( $loop_ids as $loop_id ) {
                    if ( isset( $all_loops[ $loop_id ] ) ) $loops[ $loop_id ] = $all_loops[ $loop_id ];
                }
            }
        }

        if ( empty( $items ) && empty( $wsf_forms ) && empty( $global_stylesheets ) && empty( $loops ) ) {
            return new WP_Error( 'no_items', 'No valid items found to export.' );
        }

        $style_fingerprints = [];
        foreach ( $all_styles as $id => $style ) $style_fingerprints[ $id ] = md5( $style['css'] ?? '' );

        $item_count = count( $items ) + count( $wsf_forms ) + count( $global_stylesheets ) + count( $loops );

        return [
            'etch_export_version'     => '2.1',
            'etch_export_type'        => 'multi',
            'exported_at'             => current_time( 'mysql' ),
            'source_site'             => get_bloginfo( 'url' ),
            'source_theme'            => get_stylesheet(),
            'item_count'              => $item_count,
            'manifest'                => self::build_manifest( $items, $wsf_forms, $global_stylesheets, $loops ),
            'items'                   => $items,
            'wsf_forms'               => $wsf_forms,
            'etch_styles'             => $all_styles,
            'style_fingerprints'      => $style_fingerprints,
            'etch_global_stylesheets' => $global_stylesheets,
            'etch_loops'              => $loops,
            'media'                   => $media_map,
            'errors'                  => $errors,
        ];
    }

    // ── Loop ID extraction ────────────────────────────────────────────────────

    /**
     * Extract Etch loop IDs referenced in block content.
     * etch/loop block uses "loopId":"k7mrbkq" attribute.
     */
    public static function extract_loop_ids( $content ) {
        if ( empty( $content ) ) return [];
        $ids = [];
        preg_match_all( '/"loopId"\s*:\s*"([^"]+)"/', $content, $m );
        foreach ( $m[1] as $id ) if ( $id ) $ids[] = sanitize_key( $id );
        return array_values( array_unique( $ids ) );
    }

    // ── Taxonomy export ───────────────────────────────────────────────────────

    /**
     * Export all taxonomy terms assigned to a post.
     * Returns [ taxonomy_slug => [ terms... ] ] where each term has
     * name, slug, description, parent_slug.
     * Skips internal WP taxonomies: post_format, wp_theme, nav_menu.
     */
    public static function export_post_taxonomies( $post_id, $post_type ) {
        $skip = [ 'post_format', 'wp_theme', 'nav_menu', 'wp_template_part_area' ];
        $result = [];

        $taxonomies = get_object_taxonomies( $post_type, 'objects' );
        foreach ( $taxonomies as $tax_slug => $tax_obj ) {
            if ( in_array( $tax_slug, $skip, true ) ) continue;

            $terms = wp_get_object_terms( $post_id, $tax_slug, [ 'orderby' => 'parent' ] );
            if ( is_wp_error( $terms ) || empty( $terms ) ) continue;

            $result[ $tax_slug ] = [];
            foreach ( $terms as $term ) {
                $parent_slug = '';
                if ( $term->parent ) {
                    $parent = get_term( $term->parent, $tax_slug );
                    if ( $parent && ! is_wp_error( $parent ) ) $parent_slug = $parent->slug;
                }
                $result[ $tax_slug ][] = [
                    'name'        => $term->name,
                    'slug'        => $term->slug,
                    'description' => $term->description,
                    'parent_slug' => $parent_slug,
                ];
            }
        }

        return $result;
    }

    /**
     * Export all terms from given taxonomies (for standalone taxonomy export).
     * Returns [ taxonomy_slug => [ 'label' => ..., 'terms' => [...] ] ]
     */
    public static function export_taxonomies( $tax_slugs ) {
        $result = [];
        foreach ( $tax_slugs as $tax_slug ) {
            $tax_obj = get_taxonomy( $tax_slug );
            if ( ! $tax_obj ) continue;

            $terms = get_terms( [ 'taxonomy' => $tax_slug, 'hide_empty' => false, 'orderby' => 'parent' ] );
            if ( is_wp_error( $terms ) ) continue;

            $term_data = [];
            foreach ( $terms as $term ) {
                $parent_slug = '';
                if ( $term->parent ) {
                    $parent = get_term( $term->parent, $tax_slug );
                    if ( $parent && ! is_wp_error( $parent ) ) $parent_slug = $parent->slug;
                }
                $term_data[] = [
                    'name'        => $term->name,
                    'slug'        => $term->slug,
                    'description' => $term->description,
                    'parent_slug' => $parent_slug,
                ];
            }

            $result[ $tax_slug ] = [
                'label'        => $tax_obj->label,
                'hierarchical' => $tax_obj->hierarchical,
                'terms'        => $term_data,
            ];
        }
        return $result;
    }

    // ── Dependency collectors ─────────────────────────────────────────────────

    private static function collect_component_dependencies( $post_ids, $depth = 0 ) {
        if ( $depth > 5 ) return [];
        $dep_ids = [];
        foreach ( $post_ids as $post_id ) {
            $post = get_post( intval( $post_id ) );
            if ( ! $post ) continue;
            $refs = array_merge(
                self::extract_component_refs( $post->post_content ),
                self::extract_component_refs( $post->post_content_filtered ?? '' )
            );
            foreach ( $refs as $ref_id ) {
                if ( ! in_array( $ref_id, $dep_ids, true ) && ! in_array( $ref_id, array_map( 'intval', $post_ids ), true ) ) {
                    $dep_ids[] = $ref_id;
                }
            }
        }
        if ( ! empty( $dep_ids ) ) {
            $nested = self::collect_component_dependencies( $dep_ids, $depth + 1 );
            foreach ( $nested as $nid ) {
                if ( ! in_array( $nid, $dep_ids, true ) ) $dep_ids[] = $nid;
            }
        }
        return array_values( array_unique( $dep_ids ) );
    }

    // ── Media ─────────────────────────────────────────────────────────────────

    /**
     * Extract all attachment IDs from post content.
     * Handles: etch/dynamic-image mediaId, etch/element attachmentId,
     *          wp:image id, wp:gallery ids[], wp-image-NNN class,
     *          and etch/component prop values typed as wpMediaId.
     */
    private static function extract_media_ids( $content ) {
        if ( empty( $content ) ) return [];
        $ids = [];

        // etch/dynamic-image — "mediaId":"2783" or "mediaId":2783
        preg_match_all( '/"mediaId"\s*:\s*"?(\d+)"?/', $content, $m );
        foreach ( $m[1] as $id ) $ids[] = intval( $id );

        // etch/element options.imageData — "attachmentId":1580
        preg_match_all( '/"attachmentId"\s*:\s*(\d+)/', $content, $m );
        foreach ( $m[1] as $id ) $ids[] = intval( $id );

        // Standard wp:image block comment — {"id":123}
        preg_match_all( '/wp:image\s+\{[^}]*"id"\s*:\s*(\d+)/i', $content, $m );
        foreach ( $m[1] as $id ) $ids[] = intval( $id );

        // wp:gallery — {"ids":[1,2,3]}
        preg_match_all( '/"ids"\s*:\s*\[([^\]]+)\]/', $content, $m );
        foreach ( $m[1] as $list ) {
            foreach ( explode( ',', $list ) as $id ) {
                $int = intval( trim( $id ) );
                if ( $int > 0 ) $ids[] = $int;
            }
        }

        // HTML class wp-image-NNN
        preg_match_all( '/wp-image-(\d+)/', $content, $m );
        foreach ( $m[1] as $id ) $ids[] = intval( $id );

        // etch/component props — when a component is used on a page, its media-typed
        // props are passed as numeric values in the block comment's "attributes" object:
        // <!-- wp:etch/component {"ref":297,"attributes":{"featureSmall":"684",...}} -->
        // We look up the component's property definitions to find which props are
        // wpMediaId; if definitions are unavailable we accept any pure-integer value.
        preg_match_all(
            '/<!--\s*wp:etch\/component\s+(\{[^}]*(?:\{[^}]*\}[^}]*)*\})\s*(?:\/)?-->/i',
            $content, $block_matches
        );
        foreach ( $block_matches[1] as $block_json ) {
            $block_data = json_decode( $block_json, true );
            if ( ! is_array( $block_data ) ) continue;

            $ref        = isset( $block_data['ref'] ) ? intval( $block_data['ref'] ) : 0;
            $attributes = $block_data['attributes'] ?? [];
            if ( empty( $attributes ) || ! is_array( $attributes ) ) continue;

            // Get the set of prop keys typed as wpMediaId for this component.
            $media_prop_keys = ( $ref > 0 ) ? self::get_component_media_prop_keys( $ref ) : [];

            foreach ( $attributes as $key => $value ) {
                $int_val = intval( $value );
                if ( $int_val <= 0 ) continue;
                // Accept if the key is a known wpMediaId prop, or — as a safe fallback
                // when the component post can't be read — if the value is a pure integer
                // string. encode_attachment() returns null for non-attachment IDs so
                // any false positives are harmlessly ignored.
                if ( ! empty( $media_prop_keys ) ) {
                    if ( in_array( $key, $media_prop_keys, true ) ) $ids[] = $int_val;
                } elseif ( (string) $int_val === ltrim( (string) $value, '"' ) || $int_val === $value ) {
                    $ids[] = $int_val;
                }
            }
        }

        return array_values( array_unique( array_filter( $ids ) ) );
    }

    /**
     * Return prop keys whose specialized type is "wpMediaId" for a component post.
     * The component's property definitions live in the root etch/element block attrs.
     */
    private static function get_component_media_prop_keys( $ref_id ) {
        $keys      = [];
        $comp_post = get_post( $ref_id );
        if ( ! $comp_post ) return $keys;

        // Properties are stored in the first etch/element block comment's JSON.
        // The block attrs JSON may contain nested braces, so we use a wider match
        // and rely on json_decode to validate.
        preg_match_all(
            '/<!--\s*wp:etch\/element\s+(\{.+?\})\s*(?:\/)?-->/is',
            $comp_post->post_content,
            $m
        );
        foreach ( $m[1] as $json_str ) {
            $attrs = json_decode( $json_str, true );
            if ( ! is_array( $attrs ) || empty( $attrs['properties'] ) ) continue;
            foreach ( $attrs['properties'] as $prop ) {
                if ( ( $prop['type']['specialized'] ?? '' ) === 'wpMediaId' ) {
                    $keys[] = $prop['key'];
                }
            }
            if ( ! empty( $keys ) ) break;
        }
        return $keys;
    }

    /**
     * Encode an attachment file to base64.
     * Skips files larger than 5 MB.
     */
    private static function encode_attachment( $att_id ) {
        $path = get_attached_file( $att_id );
        if ( ! $path || ! file_exists( $path ) ) return null;
        if ( filesize( $path ) > 5 * 1024 * 1024 ) return null;

        $mime = get_post_mime_type( $att_id ) ?: 'application/octet-stream';
        return [
            'attachment_id' => $att_id,
            'filename'      => basename( $path ),
            'mime_type'     => $mime,
            'alt'           => get_post_meta( $att_id, '_wp_attachment_image_alt', true ),
            'title'         => get_the_title( $att_id ),
            'data'          => base64_encode( file_get_contents( $path ) ),
        ];
    }

    // ── WSForm extraction ─────────────────────────────────────────────────────

    public static function extract_wsform_ids( $content ) {
        if ( empty( $content ) ) return [];
        $ids = [];
        preg_match_all( '/"form_id"\s*:\s*"?(\d+)"?/i', $content, $m );
        foreach ( $m[1] as $id ) $ids[] = intval( $id );
        preg_match_all( '/\[ws_form\b[^\]]*\bid\s*=\s*["\'](\d+)["\'][^\]]*\]/i', $content, $m );
        foreach ( $m[1] as $id ) $ids[] = intval( $id );
        return array_values( array_unique( array_filter( $ids ) ) );
    }

    // ── Component ref extraction ──────────────────────────────────────────────

    public static function extract_component_refs( $content ) {
        if ( empty( $content ) ) return [];
        $ids = [];
        preg_match_all( '/wp:etch\/component\s+\{[^}]*"ref"\s*:\s*(\d+)/i', $content, $matches );
        foreach ( $matches[1] as $id ) $ids[] = intval( $id );
        return array_values( array_unique( $ids ) );
    }

    // ── WSForm export ─────────────────────────────────────────────────────────

    private static function export_wsform( $form_id ) {
        if ( ! class_exists( 'WS_Form_Form' ) ) return new WP_Error( 'no_wsf', 'WS_Form_Form class not found.' );
        try {
            $ws_form_form     = new WS_Form_Form();
            $ws_form_form->id = $form_id;
            $form_object      = $ws_form_form->db_read( true, true );
            if ( ! $form_object ) return new WP_Error( 'wsf_read_failed', "Could not read form ID {$form_id}." );
            unset( $form_object->checksum, $form_object->published_checksum );
            $form_object->identifier          = WS_FORM_IDENTIFIER;
            $form_object->version             = WS_FORM_VERSION;
            $form_object->time                = time();
            $form_object->status              = 'draft';
            $form_object->count_submit        = 0;
            $form_object->meta->tab_index     = 0;
            $form_object->meta->export_object = 'form';
            $form_object->meta->style_id      = 0;
            $form_object->meta->style_id_conv = 0;
            $form_object->checksum            = md5( wp_json_encode( $form_object ) );
            return [ 'label' => $form_object->label, 'source_form_id' => $form_id, 'form_object' => $form_object ];
        } catch ( Exception $e ) {
            return new WP_Error( 'wsf_exception', $e->getMessage() );
        }
    }

    // ── Manifest ──────────────────────────────────────────────────────────────

    private static function build_manifest( $items, $wsf_forms, $global_stylesheets, $loops ) {
        $lines = [];
        foreach ( $items as $item ) {
            $dep     = ! empty( $item['is_dependency'] ) ? ' [dependency]' : '';
            $lines[] = "[{$item['source_post_type']}] {$item['post_title']}{$dep}";
        }
        foreach ( $wsf_forms as $f )                       $lines[] = '[wsf_form] '          . ( $f['label'] ?? '?' );
        foreach ( $global_stylesheets as $id => $sheet )   $lines[] = '[global_stylesheet] ' . ( $sheet['name'] ?? $id );
        foreach ( $loops as $id => $loop )                 $lines[] = '[loop] '              . ( $loop['name'] ?? $id );
        return implode( "\n", $lines );
    }

    // ── WP helpers ────────────────────────────────────────────────────────────

    public static function get_post_meta( $post_id ) {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $post_id ),
            ARRAY_A
        );
    }

    public static function get_styles_for_ids( $style_ids ) {
        $all_styles  = get_option( 'etch_styles', [] );
        $page_styles = [];
        if ( is_string( $all_styles ) ) $all_styles = maybe_unserialize( $all_styles );
        foreach ( $style_ids as $id ) {
            if ( isset( $all_styles[ $id ] ) ) $page_styles[ $id ] = $all_styles[ $id ];
        }
        return $page_styles;
    }

    public static function extract_style_ids( $content ) {
        $ids = [];
        preg_match_all( '/"styles"\s*:\s*\[([^\]]*)\]/', $content, $matches );
        foreach ( $matches[1] as $match ) {
            preg_match_all( '/"([^"]+)"/', $match, $string_matches );
            foreach ( $string_matches[1] as $id ) $ids[] = $id;
        }
        return array_values( array_unique( $ids ) );
    }
}
