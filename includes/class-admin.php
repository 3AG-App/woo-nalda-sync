<?php
/**
 * Admin class
 *
 * @package Woo_Nalda_Sync
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Admin class
 */
class WNS_Admin {

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_filter( 'plugin_action_links_' . WNS_PLUGIN_BASENAME, array( $this, 'add_action_links' ) );

        // Product list column
        add_filter( 'manage_edit-product_columns', array( $this, 'add_product_column' ) );
        add_action( 'manage_product_posts_custom_column', array( $this, 'render_product_column' ), 10, 2 );

        // Product meta box
        add_action( 'add_meta_boxes', array( $this, 'add_product_meta_box' ) );
        add_action( 'save_post_product', array( $this, 'save_product_meta' ) );

        // Quick edit
        add_action( 'quick_edit_custom_box', array( $this, 'quick_edit_custom_box' ), 10, 2 );
        add_action( 'save_post_product', array( $this, 'save_quick_edit' ) );
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __( 'Nalda Sync', 'woo-nalda-sync' ),
            __( 'Nalda Sync', 'woo-nalda-sync' ),
            'manage_woocommerce',
            'wns-dashboard',
            array( $this, 'render_dashboard_page' ),
            'dashicons-cloud-upload',
            56
        );

        add_submenu_page(
            'wns-dashboard',
            __( 'Dashboard', 'woo-nalda-sync' ),
            __( 'Dashboard', 'woo-nalda-sync' ),
            'manage_woocommerce',
            'wns-dashboard',
            array( $this, 'render_dashboard_page' )
        );

        add_submenu_page(
            'wns-dashboard',
            __( 'Settings', 'woo-nalda-sync' ),
            __( 'Settings', 'woo-nalda-sync' ),
            'manage_woocommerce',
            'wns-settings',
            array( $this, 'render_settings_page' )
        );

        add_submenu_page(
            'wns-dashboard',
            __( 'Logs', 'woo-nalda-sync' ),
            __( 'Logs', 'woo-nalda-sync' ),
            'manage_woocommerce',
            'wns-logs',
            array( $this, 'render_logs_page' )
        );

        add_submenu_page(
            'wns-dashboard',
            __( 'License', 'woo-nalda-sync' ),
            __( 'License', 'woo-nalda-sync' ),
            'manage_woocommerce',
            'wns-license',
            array( $this, 'render_license_page' )
        );
    }

    /**
     * Enqueue scripts
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_scripts( $hook ) {
        // Only load on plugin pages
        if ( strpos( $hook, 'wns-' ) === false && $hook !== 'edit.php' ) {
            return;
        }

        wp_enqueue_style(
            'wns-admin',
            WNS_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WNS_VERSION
        );

        wp_enqueue_script(
            'wns-admin',
            WNS_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            WNS_VERSION,
            true
        );

        wp_localize_script(
            'wns-admin',
            'wns_admin',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'wns_admin_nonce' ),
                'strings'  => array(
                    'confirm_clear_logs'    => __( 'Are you sure you want to clear all logs?', 'woo-nalda-sync' ),
                    'running'               => __( 'Running...', 'woo-nalda-sync' ),
                    'success'               => __( 'Success!', 'woo-nalda-sync' ),
                    'error'                 => __( 'Error occurred', 'woo-nalda-sync' ),
                    'saving'                => __( 'Saving...', 'woo-nalda-sync' ),
                    'saved'                 => __( 'Settings saved!', 'woo-nalda-sync' ),
                    'testing'               => __( 'Testing...', 'woo-nalda-sync' ),
                    'connection_success'    => __( 'Connection successful!', 'woo-nalda-sync' ),
                    'connection_failed'     => __( 'Connection failed', 'woo-nalda-sync' ),
                    'activating'            => __( 'Activating...', 'woo-nalda-sync' ),
                    'deactivating'          => __( 'Deactivating...', 'woo-nalda-sync' ),
                    'confirm_deactivate'    => __( 'Are you sure you want to deactivate this license?', 'woo-nalda-sync' ),
                ),
            )
        );
    }

    /**
     * Add action links
     *
     * @param array $links Plugin action links.
     * @return array
     */
    public function add_action_links( $links ) {
        $plugin_links = array(
            '<a href="' . admin_url( 'admin.php?page=wns-settings' ) . '">' . __( 'Settings', 'woo-nalda-sync' ) . '</a>',
        );
        return array_merge( $plugin_links, $links );
    }

    /**
     * Add product column
     *
     * @param array $columns Existing columns.
     * @return array
     */
    public function add_product_column( $columns ) {
        $columns['nalda_export'] = __( 'Nalda', 'woo-nalda-sync' );
        return $columns;
    }

    /**
     * Render product column
     *
     * @param string $column Column name.
     * @param int    $post_id Post ID.
     */
    public function render_product_column( $column, $post_id ) {
        if ( 'nalda_export' !== $column ) {
            return;
        }

        $nalda_export = get_post_meta( $post_id, '_wns_nalda_export', true );
        $default      = get_option( 'wns_product_default_behavior', 'include' );

        if ( empty( $nalda_export ) || 'default' === $nalda_export ) {
            $effective = $default;
            $label     = 'include' === $default ? __( 'Included (default)', 'woo-nalda-sync' ) : __( 'Excluded (default)', 'woo-nalda-sync' );
            $class     = 'include' === $default ? 'wns-status-default-include' : 'wns-status-default-exclude';
        } elseif ( 'include' === $nalda_export ) {
            $label = __( 'Included', 'woo-nalda-sync' );
            $class = 'wns-status-include';
        } else {
            $label = __( 'Excluded', 'woo-nalda-sync' );
            $class = 'wns-status-exclude';
        }

        // Check if product has GTIN and price
        $product = wc_get_product( $post_id );
        $gtin    = $this->get_product_gtin( $product );
        $price   = $product ? $product->get_price() : '';

        $warnings = array();
        if ( empty( $gtin ) ) {
            $warnings[] = __( 'No GTIN', 'woo-nalda-sync' );
        }
        if ( empty( $price ) ) {
            $warnings[] = __( 'No Price', 'woo-nalda-sync' );
        }

        echo '<span class="wns-status ' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
        if ( ! empty( $warnings ) ) {
            echo '<br><small class="wns-warning">' . esc_html( implode( ', ', $warnings ) ) . '</small>';
        }
    }

    /**
     * Get product GTIN
     *
     * @param WC_Product $product Product object.
     * @return string
     */
    private function get_product_gtin( $product ) {
        if ( ! $product ) {
            return '';
        }

        // Check common GTIN meta fields
        $gtin_fields = array( '_gtin', '_ean', '_barcode', 'gtin', 'ean', 'barcode', '_global_unique_id' );

        foreach ( $gtin_fields as $field ) {
            $gtin = $product->get_meta( $field );
            if ( ! empty( $gtin ) ) {
                return $gtin;
            }
        }

        // WooCommerce 8.4+ has native GTIN support
        if ( method_exists( $product, 'get_global_unique_id' ) ) {
            return $product->get_global_unique_id();
        }

        return '';
    }

    /**
     * Add product meta box
     */
    public function add_product_meta_box() {
        add_meta_box(
            'wns_nalda_export',
            __( 'Nalda Marketplace', 'woo-nalda-sync' ),
            array( $this, 'render_product_meta_box' ),
            'product',
            'side',
            'default'
        );
    }

    /**
     * Render product meta box
     *
     * @param WP_Post $post Post object.
     */
    public function render_product_meta_box( $post ) {
        $nalda_export = get_post_meta( $post->ID, '_wns_nalda_export', true );
        $default      = get_option( 'wns_product_default_behavior', 'include' );

        wp_nonce_field( 'wns_product_meta', 'wns_product_meta_nonce' );
        ?>
        <p>
            <label for="wns_nalda_export"><?php esc_html_e( 'Export to Nalda:', 'woo-nalda-sync' ); ?></label>
            <select name="wns_nalda_export" id="wns_nalda_export" class="widefat">
                <option value="default" <?php selected( $nalda_export, 'default' ); ?>>
                    <?php
                    printf(
                        /* translators: %s: Default behavior (Include/Exclude) */
                        esc_html__( 'Follow default (%s)', 'woo-nalda-sync' ),
                        'include' === $default ? esc_html__( 'Include', 'woo-nalda-sync' ) : esc_html__( 'Exclude', 'woo-nalda-sync' )
                    );
                    ?>
                </option>
                <option value="include" <?php selected( $nalda_export, 'include' ); ?>>
                    <?php esc_html_e( 'Explicitly Include', 'woo-nalda-sync' ); ?>
                </option>
                <option value="exclude" <?php selected( $nalda_export, 'exclude' ); ?>>
                    <?php esc_html_e( 'Explicitly Exclude', 'woo-nalda-sync' ); ?>
                </option>
            </select>
        </p>
        <p class="description">
            <?php esc_html_e( 'Note: Products must have a GTIN and price to be exported.', 'woo-nalda-sync' ); ?>
        </p>
        <?php
    }

    /**
     * Save product meta
     *
     * @param int $post_id Post ID.
     */
    public function save_product_meta( $post_id ) {
        if ( ! isset( $_POST['wns_product_meta_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wns_product_meta_nonce'] ) ), 'wns_product_meta' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( isset( $_POST['wns_nalda_export'] ) ) {
            $value = sanitize_text_field( wp_unslash( $_POST['wns_nalda_export'] ) );
            update_post_meta( $post_id, '_wns_nalda_export', $value );
        }
    }

    /**
     * Quick edit custom box
     *
     * @param string $column_name Column name.
     * @param string $post_type Post type.
     */
    public function quick_edit_custom_box( $column_name, $post_type ) {
        if ( 'product' !== $post_type || 'nalda_export' !== $column_name ) {
            return;
        }

        $default = get_option( 'wns_product_default_behavior', 'include' );
        ?>
        <fieldset class="inline-edit-col-right">
            <div class="inline-edit-col">
                <label class="inline-edit-group">
                    <span class="title"><?php esc_html_e( 'Nalda Export', 'woo-nalda-sync' ); ?></span>
                    <select name="wns_nalda_export">
                        <option value="default">
                            <?php
                            printf(
                                /* translators: %s: Default behavior */
                                esc_html__( 'Follow default (%s)', 'woo-nalda-sync' ),
                                'include' === $default ? esc_html__( 'Include', 'woo-nalda-sync' ) : esc_html__( 'Exclude', 'woo-nalda-sync' )
                            );
                            ?>
                        </option>
                        <option value="include"><?php esc_html_e( 'Explicitly Include', 'woo-nalda-sync' ); ?></option>
                        <option value="exclude"><?php esc_html_e( 'Explicitly Exclude', 'woo-nalda-sync' ); ?></option>
                    </select>
                </label>
            </div>
        </fieldset>
        <?php
    }

    /**
     * Save quick edit
     *
     * @param int $post_id Post ID.
     */
    public function save_quick_edit( $post_id ) {
        // Skip if our nonce field is present (handled by save_product_meta)
        if ( isset( $_POST['wns_product_meta_nonce'] ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Check for bulk edit or quick edit
        if ( isset( $_POST['wns_nalda_export'] ) ) {
            $value = sanitize_text_field( wp_unslash( $_POST['wns_nalda_export'] ) );
            update_post_meta( $post_id, '_wns_nalda_export', $value );
        }
    }

    /**
     * Render dashboard page
     */
    public function render_dashboard_page() {
        include WNS_PLUGIN_DIR . 'includes/views/dashboard.php';
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        include WNS_PLUGIN_DIR . 'includes/views/settings.php';
    }

    /**
     * Render logs page
     */
    public function render_logs_page() {
        include WNS_PLUGIN_DIR . 'includes/views/logs.php';
    }

    /**
     * Render license page
     */
    public function render_license_page() {
        include WNS_PLUGIN_DIR . 'includes/views/license.php';
    }
}
