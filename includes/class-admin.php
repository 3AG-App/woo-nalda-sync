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

        // Nalda order meta box
        add_action( 'add_meta_boxes', array( $this, 'add_nalda_order_meta_box' ) );
        add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_nalda_order_meta' ), 10, 1 );
        // For HPOS
        add_action( 'woocommerce_before_order_object_save', array( $this, 'save_nalda_order_meta_hpos' ), 10, 1 );
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
            __( 'Upload History', 'woo-nalda-sync' ),
            __( 'Upload History', 'woo-nalda-sync' ),
            'manage_woocommerce',
            'wns-history',
            array( $this, 'render_history_page' )
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

        // Enqueue media uploader on settings page
        if ( strpos( $hook, 'wns-settings' ) !== false ) {
            wp_enqueue_media();
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
                    'confirm_sync'          => __( 'Are you sure you want to run this sync now?', 'woo-nalda-sync' ),
                    'confirm_clear_logs'    => __( 'Are you sure you want to clear all logs?', 'woo-nalda-sync' ),
                    'running'               => __( 'Running...', 'woo-nalda-sync' ),
                    'sync_running'          => __( 'Syncing...', 'woo-nalda-sync' ),
                    'sync_error'            => __( 'Sync failed. Please try again.', 'woo-nalda-sync' ),
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
                    'select_image'          => __( 'Select Logo', 'woo-nalda-sync' ),
                    'use_image'             => __( 'Use this image', 'woo-nalda-sync' ),
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

        // WooCommerce 8.4+ has native GTIN support - check first to avoid deprecated meta access
        if ( method_exists( $product, 'get_global_unique_id' ) ) {
            $gtin = $product->get_global_unique_id();
            if ( ! empty( $gtin ) ) {
                return $gtin;
            }
        }

        // Check common GTIN meta fields
        $gtin_fields = array( '_gtin', '_ean', '_barcode', 'gtin', 'ean', 'barcode' );

        foreach ( $gtin_fields as $field ) {
            $gtin = $product->get_meta( $field );
            if ( ! empty( $gtin ) ) {
                return $gtin;
            }
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

    /**
     * Render history page
     */
    public function render_history_page() {
        include WNS_PLUGIN_DIR . 'includes/views/history.php';
    }

    /**
     * Add Nalda order meta box
     */
    public function add_nalda_order_meta_box() {
        // Support both traditional and HPOS
        $screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) &&
                  wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id( 'shop-order' )
            : 'shop_order';

        add_meta_box(
            'wns_nalda_order_info',
            __( 'Nalda Marketplace Info', 'woo-nalda-sync' ),
            array( $this, 'render_nalda_order_meta_box' ),
            $screen,
            'side',
            'high'
        );
    }

    /**
     * Render Nalda order meta box
     *
     * @param WP_Post|WC_Order $post_or_order Post or order object.
     */
    public function render_nalda_order_meta_box( $post_or_order ) {
        // Get the order object
        if ( $post_or_order instanceof WC_Order ) {
            $order = $post_or_order;
        } else {
            $order = wc_get_order( $post_or_order->ID );
        }

        if ( ! $order ) {
            return;
        }

        // Check if this is a Nalda order
        $nalda_order_id = $order->get_meta( '_nalda_order_id' );

        if ( empty( $nalda_order_id ) ) {
            echo '<p style="color: #666; font-style: italic;">' . esc_html__( 'This order was not imported from Nalda.', 'woo-nalda-sync' ) . '</p>';
            return;
        }

        // Get Nalda metadata
        $commission      = floatval( $order->get_meta( '_nalda_total_commission' ) );
        $original_total  = floatval( $order->get_meta( '_nalda_original_total' ) );
        $payout_status   = $order->get_meta( '_nalda_payout_status' );
        $created_at      = $order->get_meta( '_nalda_created_at' );
        $nalda_state     = $order->get_meta( '_nalda_state' );
        $expected_date   = $order->get_meta( '_nalda_expected_delivery_date' );
        $tracking_code   = $order->get_meta( '_nalda_tracking_code' );
        $end_customer_email = $order->get_meta( '_nalda_end_customer_email' );

        // Net revenue (order total is already after commission)
        $net_revenue = floatval( $order->get_total() );
        $currency    = $order->get_currency();

        // Calculate commission percentage
        $commission_pct = $original_total > 0 ? ( $commission / $original_total ) * 100 : 0;
        ?>
        <style>
            .wns-order-meta-box { margin: -6px -12px -12px; }
            .wns-order-meta-row { display: flex; justify-content: space-between; padding: 8px 12px; border-bottom: 1px solid #f0f0f0; }
            .wns-order-meta-row:last-child { border-bottom: none; }
            .wns-order-meta-label { color: #646970; font-size: 12px; }
            .wns-order-meta-value { font-weight: 500; text-align: right; }
            .wns-order-meta-value.negative { color: #d63638; }
            .wns-order-meta-value.positive { color: #00a32a; }
            .wns-order-meta-divider { border-top: 2px solid #dcdcde; margin: 0; }
            .wns-order-meta-total { background: #f6f7f7; }
            .wns-order-meta-total .wns-order-meta-label { font-weight: 600; color: #1d2327; }
            .wns-order-meta-total .wns-order-meta-value { font-weight: 600; font-size: 14px; }
            .wns-order-meta-badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 500; }
            .wns-order-meta-badge.pending { background: #fcf0e3; color: #9a6700; }
            .wns-order-meta-badge.paid { background: #d4edda; color: #155724; }
        </style>
        <div class="wns-order-meta-box">
            <div class="wns-order-meta-row">
                <span class="wns-order-meta-label"><?php esc_html_e( 'Nalda Order ID', 'woo-nalda-sync' ); ?></span>
                <span class="wns-order-meta-value">#<?php echo esc_html( $nalda_order_id ); ?></span>
            </div>

            <?php if ( $end_customer_email ) : ?>
            <div class="wns-order-meta-row">
                <span class="wns-order-meta-label"><?php esc_html_e( 'End Customer Email', 'woo-nalda-sync' ); ?></span>
                <span class="wns-order-meta-value" style="font-size: 11px;">
                    <a href="mailto:<?php echo esc_attr( $end_customer_email ); ?>"><?php echo esc_html( $end_customer_email ); ?></a>
                </span>
            </div>
            <?php endif; ?>

            <hr class="wns-order-meta-divider">

            <div class="wns-order-meta-row">
                <span class="wns-order-meta-label"><?php esc_html_e( 'Customer Paid', 'woo-nalda-sync' ); ?></span>
                <span class="wns-order-meta-value"><?php echo wc_price( $original_total, array( 'currency' => $currency ) ); ?></span>
            </div>

            <div class="wns-order-meta-row">
                <span class="wns-order-meta-label">
                    <?php
                    printf(
                        /* translators: %s: commission percentage */
                        esc_html__( 'Nalda Commission (%s%%)', 'woo-nalda-sync' ),
                        esc_html( number_format( $commission_pct, 1 ) )
                    );
                    ?>
                </span>
                <span class="wns-order-meta-value negative">-<?php echo wc_price( $commission, array( 'currency' => $currency ) ); ?></span>
            </div>

            <hr class="wns-order-meta-divider">

            <div class="wns-order-meta-row wns-order-meta-total">
                <span class="wns-order-meta-label"><?php esc_html_e( 'Your Revenue', 'woo-nalda-sync' ); ?></span>
                <span class="wns-order-meta-value positive"><?php echo wc_price( $net_revenue, array( 'currency' => $currency ) ); ?></span>
            </div>

            <hr class="wns-order-meta-divider">

            <div class="wns-order-meta-row">
                <span class="wns-order-meta-label"><?php esc_html_e( 'Payout Status', 'woo-nalda-sync' ); ?></span>
                <span class="wns-order-meta-value">
                    <?php
                    $status_class = 'pending';
                    $status_label = __( 'Pending', 'woo-nalda-sync' );
                    $payout_status_lower = strtolower( $payout_status );

                    if ( in_array( $payout_status_lower, array( 'paid', 'settled', 'completed' ), true ) ) {
                        $status_class = 'paid';
                        $status_label = __( 'Paid', 'woo-nalda-sync' );
                    } elseif ( in_array( $payout_status_lower, array( 'open', 'pending' ), true ) ) {
                        $status_class = 'pending';
                        $status_label = __( 'Open', 'woo-nalda-sync' );
                    } elseif ( ! empty( $payout_status ) ) {
                        $status_label = ucfirst( str_replace( '_', ' ', $payout_status ) );
                    }
                    ?>
                    <span class="wns-order-meta-badge <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span>
                </span>
            </div>

            <?php if ( $created_at ) : ?>
            <div class="wns-order-meta-row">
                <span class="wns-order-meta-label"><?php esc_html_e( 'Imported At', 'woo-nalda-sync' ); ?></span>
                <span class="wns-order-meta-value" style="font-size: 11px; color: #646970;"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $created_at ) ) ); ?></span>
            </div>
            <?php endif; ?>

            <hr class="wns-order-meta-divider">
            <p style="margin: 12px 12px 8px; font-weight: 600; color: #1d2327; font-size: 12px;">
                <?php esc_html_e( 'Delivery Information', 'woo-nalda-sync' ); ?>
            </p>

            <?php
            wp_nonce_field( 'wns_nalda_order_meta', 'wns_nalda_order_nonce' );

            // Available Nalda states
            $nalda_states = array(
                ''                 => __( '— Select State —', 'woo-nalda-sync' ),
                'IN_PREPARATION'   => __( 'In Preparation', 'woo-nalda-sync' ),
                'READY_TO_COLLECT' => __( 'Ready to Collect', 'woo-nalda-sync' ),
                'IN_DELIVERY'      => __( 'In Delivery', 'woo-nalda-sync' ),
                'DELIVERED'        => __( 'Delivered', 'woo-nalda-sync' ),
                'RETURNED'         => __( 'Returned', 'woo-nalda-sync' ),
                'CANCELLED'        => __( 'Cancelled', 'woo-nalda-sync' ),
                'DISPUTE'          => __( 'Dispute', 'woo-nalda-sync' ),
            );
            ?>

            <div class="wns-order-meta-row" style="flex-direction: column; gap: 4px;">
                <label class="wns-order-meta-label" for="_nalda_state"><?php esc_html_e( 'State', 'woo-nalda-sync' ); ?></label>
                <select name="_nalda_state" id="_nalda_state" style="width: 100%;">
                    <?php foreach ( $nalda_states as $value => $label ) : ?>
                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $nalda_state, $value ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="wns-order-meta-row" style="flex-direction: column; gap: 4px;">
                <label class="wns-order-meta-label" for="_nalda_expected_delivery_date"><?php esc_html_e( 'Expected Delivery Date', 'woo-nalda-sync' ); ?></label>
                <input type="date" name="_nalda_expected_delivery_date" id="_nalda_expected_delivery_date" value="<?php echo esc_attr( $expected_date ); ?>" style="width: 100%;">
            </div>

            <div class="wns-order-meta-row" style="flex-direction: column; gap: 4px;">
                <label class="wns-order-meta-label" for="_nalda_tracking_code"><?php esc_html_e( 'Tracking Code', 'woo-nalda-sync' ); ?></label>
                <input type="text" name="_nalda_tracking_code" id="_nalda_tracking_code" value="<?php echo esc_attr( $tracking_code ); ?>" style="width: 100%;" placeholder="<?php esc_attr_e( 'Enter tracking code', 'woo-nalda-sync' ); ?>">
            </div>

            <hr class="wns-order-meta-divider">

            <div class="wns-order-meta-row" style="padding: 12px;">
                <?php
                $delivery_note_url = add_query_arg( array(
                    'action'   => 'wns_download_delivery_note',
                    'order_id' => $order->get_id(),
                    'nonce'    => wp_create_nonce( 'wns_delivery_note_' . $order->get_id() ),
                ), admin_url( 'admin-ajax.php' ) );
                ?>
                <a href="<?php echo esc_url( $delivery_note_url ); ?>" 
                   class="button button-secondary" 
                   style="width: 100%; text-align: center;"
                   target="_blank">
                    <span class="dashicons dashicons-media-document" style="vertical-align: middle; margin-right: 5px;"></span>
                    <?php esc_html_e( 'Download Delivery Note', 'woo-nalda-sync' ); ?>
                </a>
            </div>
        </div>
        <?php
    }

    /**
     * Save Nalda order meta (legacy)
     *
     * @param int $order_id Order ID.
     */
    public function save_nalda_order_meta( $order_id ) {
        // Verify nonce
        if ( ! isset( $_POST['wns_nalda_order_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wns_nalda_order_nonce'] ) ), 'wns_nalda_order_meta' ) ) {
            return;
        }

        // Check permission
        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Only save for Nalda orders
        $nalda_order_id = $order->get_meta( '_nalda_order_id' );
        if ( empty( $nalda_order_id ) ) {
            return;
        }

        // Save state
        if ( isset( $_POST['_nalda_state'] ) ) {
            $order->update_meta_data( '_nalda_state', sanitize_text_field( wp_unslash( $_POST['_nalda_state'] ) ) );
        }

        // Save expected delivery date
        if ( isset( $_POST['_nalda_expected_delivery_date'] ) ) {
            $order->update_meta_data( '_nalda_expected_delivery_date', sanitize_text_field( wp_unslash( $_POST['_nalda_expected_delivery_date'] ) ) );
        }

        // Save tracking code
        if ( isset( $_POST['_nalda_tracking_code'] ) ) {
            $order->update_meta_data( '_nalda_tracking_code', sanitize_text_field( wp_unslash( $_POST['_nalda_tracking_code'] ) ) );
        }

        $order->save();
    }

    /**
     * Save Nalda order meta (HPOS)
     *
     * @param WC_Order $order Order object.
     */
    public function save_nalda_order_meta_hpos( $order ) {
        // Only process in admin context with POST data
        if ( ! is_admin() || empty( $_POST ) ) {
            return;
        }

        // Verify nonce
        if ( ! isset( $_POST['wns_nalda_order_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wns_nalda_order_nonce'] ) ), 'wns_nalda_order_meta' ) ) {
            return;
        }

        // Check permission
        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            return;
        }

        // Only save for Nalda orders
        $nalda_order_id = $order->get_meta( '_nalda_order_id' );
        if ( empty( $nalda_order_id ) ) {
            return;
        }

        // Save state
        if ( isset( $_POST['_nalda_state'] ) ) {
            $order->update_meta_data( '_nalda_state', sanitize_text_field( wp_unslash( $_POST['_nalda_state'] ) ) );
        }

        // Save expected delivery date
        if ( isset( $_POST['_nalda_expected_delivery_date'] ) ) {
            $order->update_meta_data( '_nalda_expected_delivery_date', sanitize_text_field( wp_unslash( $_POST['_nalda_expected_delivery_date'] ) ) );
        }

        // Save tracking code
        if ( isset( $_POST['_nalda_tracking_code'] ) ) {
            $order->update_meta_data( '_nalda_tracking_code', sanitize_text_field( wp_unslash( $_POST['_nalda_tracking_code'] ) ) );
        }
    }
}
