<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Etch_Transfer_Admin {

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_etch_export_multi', [ $this, 'handle_export_multi' ] );
        add_action( 'wp_ajax_etch_import',       [ $this, 'handle_import' ] );
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

    private function get_all_grouped() {
        $groups = [];

        // ── Public post types (pages, posts, CPTs) ────────────────────────────
        $post_types = get_post_types( [ 'public' => true ], 'objects' );
        unset( $post_types['attachment'] );

        foreach ( $post_types as $slug => $obj ) {
            $posts = get_posts([
                'post_type'      => $slug,
                'post_status'    => [ 'publish', 'draft' ],
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ]);
            if ( ! empty( $posts ) ) {
                $groups[ $slug ] = [ 'label' => $obj->labels->name, 'posts' => $posts ];
            }
        }

        // ── Templates ─────────────────────────────────────────────────────────
        $templates = get_posts([
            'post_type'      => 'wp_template',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);
        if ( ! empty( $templates ) ) {
            $groups['wp_template'] = [ 'label' => 'Templates', 'posts' => $templates ];
        }

        // ── Patterns & Components — split by etch_component_html_key ────────
        $all_blocks = get_posts([
            'post_type'      => 'wp_block',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        $components = [];
        $patterns   = [];

        foreach ( $all_blocks as $block ) {
            $is_component = (bool) get_post_meta( $block->ID, 'etch_component_html_key', true );
            if ( $is_component ) {
                $components[] = $block;
            } else {
                $patterns[] = $block;
            }
        }

        if ( ! empty( $components ) ) {
            $groups['wp_block_component'] = [ 'label' => 'Etch Components', 'posts' => $components, 'post_type' => 'wp_block' ];
        }
        if ( ! empty( $patterns ) ) {
            $groups['wp_block_pattern'] = [ 'label' => 'Patterns', 'posts' => $patterns, 'post_type' => 'wp_block' ];
        }

        return $groups;
    }

    public function render_page() {
        $groups = $this->get_all_grouped();
        ?>
        <div class="wrap et-wrap">
            <div class="et-header">
                <div class="et-header__inner">
                    <span class="et-badge">Etch</span>
                    <h1>Transfer Tool</h1>
                    <p>Move pages, templates, patterns, and components between WordPress sites.</p>
                </div>
            </div>

            <!-- Tabs -->
            <div class="et-tabs">
                <button class="et-tab active" data-tab="export">Export</button>
                <button class="et-tab" data-tab="import">Import</button>
            </div>

            <!-- ── TAB: Export ── -->
            <div class="et-tab-panel active" id="et-tab-export">
                <div class="et-panel et-panel--full">
                    <div class="et-panel__head">
                        <span class="et-step">Export</span>
                        <div>
                            <h2>Select Items to Export</h2>
                            <p>Choose any combination of pages, CPTs, templates, patterns, and components. All selected items are bundled into a single JSON file.</p>
                        </div>
                    </div>
                    <div class="et-panel__body">

                        <?php foreach ( $groups as $type_slug => $group ) : ?>
                        <div class="et-check-group" data-type="<?php echo esc_attr( $type_slug ); ?>">
                            <div class="et-check-group__header">
                                <span class="et-check-group__label"><?php echo $group['label']; ?></span>
                                <button type="button" class="et-select-all" data-group="<?php echo esc_attr( $type_slug ); ?>">Select all</button>
                            </div>
                            <div class="et-check-list">
                                <?php foreach ( $group['posts'] as $post ) : ?>
                                <label class="et-check-item">
                                    <input type="checkbox" class="et-multi-check"
                                        data-group="<?php echo esc_attr( $type_slug ); ?>"
                                        data-label="<?php echo esc_attr( $group['label'] ); ?>"
                                        value="<?php echo esc_attr( $post->ID ); ?>">
                                    <span class="et-check-title"><?php echo esc_html( $post->post_title ?: '(no title)' ); ?></span>
                                    <span class="et-check-meta"><?php echo esc_html( $post->post_name ); ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>

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

            <!-- ── TAB: Import ── -->
            <div class="et-tab-panel" id="et-tab-import">
                <div class="et-panel et-panel--full">
                    <div class="et-panel__head">
                        <span class="et-step">Import</span>
                        <div>
                            <h2>Import Bundle</h2>
                            <p>Upload an export file. Each item is automatically matched to its destination by post type and slug. New items are created if they don't exist yet.</p>
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

                        <div id="et-import-summary" class="et-import-summary" style="display:none;"></div>

                        <button id="et-import-btn" class="et-btn et-btn--import" disabled>
                            <?php echo $this->icon_upload(); ?> Import All
                        </button>
                        <div id="et-import-status" class="et-status" style="display:none;"></div>

                    </div>
                </div>
            </div>

        </div>
        <?php
    }

    // ── AJAX handlers ─────────────────────────────────────────────────────────

    public function handle_export_multi() {
        check_ajax_referer( 'etch_transfer_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized', 403 );

        $ids = $_POST['ids'] ?? [];
        if ( is_string( $ids ) ) $ids = explode( ',', $ids );
        $ids = array_map( 'intval', $ids );

        $result = Etch_Transfer_Exporter::export_multi( $ids );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $envelope = [ 'success' => true, 'data' => $result ];
        header( 'Content-Type: application/json; charset=utf-8' );
        echo json_encode( $envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        wp_die();
    }

    public function handle_import() {
        check_ajax_referer( 'etch_transfer_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized', 403 );

        if ( empty( $_FILES['import_file']['tmp_name'] ) ) {
            wp_send_json_error( 'No file received.' );
            return;
        }

        $json = file_get_contents( $_FILES['import_file']['tmp_name'] );
        if ( $json === false || empty( $json ) ) {
            wp_send_json_error( 'Could not read uploaded file.' );
            return;
        }

        $result = Etch_Transfer_Importer::import( $json );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }

    private function icon_download() {
        return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>';
    }

    private function icon_upload() {
        return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>';
    }
}
