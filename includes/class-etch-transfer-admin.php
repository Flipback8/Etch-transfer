<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Etch_Transfer_Admin {

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_etch_export_multi', [ $this, 'handle_export_multi' ] );
        add_action( 'wp_ajax_etch_import',       [ $this, 'handle_import' ] );
        add_action( 'wp_ajax_etch_dry_run',      [ $this, 'handle_dry_run' ] );
    }

    public function register_menu() {
        add_menu_page( 'Etch Transfer', 'Etch Transfer', 'manage_options', 'etch-transfer', [ $this, 'render_page' ], 'dashicons-migrate', 80 );
    }

    public function enqueue_assets( $hook ) {
        if ( $hook !== 'toplevel_page_etch-transfer' ) return;
        wp_enqueue_style( 'etch-transfer-css', ETCH_TRANSFER_URL . 'assets/admin.css', [], ETCH_TRANSFER_VERSION );
        wp_enqueue_script( 'etch-transfer-js', ETCH_TRANSFER_URL . 'assets/admin.js', [ 'jquery' ], ETCH_TRANSFER_VERSION, true );
        wp_localize_script( 'etch-transfer-js', 'etchTransfer', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'etch_transfer_nonce' ),
        ]);
    }

    // ── Data getters ──────────────────────────────────────────────────────────

    private function get_all_grouped() {
        $groups = [];

        $post_types = get_post_types( [ 'public' => true ], 'objects' );
        unset( $post_types['attachment'] );

        foreach ( $post_types as $slug => $obj ) {
            $posts = get_posts([ 'post_type' => $slug, 'post_status' => [ 'publish', 'draft' ], 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ]);
            if ( ! empty( $posts ) ) $groups[ $slug ] = [ 'label' => $obj->labels->name, 'posts' => $posts ];
        }

        $templates = get_posts([ 'post_type' => 'wp_template', 'post_status' => 'any', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ]);
        if ( ! empty( $templates ) ) $groups['wp_template'] = [ 'label' => 'Templates', 'posts' => $templates ];

        $all_blocks  = get_posts([ 'post_type' => 'wp_block', 'post_status' => 'any', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ]);
        $components  = [];
        $patterns    = [];
        foreach ( $all_blocks as $block ) {
            (bool) get_post_meta( $block->ID, 'etch_component_html_key', true ) ? ( $components[] = $block ) : ( $patterns[] = $block );
        }
        if ( ! empty( $components ) ) $groups['wp_block_component'] = [ 'label' => 'Etch Components', 'posts' => $components, 'post_type' => 'wp_block' ];
        if ( ! empty( $patterns )   ) $groups['wp_block_pattern']   = [ 'label' => 'Patterns',        'posts' => $patterns,   'post_type' => 'wp_block' ];

        return $groups;
    }

    private function get_global_stylesheets() {
        $raw = get_option( 'etch_global_stylesheets', [] );
        if ( is_string( $raw ) ) $raw = maybe_unserialize( $raw );
        return is_array( $raw ) ? $raw : [];
    }

    private function get_loops() {
        $raw = get_option( 'etch_loops', [] );
        if ( is_string( $raw ) ) $raw = maybe_unserialize( $raw );
        return is_array( $raw ) ? $raw : [];
    }

    private function get_wsforms() {
        global $wpdb;
        $table = $wpdb->prefix . 'wsf_form';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) return [];
        return $wpdb->get_results( "SELECT id, label, status FROM {$table} ORDER BY label ASC" );
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function render_page() {
        $groups      = $this->get_all_grouped();
        $gss         = $this->get_global_stylesheets();
        $loops       = $this->get_loops();
        $wsforms     = $this->get_wsforms();
        ?>
        <div class="wrap et-wrap">
            <div class="et-header">
                <div class="et-header__inner">
                    <span class="et-badge">Etch</span>
                    <h1>Transfer Tool <span class="et-version">v2.1</span></h1>
                    <p>Move pages, templates, patterns, components, global stylesheets, loops, and WS Forms between WordPress sites.</p>
                </div>
            </div>

            <div class="et-tabs">
                <button class="et-tab active" data-tab="export">Export</button>
                <button class="et-tab" data-tab="import">Import</button>
            </div>

            <!-- EXPORT TAB -->
            <div class="et-tab-panel active" id="et-tab-export">
                <div class="et-panel et-panel--full">
                    <div class="et-panel__head">
                        <span class="et-step">Export</span>
                        <div>
                            <h2>Select Items to Export</h2>
                            <p>Choose any combination of content. Component dependencies, referenced loops, and WS Forms used on selected pages are auto-included.</p>
                        </div>
                    </div>
                    <div class="et-panel__body">

                        <div class="et-options-row">
                            <label class="et-toggle">
                                <input type="checkbox" id="et-opt-include-media">
                                <span class="et-toggle__label">Include media attachments <span class="et-badge-sm">base64, max 5 MB/file</span></span>
                            </label>
                            <label class="et-toggle">
                                <input type="checkbox" id="et-opt-include-deps" checked>
                                <span class="et-toggle__label">Auto-include component &amp; loop dependencies</span>
                            </label>
                        </div>

                        <?php foreach ( $groups as $type_slug => $group ) :
                            $slug_class    = 'et-group--' . sanitize_html_class( str_replace( '_', '-', $type_slug ) );
                            $post_type_key = $group['post_type'] ?? $type_slug;
                        ?>
                        <div class="et-check-group <?php echo esc_attr( $slug_class ); ?>" data-type="<?php echo esc_attr( $type_slug ); ?>">
                            <div class="et-check-group__header">
                                <span class="et-check-group__label">
                                    <?php echo esc_html( $group['label'] ); ?>
                                    <span class="et-post-type-key"><?php echo esc_html( $post_type_key ); ?></span>
                                </span>
                                <button type="button" class="et-select-all" data-group="<?php echo esc_attr( $type_slug ); ?>">Select all</button>
                            </div>
                            <div class="et-check-list">
                                <?php foreach ( $group['posts'] as $post ) : ?>
                                <label class="et-check-item">
                                    <input type="checkbox" class="et-multi-check"
                                        data-group="<?php echo esc_attr( $type_slug ); ?>"
                                        value="<?php echo esc_attr( $post->ID ); ?>">
                                    <span class="et-check-title"><?php echo esc_html( $post->post_title ?: '(no title)' ); ?></span>
                                    <span class="et-check-meta"><?php echo esc_html( $post->post_name ); ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <!-- Global Stylesheets -->
                        <?php if ( ! empty( $gss ) ) : ?>
                        <div class="et-check-group et-check-group--gss et-group--global-stylesheets" data-type="etch_global_stylesheets">
                            <div class="et-check-group__header">
                                <span class="et-check-group__label">Global Stylesheets <span class="et-post-type-key">etch_global_stylesheets</span></span>
                                <button type="button" class="et-select-all" data-group="etch_global_stylesheets">Select all</button>
                            </div>
                            <div class="et-check-list">
                                <?php foreach ( $gss as $sheet_id => $sheet ) : ?>
                                <label class="et-check-item">
                                    <input type="checkbox" class="et-multi-check et-gss-check" data-group="etch_global_stylesheets" value="<?php echo esc_attr( $sheet_id ); ?>">
                                    <span class="et-check-title"><?php echo esc_html( $sheet['name'] ?? $sheet_id ); ?></span>
                                    <span class="et-check-meta"><?php echo esc_html( $sheet_id ); ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Loops -->
                        <?php if ( ! empty( $loops ) ) : ?>
                        <div class="et-check-group et-check-group--loops et-group--loops" data-type="etch_loops">
                            <div class="et-check-group__header">
                                <span class="et-check-group__label">Loops <span class="et-post-type-key">etch_loops</span></span>
                                <button type="button" class="et-select-all" data-group="etch_loops">Select all</button>
                            </div>
                            <div class="et-check-list">
                                <?php foreach ( $loops as $loop_id => $loop ) : ?>
                                <label class="et-check-item">
                                    <input type="checkbox" class="et-multi-check et-loop-check" data-group="etch_loops" value="<?php echo esc_attr( $loop_id ); ?>">
                                    <span class="et-check-title"><?php echo esc_html( $loop['name'] ?? $loop_id ); ?></span>
                                    <span class="et-check-meta"><?php echo esc_html( $loop['key'] ?? $loop_id ); ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- WS Forms — always last -->
                        <?php if ( ! empty( $wsforms ) ) : ?>
                        <div class="et-check-group et-check-group--wsf et-group--wsf-form" data-type="wsf_form">
                            <div class="et-check-group__header">
                                <span class="et-check-group__label">WS Forms <span class="et-post-type-key">wsf_form</span></span>
                                <button type="button" class="et-select-all" data-group="wsf_form">Select all</button>
                            </div>
                            <div class="et-check-list">
                                <?php foreach ( $wsforms as $form ) : ?>
                                <label class="et-check-item">
                                    <input type="checkbox" class="et-multi-check et-wsf-check" data-group="wsf_form" value="<?php echo esc_attr( $form->id ); ?>">
                                    <span class="et-check-title"><?php echo esc_html( $form->label ?: '(no title)' ); ?></span>
                                    <span class="et-check-meta"><?php echo esc_html( 'ID: ' . $form->id . ' · ' . $form->status ); ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="et-multi-footer">
                            <span id="et-selected-count" class="et-selected-count">0 selected</span>
                            <button id="et-export-multi-btn" class="et-btn et-btn--export" disabled>
                                <?php echo $this->icon_download(); ?> Download Bundle JSON
                            </button>
                        </div>
                        <div id="et-multi-export-status" class="et-status" style="display:none;"></div>

                    </div>
                </div>
            </div>

            <!-- IMPORT TAB -->
            <div class="et-tab-panel" id="et-tab-import">
                <div class="et-panel et-panel--full">
                    <div class="et-panel__head">
                        <span class="et-step">Import</span>
                        <div>
                            <h2>Import Bundle</h2>
                            <p>Drop an export file to preview what will happen, then import when ready.</p>
                        </div>
                    </div>
                    <div class="et-panel__body">

                        <div class="et-field">
                            <label>Export JSON File</label>
                            <div class="et-file-drop" id="et-file-drop">
                                <?php echo $this->icon_upload(); ?>
                                <span id="et-file-label">Drop file here or <u>browse</u></span>
                                <input type="file" id="et-import-file" accept=".json">
                            </div>
                        </div>

                        <!-- Preview renders here automatically after file drop -->
                        <div id="et-import-preview-wrap" style="display:none;">
                            <div id="et-import-summary" class="et-import-summary"></div>
                            <div id="et-dry-run-results"></div>

                            <div class="et-options-row" id="et-import-options">
                                <div class="et-option-group">
                                    <label class="et-option-label">Style conflicts</label>
                                    <select id="et-opt-style-conflicts" class="et-select">
                                        <option value="skip">Skip (keep destination)</option>
                                        <option value="overwrite">Overwrite with imported</option>
                                        <option value="rename">Import under new ID</option>
                                    </select>
                                </div>
                                <label class="et-toggle">
                                    <input type="checkbox" id="et-opt-import-media">
                                    <span class="et-toggle__label">Import media attachments <span class="et-badge-sm">if bundled</span></span>
                                </label>
                            </div>

                            <div class="et-import-actions">
                                <button id="et-import-btn" class="et-btn et-btn--import">
                                    <?php echo $this->icon_upload(); ?> Import All
                                </button>
                            </div>
                        </div>

                        <div id="et-import-status" class="et-status" style="display:none;"></div>
                        <div id="et-item-log" style="display:none;"></div>

                    </div>
                </div>
            </div>

        </div>
        <?php
    }

    // ── AJAX ──────────────────────────────────────────────────────────────────

    public function handle_export_multi() {
        check_ajax_referer( 'etch_transfer_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized', 403 );

        $ids      = $_POST['ids']      ?? [];
        $wsf_ids  = $_POST['wsf_ids']  ?? [];
        $gss_ids  = $_POST['gss_ids']  ?? [];
        $loop_ids = $_POST['loop_ids'] ?? [];

        if ( is_string( $ids ) )      $ids      = array_filter( explode( ',', $ids ) );
        if ( is_string( $wsf_ids ) )  $wsf_ids  = array_filter( explode( ',', $wsf_ids ) );
        if ( is_string( $gss_ids ) )  $gss_ids  = array_filter( explode( ',', $gss_ids ) );
        if ( is_string( $loop_ids ) ) $loop_ids = array_filter( explode( ',', $loop_ids ) );

        $ids      = array_map( 'intval',       array_filter( (array) $ids ) );
        $wsf_ids  = array_map( 'intval',       array_filter( (array) $wsf_ids ) );
        $gss_ids  = array_map( 'sanitize_key', array_filter( (array) $gss_ids ) );
        $loop_ids = array_map( 'sanitize_key', array_filter( (array) $loop_ids ) );

        $options = [
            'include_media'      => ! empty( $_POST['include_media'] ),
            'include_dependents' => isset( $_POST['include_dependents'] ) ? (bool) $_POST['include_dependents'] : true,
        ];

        $result = Etch_Transfer_Exporter::export_multi( $ids, $wsf_ids, $gss_ids, $loop_ids, $options );
        if ( is_wp_error( $result ) ) { wp_send_json_error( $result->get_error_message() ); return; }

        header( 'Content-Type: application/json; charset=utf-8' );
        echo json_encode( [ 'success' => true, 'data' => $result ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        wp_die();
    }

    public function handle_dry_run() {
        check_ajax_referer( 'etch_transfer_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized', 403 );

        if ( empty( $_FILES['import_file']['tmp_name'] ) ) { wp_send_json_error( 'No file received.' ); return; }
        $json = file_get_contents( $_FILES['import_file']['tmp_name'] );
        if ( empty( $json ) ) { wp_send_json_error( 'Could not read file.' ); return; }

        $result = Etch_Transfer_Importer::import( $json, [ 'dry_run' => true ] );
        is_wp_error( $result ) ? wp_send_json_error( $result->get_error_message() ) : wp_send_json_success( $result );
    }

    public function handle_import() {
        check_ajax_referer( 'etch_transfer_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized', 403 );
        if ( function_exists( 'set_time_limit' ) ) set_time_limit( 120 );
        @ini_set( 'memory_limit', '256M' );

        try {
            if ( empty( $_FILES['import_file']['tmp_name'] ) ) { wp_send_json_error( 'No file received.' ); return; }
            $json = file_get_contents( $_FILES['import_file']['tmp_name'] );
            if ( empty( $json ) ) { wp_send_json_error( 'Could not read file.' ); return; }

            $options = [
                'style_conflicts' => sanitize_key( $_POST['style_conflicts'] ?? 'skip' ),
                'import_media'    => ! empty( $_POST['import_media'] ),
            ];

            $result = Etch_Transfer_Importer::import( $json, $options );
            is_wp_error( $result ) ? wp_send_json_error( $result->get_error_message() ) : wp_send_json_success( $result );

        } catch ( \Throwable $e ) {
            wp_send_json_error( 'PHP error: ' . $e->getMessage() . ' in ' . basename( $e->getFile() ) . ':' . $e->getLine() );
        }
    }

    private function icon_download() { return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>'; }
    private function icon_upload()   { return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>'; }
}
