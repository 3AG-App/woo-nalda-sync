<?php
/**
 * AJAX Handler Class
 * 
 * Handles all AJAX requests for the plugin.
 *
 * @package Woo_Nalda_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Ajax class
 */
class WNS_Ajax {

    /**
     * Constructor
     */
    public function __construct() {
        // Settings
        add_action( 'wp_ajax_wns_save_settings', array( $this, 'save_settings' ) );

        // Connection test
        add_action( 'wp_ajax_wns_test_sftp', array( $this, 'test_sftp' ) );
        add_action( 'wp_ajax_wns_test_nalda_api', array( $this, 'test_nalda_api' ) );

        // Manual triggers
        add_action( 'wp_ajax_wns_run_product_export', array( $this, 'run_product_export' ) );
        add_action( 'wp_ajax_wns_run_order_import', array( $this, 'run_order_import' ) );
        add_action( 'wp_ajax_wns_run_order_status_export', array( $this, 'run_order_status_export' ) );

        // Toggle sync
        add_action( 'wp_ajax_wns_toggle_product_export', array( $this, 'toggle_product_export' ) );
        add_action( 'wp_ajax_wns_toggle_order_import', array( $this, 'toggle_order_import' ) );
        add_action( 'wp_ajax_wns_toggle_order_status_export', array( $this, 'toggle_order_status_export' ) );

        // Status
        add_action( 'wp_ajax_wns_get_sync_status', array( $this, 'get_sync_status' ) );

        // Logs
        add_action( 'wp_ajax_wns_clear_logs', array( $this, 'clear_logs' ) );
        add_action( 'wp_ajax_wns_get_log_details', array( $this, 'get_log_details' ) );

        // License
        add_action( 'wp_ajax_wns_activate_license', array( $this, 'activate_license' ) );
        add_action( 'wp_ajax_wns_activate_domain', array( $this, 'activate_domain' ) );
        add_action( 'wp_ajax_wns_deactivate_license', array( $this, 'deactivate_license' ) );
        add_action( 'wp_ajax_wns_check_license', array( $this, 'check_license' ) );

        // Updates
        add_action( 'wp_ajax_wns_check_update', array( $this, 'check_update' ) );
        add_action( 'wp_ajax_wns_install_update', array( $this, 'install_update' ) );

        // Product column AJAX
        add_action( 'wp_ajax_wns_toggle_product_nalda', array( $this, 'toggle_product_nalda' ) );

        // Delivery note PDF download
        add_action( 'wp_ajax_wns_download_delivery_note', array( $this, 'download_delivery_note' ) );

        // Upload history
        add_action( 'wp_ajax_wns_get_upload_history', array( $this, 'get_upload_history' ) );
    }

    /**
     * Verify nonce and capability
     */
    private function verify_nonce() {
        if ( ! check_ajax_referer( 'wns_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Security check failed.', 'woo-nalda-sync' ),
                )
            );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Permission denied.', 'woo-nalda-sync' ),
                )
            );
        }
    }

    /**
     * Save settings
     */
    public function save_settings() {
        $this->verify_nonce();

        // Validate license
        if ( ! WNS()->license->is_valid() ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Please activate a valid license first.', 'woo-nalda-sync' ),
                )
            );
        }

        // SFTP settings
        if ( isset( $_POST['sftp_host'] ) ) {
            update_option( 'wns_sftp_host', sanitize_text_field( wp_unslash( $_POST['sftp_host'] ) ) );
        }
        if ( isset( $_POST['sftp_port'] ) ) {
            update_option( 'wns_sftp_port', sanitize_text_field( wp_unslash( $_POST['sftp_port'] ) ) );
        }
        if ( isset( $_POST['sftp_username'] ) ) {
            update_option( 'wns_sftp_username', sanitize_text_field( wp_unslash( $_POST['sftp_username'] ) ) );
        }
        if ( isset( $_POST['sftp_password'] ) ) {
            update_option( 'wns_sftp_password', sanitize_text_field( wp_unslash( $_POST['sftp_password'] ) ) );
        }

        // Nalda API settings
        if ( isset( $_POST['nalda_api_key'] ) ) {
            update_option( 'wns_nalda_api_key', sanitize_text_field( wp_unslash( $_POST['nalda_api_key'] ) ) );
        }

        // Product export settings
        if ( isset( $_POST['product_export_interval'] ) ) {
            update_option( 'wns_product_export_interval', sanitize_text_field( wp_unslash( $_POST['product_export_interval'] ) ) );
        }
        if ( isset( $_POST['product_default_behavior'] ) ) {
            update_option( 'wns_product_default_behavior', sanitize_text_field( wp_unslash( $_POST['product_default_behavior'] ) ) );
        }
        // Save product export enabled checkbox
        $product_export_enabled = isset( $_POST['product_export_enabled'] ) && filter_var( wp_unslash( $_POST['product_export_enabled'] ), FILTER_VALIDATE_BOOLEAN );
        update_option( 'wns_product_export_enabled', $product_export_enabled );

        // Order import settings
        if ( isset( $_POST['order_import_interval'] ) ) {
            update_option( 'wns_order_import_interval', sanitize_text_field( wp_unslash( $_POST['order_import_interval'] ) ) );
        }
        if ( isset( $_POST['order_import_range'] ) ) {
            $range = sanitize_text_field( wp_unslash( $_POST['order_import_range'] ) );
            // Validate against allowed Nalda API range values
            $valid_ranges = array( 'today', 'yesterday', 'current-month', 'current-year', '3m', '6m', '12m', '24m' );
            if ( in_array( $range, $valid_ranges, true ) ) {
                update_option( 'wns_order_import_range', $range );
            }
        }
        // Save order import enabled checkbox
        $order_import_enabled = isset( $_POST['order_import_enabled'] ) && filter_var( wp_unslash( $_POST['order_import_enabled'] ), FILTER_VALIDATE_BOOLEAN );
        update_option( 'wns_order_import_enabled', $order_import_enabled );

        // Order status export settings
        if ( isset( $_POST['order_status_export_interval'] ) ) {
            update_option( 'wns_order_status_export_interval', sanitize_text_field( wp_unslash( $_POST['order_status_export_interval'] ) ) );
        }
        // Save order status export enabled checkbox
        $order_status_export_enabled = isset( $_POST['order_status_export_enabled'] ) && filter_var( wp_unslash( $_POST['order_status_export_enabled'] ), FILTER_VALIDATE_BOOLEAN );
        update_option( 'wns_order_status_export_enabled', $order_status_export_enabled );

        // Product default settings (country and currency are taken from WooCommerce settings)
        if ( isset( $_POST['default_delivery_days'] ) ) {
            update_option( 'wns_default_delivery_days', absint( wp_unslash( $_POST['default_delivery_days'] ) ) );
        }
        if ( isset( $_POST['default_return_days'] ) ) {
            update_option( 'wns_default_return_days', absint( wp_unslash( $_POST['default_return_days'] ) ) );
        }

        // Delivery note logo
        if ( isset( $_POST['delivery_note_logo_id'] ) ) {
            update_option( 'wns_delivery_note_logo_id', absint( wp_unslash( $_POST['delivery_note_logo_id'] ) ) );
        }

        // Reschedule crons if intervals changed
        WNS()->scheduler->reschedule_all();

        wp_send_json_success(
            array(
                'message' => __( 'Settings saved successfully.', 'woo-nalda-sync' ),
            )
        );
    }

    /**
     * Test SFTP connection
     */
    public function test_sftp() {
        $this->verify_nonce();

        // Validate license
        if ( ! WNS()->license->is_valid() ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Please activate a valid license first.', 'woo-nalda-sync' ),
                )
            );
        }

        $license_key = get_option( 'wns_license_key', '' );
        if ( empty( $license_key ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'License key is required.', 'woo-nalda-sync' ),
                )
            );
        }

        // Use values from POST (current form values) instead of saved options
        $sftp_host     = isset( $_POST['sftp_host'] ) ? sanitize_text_field( wp_unslash( $_POST['sftp_host'] ) ) : '';
        $sftp_port     = isset( $_POST['sftp_port'] ) ? sanitize_text_field( wp_unslash( $_POST['sftp_port'] ) ) : '2022';
        $sftp_username = isset( $_POST['sftp_username'] ) ? sanitize_text_field( wp_unslash( $_POST['sftp_username'] ) ) : '';
        $sftp_password = isset( $_POST['sftp_password'] ) ? sanitize_text_field( wp_unslash( $_POST['sftp_password'] ) ) : '';

        if ( empty( $sftp_host ) || empty( $sftp_username ) || empty( $sftp_password ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'SFTP credentials are required.', 'woo-nalda-sync' ),
                )
            );
        }

        $response = wp_remote_post(
            WNS_API_BASE_URL . '/nalda/sftp-validate',
            array(
                'timeout' => 30,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ),
                'body'    => wp_json_encode(
                    array(
                        'license_key'   => $license_key,
                        'product_slug'  => WNS_PRODUCT_SLUG,
                        'domain'        => wns_get_domain(),
                        'sftp_host'     => $sftp_host,
                        'sftp_port'     => (int) $sftp_port,
                        'sftp_username' => $sftp_username,
                        'sftp_password' => $sftp_password,
                    )
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error(
                array(
                    'message' => $response->get_error_message(),
                )
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 === $code ) {
            wp_send_json_success(
                array(
                    'message' => __( 'SFTP connection successful!', 'woo-nalda-sync' ),
                )
            );
        } else {
            $message = isset( $body['message'] ) ? $body['message'] : __( 'SFTP connection failed.', 'woo-nalda-sync' );
            wp_send_json_error(
                array(
                    'message' => $message,
                )
            );
        }
    }

    /**
     * Test Nalda API connection
     */
    public function test_nalda_api() {
        $this->verify_nonce();

        // Validate license
        if ( ! WNS()->license->is_valid() ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Please activate a valid license first.', 'woo-nalda-sync' ),
                )
            );
        }

        // Use value from POST (current form value) instead of saved option
        $api_key = isset( $_POST['nalda_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['nalda_api_key'] ) ) : '';
        if ( empty( $api_key ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Nalda API key is required.', 'woo-nalda-sync' ),
                )
            );
        }

        // Use /orders endpoint to validate API key (requires authentication)
        $response = wp_remote_post(
            'https://sellers-api.nalda.com/orders',
            array(
                'timeout' => 15,
                'headers' => array(
                    'X-API-KEY'    => $api_key,
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ),
                'body'    => wp_json_encode(
                    array(
                        'range' => 'today',
                    )
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error(
                array(
                    'message' => sprintf(
                        /* translators: %s: Error message */
                        __( 'Connection failed: %s', 'woo-nalda-sync' ),
                        $response->get_error_message()
                    ),
                )
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        // 200 with success=true means valid API key (with or without orders)
        if ( 200 === $code && isset( $body['success'] ) && $body['success'] ) {
            wp_send_json_success(
                array(
                    'message' => __( 'Nalda API connection successful!', 'woo-nalda-sync' ),
                )
            );
        } elseif ( 401 === $code ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Invalid API key.', 'woo-nalda-sync' ),
                )
            );
        } elseif ( 404 === $code && isset( $body['success'] ) ) {
            // 404 with a proper response structure means API key is valid, just no orders found
            wp_send_json_success(
                array(
                    'message' => __( 'Nalda API connection successful! (No orders found)', 'woo-nalda-sync' ),
                )
            );
        } else {
            $error_message = isset( $body['message'] ) ? $body['message'] : __( 'Validation failed.', 'woo-nalda-sync' );
            wp_send_json_error(
                array(
                    'message' => $error_message,
                )
            );
        }
    }

    /**
     * Run product export
     */
    public function run_product_export() {
        $this->verify_nonce();

        // Validate license
        if ( ! WNS()->license->is_valid() ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Please activate a valid license first.', 'woo-nalda-sync' ),
                )
            );
        }

        // Check rate limit
        $last_run = get_transient( 'wns_product_export_lock' );
        if ( $last_run ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Please wait 30 seconds between manual exports.', 'woo-nalda-sync' ),
                )
            );
        }

        // Set lock
        set_transient( 'wns_product_export_lock', time(), 30 );

        // Run export
        $result = WNS()->product_export->run( 'manual' );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error(
                array(
                    'message' => $result->get_error_message(),
                )
            );
        }

        wp_send_json_success(
            array(
                'message' => sprintf(
                    /* translators: %d: Number of products exported */
                    __( 'Successfully exported %d products.', 'woo-nalda-sync' ),
                    $result['exported']
                ),
                'stats'   => $result,
            )
        );
    }

    /**
     * Run order import
     */
    public function run_order_import() {
        $this->verify_nonce();

        // Validate license
        if ( ! WNS()->license->is_valid() ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Please activate a valid license first.', 'woo-nalda-sync' ),
                )
            );
        }

        // Check rate limit
        $last_run = get_transient( 'wns_order_import_lock' );
        if ( $last_run ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Please wait 30 seconds between manual imports.', 'woo-nalda-sync' ),
                )
            );
        }

        // Set lock
        set_transient( 'wns_order_import_lock', time(), 30 );

        // Run import
        $result = WNS()->order_import->run( 'manual' );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error(
                array(
                    'message' => $result->get_error_message(),
                )
            );
        }

        wp_send_json_success(
            array(
                'message' => sprintf(
                    /* translators: %d: Number of orders imported */
                    __( 'Successfully imported %d orders.', 'woo-nalda-sync' ),
                    $result['imported']
                ),
                'stats'   => $result,
            )
        );
    }

    /**
     * Run order status export
     */
    public function run_order_status_export() {
        $this->verify_nonce();

        // Validate license
        if ( ! WNS()->license->is_valid() ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Please activate a valid license first.', 'woo-nalda-sync' ),
                )
            );
        }

        // Check rate limit
        $last_run = get_transient( 'wns_order_status_export_lock' );
        if ( $last_run ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Please wait 30 seconds between manual exports.', 'woo-nalda-sync' ),
                )
            );
        }

        // Set lock
        set_transient( 'wns_order_status_export_lock', time(), 30 );

        // Run export
        $result = WNS()->order_status_export->run( 'manual' );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error(
                array(
                    'message' => $result->get_error_message(),
                )
            );
        }

        wp_send_json_success(
            array(
                'message' => sprintf(
                    /* translators: %d: Number of order statuses exported */
                    __( 'Successfully exported %d order statuses.', 'woo-nalda-sync' ),
                    $result['exported']
                ),
                'stats'   => $result,
            )
        );
    }

    /**
     * Toggle product export
     */
    public function toggle_product_export() {
        $this->verify_nonce();

        // Validate license
        if ( ! WNS()->license->is_valid() ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Please activate a valid license first.', 'woo-nalda-sync' ),
                )
            );
        }

        $enabled = isset( $_POST['enabled'] ) && 'true' === $_POST['enabled'];
        update_option( 'wns_product_export_enabled', $enabled );

        WNS()->scheduler->reschedule_product_export();

        wp_send_json_success(
            array(
                'message' => $enabled 
                    ? __( 'Product export enabled.', 'woo-nalda-sync' ) 
                    : __( 'Product export disabled.', 'woo-nalda-sync' ),
            )
        );
    }

    /**
     * Toggle order import
     */
    public function toggle_order_import() {
        $this->verify_nonce();

        // Validate license
        if ( ! WNS()->license->is_valid() ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Please activate a valid license first.', 'woo-nalda-sync' ),
                )
            );
        }

        $enabled = isset( $_POST['enabled'] ) && 'true' === $_POST['enabled'];
        update_option( 'wns_order_import_enabled', $enabled );

        WNS()->scheduler->reschedule_order_import();

        wp_send_json_success(
            array(
                'message' => $enabled 
                    ? __( 'Order import enabled.', 'woo-nalda-sync' ) 
                    : __( 'Order import disabled.', 'woo-nalda-sync' ),
            )
        );
    }

    /**
     * Toggle order status export
     */
    public function toggle_order_status_export() {
        $this->verify_nonce();

        // Validate license
        if ( ! WNS()->license->is_valid() ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Please activate a valid license first.', 'woo-nalda-sync' ),
                )
            );
        }

        $enabled = isset( $_POST['enabled'] ) && 'true' === $_POST['enabled'];
        update_option( 'wns_order_status_export_enabled', $enabled );

        WNS()->scheduler->reschedule_order_status_export();

        wp_send_json_success(
            array(
                'message' => $enabled 
                    ? __( 'Order status export enabled.', 'woo-nalda-sync' ) 
                    : __( 'Order status export disabled.', 'woo-nalda-sync' ),
            )
        );
    }

    /**
     * Get sync status
     */
    public function get_sync_status() {
        $this->verify_nonce();

        wp_send_json_success(
            array(
                'product_export'      => array(
                    'enabled'  => get_option( 'wns_product_export_enabled', false ),
                    'last_run' => get_option( 'wns_last_product_export_time', 0 ),
                    'stats'    => get_option( 'wns_last_product_export_stats', array() ),
                    'next_run' => wp_next_scheduled( 'wns_product_export_event' ),
                ),
                'order_import'        => array(
                    'enabled'  => get_option( 'wns_order_import_enabled', false ),
                    'last_run' => get_option( 'wns_last_order_import_time', 0 ),
                    'stats'    => get_option( 'wns_last_order_import_stats', array() ),
                    'next_run' => wp_next_scheduled( 'wns_order_import_event' ),
                ),
                'order_status_export' => array(
                    'enabled'  => get_option( 'wns_order_status_export_enabled', false ),
                    'last_run' => get_option( 'wns_last_order_status_export_time', 0 ),
                    'stats'    => get_option( 'wns_last_order_status_export_stats', array() ),
                    'next_run' => wp_next_scheduled( 'wns_order_status_export_event' ),
                ),
            )
        );
    }

    /**
     * Clear logs
     */
    public function clear_logs() {
        $this->verify_nonce();

        WNS()->logs->clear();

        wp_send_json_success(
            array(
                'message' => __( 'Logs cleared successfully.', 'woo-nalda-sync' ),
            )
        );
    }

    /**
     * Get log details
     */
    public function get_log_details() {
        $this->verify_nonce();

        $log_id = isset( $_POST['log_id'] ) ? absint( $_POST['log_id'] ) : 0;

        if ( ! $log_id ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Invalid log ID.', 'woo-nalda-sync' ),
                )
            );
        }

        $log = WNS()->logs->get( $log_id );

        if ( ! $log ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Log not found.', 'woo-nalda-sync' ),
                )
            );
        }

        wp_send_json_success(
            array(
                'log' => $log,
            )
        );
    }

    /**
     * Activate license
     */
    public function activate_license() {
        $this->verify_nonce();

        $license_key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';

        if ( empty( $license_key ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Please enter a license key.', 'woo-nalda-sync' ),
                )
            );
        }

        $result = WNS()->license->activate( $license_key );

        if ( $result['success'] ) {
            wp_send_json_success(
                array(
                    'message' => __( 'License activated successfully!', 'woo-nalda-sync' ),
                    'data'    => $result['data'],
                )
            );
        } else {
            // Provide user-friendly error messages for common cases
            $message = isset( $result['message'] ) ? $result['message'] : __( 'Activation failed.', 'woo-nalda-sync' );

            // Check for domain limit error
            if ( strpos( $message, 'Domain limit' ) !== false || strpos( $message, 'limit reached' ) !== false ) {
                $message = __( 'Domain activation limit reached. Please deactivate another domain from your license dashboard first.', 'woo-nalda-sync' );
            }

            // Check for license not active error (403)
            if ( strpos( $message, 'not active' ) !== false || strpos( $message, 'is not active' ) !== false ) {
                $message = __( 'This license is not active. It may be expired, suspended, or cancelled.', 'woo-nalda-sync' );
            }

            wp_send_json_error(
                array(
                    'message' => $message,
                )
            );
        }
    }

    /**
     * Activate domain for existing license
     * Used when a license is valid but not activated on this domain
     */
    public function activate_domain() {
        $this->verify_nonce();

        $license_key = WNS()->license->get_key();

        if ( empty( $license_key ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'No license key found. Please enter a license key.', 'woo-nalda-sync' ),
                )
            );
        }

        $result = WNS()->license->activate( $license_key );

        if ( $result['success'] ) {
            wp_send_json_success(
                array(
                    'message' => __( 'Domain activated successfully!', 'woo-nalda-sync' ),
                    'data'    => $result['data'],
                )
            );
        } else {
            // Provide user-friendly error messages for common cases
            $message = isset( $result['message'] ) ? $result['message'] : __( 'Activation failed.', 'woo-nalda-sync' );

            // Check for domain limit error
            if ( strpos( $message, 'Domain limit' ) !== false || strpos( $message, 'limit reached' ) !== false ) {
                $message = __( 'Domain activation limit reached. Please deactivate another domain from your license dashboard first.', 'woo-nalda-sync' );
            }

            wp_send_json_error(
                array(
                    'message' => $message,
                )
            );
        }
    }

    /**
     * Deactivate license
     */
    public function deactivate_license() {
        $this->verify_nonce();

        $result = WNS()->license->deactivate();

        // Disable all syncs and clear restore data
        update_option( 'wns_product_export_enabled', false );
        update_option( 'wns_order_import_enabled', false );
        update_option( 'wns_order_status_export_enabled', false );
        delete_option( 'wns_syncs_disabled_by_license' );

        WNS()->scheduler->unschedule( 'wns_product_export_event' );
        WNS()->scheduler->unschedule( 'wns_order_import_event' );
        WNS()->scheduler->unschedule( 'wns_order_status_export_event' );

        if ( $result['success'] ) {
            wp_send_json_success(
                array(
                    'message' => __( 'License deactivated successfully.', 'woo-nalda-sync' ),
                )
            );
        } else {
            // Still consider it success if we cleared local data
            wp_send_json_success(
                array(
                    'message' => __( 'License deactivated locally.', 'woo-nalda-sync' ),
                )
            );
        }
    }

    /**
     * Check/validate license status
     */
    public function check_license() {
        $this->verify_nonce();

        $result = WNS()->license->validate();

        if ( $result['success'] && isset( $result['data'] ) ) {
            wp_send_json_success(
                array(
                    'valid'     => ! empty( $result['data']['valid'] ),
                    'activated' => ! empty( $result['data']['activated'] ),
                    'data'      => $result['data'],
                )
            );
        } else {
            wp_send_json_error( $result );
        }
    }

    /**
     * Check for plugin updates
     */
    public function check_update() {
        $this->verify_nonce();

        // Force check for updates from GitHub
        WNS()->updater->force_check();

        // Get fresh update data
        $update_data     = get_transient( 'wns_update_data' );
        $current_version = WNS_VERSION;
        $has_update      = $update_data && ! empty( $update_data['version'] ) && version_compare( $current_version, $update_data['version'], '<' );

        if ( $has_update ) {
            wp_send_json_success(
                array(
                    'message'         => sprintf(
                        /* translators: %s: version number */
                        __( 'Update available! Version %s is ready to install.', 'woo-nalda-sync' ),
                        $update_data['version']
                    ),
                    'has_update'      => true,
                    'current_version' => $current_version,
                    'new_version'     => $update_data['version'],
                    'download_url'    => $update_data['download_url'],
                )
            );
        } else {
            wp_send_json_success(
                array(
                    'message'         => __( 'You are running the latest version.', 'woo-nalda-sync' ),
                    'has_update'      => false,
                    'current_version' => $current_version,
                    'new_version'     => $update_data['version'] ?? $current_version,
                )
            );
        }
    }

    /**
     * Install plugin update
     */
    public function install_update() {
        $this->verify_nonce();

        // Check for update data
        $update_data = get_transient( 'wns_update_data' );

        if ( ! $update_data || empty( $update_data['download_url'] ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'No update available or download URL missing.', 'woo-nalda-sync' ),
                )
            );
        }

        // Include required files for plugin update
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';

        // Use a silent skin to prevent output
        $skin     = new WP_Ajax_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader( $skin );

        // Deactivate the plugin before upgrading
        deactivate_plugins( WNS_PLUGIN_BASENAME );

        // Clear the plugin from update cache to force fresh install
        $result = $upgrader->install(
            $update_data['download_url'],
            array(
                'overwrite_package' => true,
            )
        );

        if ( is_wp_error( $result ) ) {
            // Reactivate plugin on failure
            activate_plugin( WNS_PLUGIN_BASENAME );
            wp_send_json_error(
                array(
                    'message' => $result->get_error_message(),
                )
            );
        }

        if ( false === $result ) {
            // Reactivate plugin on failure
            activate_plugin( WNS_PLUGIN_BASENAME );
            wp_send_json_error(
                array(
                    'message' => __( 'Update failed. Please try again or update manually.', 'woo-nalda-sync' ),
                )
            );
        }

        // Reactivate the plugin
        activate_plugin( WNS_PLUGIN_BASENAME );

        // Clear update cache
        WNS()->updater->clear_cache();
        delete_site_transient( 'update_plugins' );

        wp_send_json_success(
            array(
                'message'     => sprintf(
                    /* translators: %s: version number */
                    __( 'Successfully updated to version %s. Please refresh the page.', 'woo-nalda-sync' ),
                    $update_data['version']
                ),
                'new_version' => $update_data['version'],
                'reload'      => true,
            )
        );
    }

    /**
     * Toggle product Nalda export status
     */
    public function toggle_product_nalda() {
        $this->verify_nonce();

        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        $status     = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : 'default';

        if ( ! $product_id ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Invalid product ID.', 'woo-nalda-sync' ),
                )
            );
        }

        update_post_meta( $product_id, '_wns_nalda_export', $status );

        wp_send_json_success(
            array(
                'message' => __( 'Product updated.', 'woo-nalda-sync' ),
            )
        );
    }

    /**
     * Download delivery note PDF
     */
    public function download_delivery_note() {
        // Get order ID from request.
        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        
        // Get language from request.
        $language = isset( $_GET['lang'] ) ? sanitize_text_field( wp_unslash( $_GET['lang'] ) ) : '';

        if ( ! $order_id ) {
            wp_die( __( 'Invalid order ID.', 'woo-nalda-sync' ) );
        }

        // Verify nonce.
        if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['nonce'] ) ), 'wns_delivery_note_' . $order_id ) ) {
            wp_die( __( 'Security check failed.', 'woo-nalda-sync' ) );
        }

        // Check capability.
        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_die( __( 'You do not have permission to access this page.', 'woo-nalda-sync' ) );
        }

        // Get order.
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_die( __( 'Order not found.', 'woo-nalda-sync' ) );
        }

        // Load the PDF generator class if not already loaded.
        if ( ! class_exists( 'WNS_Delivery_Note_PDF' ) ) {
            require_once WNS_PLUGIN_DIR . 'includes/class-delivery-note-pdf.php';
        }

        // Generate and output PDF with language parameter.
        $pdf_generator = new WNS_Delivery_Note_PDF( $order, $language );
        $pdf_generator->generate();

        // The generate() method exits, so this won't be reached.
        exit;
    }

    /**
     * Get upload history from API
     */
    public function get_upload_history() {
        $this->verify_nonce();

        // Check license
        if ( ! WNS()->license->is_valid() ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Please activate a valid license first.', 'woo-nalda-sync' ),
                )
            );
        }

        $page     = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
        $per_page = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 15;

        // Get credentials
        $license_key = get_option( 'wns_license_key', '' );
        $domain      = wns_get_domain();

        if ( empty( $license_key ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'License key not configured.', 'woo-nalda-sync' ),
                )
            );
        }

        // Build API URL
        $api_url = add_query_arg(
            array(
                'license_key'  => $license_key,
                'product_slug' => WNS_PRODUCT_SLUG,
                'domain'       => $domain,
                'page'         => $page,
                'per_page'     => $per_page,
            ),
            WNS_API_BASE_URL . '/nalda/csv-upload/list'
        );

        // Make API request
        $response = wp_remote_get(
            $api_url,
            array(
                'timeout' => 30,
                'headers' => array(
                    'Accept' => 'application/json',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error(
                array(
                    'message' => $response->get_error_message(),
                )
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $data        = json_decode( $body, true );

        if ( $status_code !== 200 ) {
            $error_message = isset( $data['message'] ) ? $data['message'] : __( 'Failed to fetch upload history.', 'woo-nalda-sync' );
            wp_send_json_error(
                array(
                    'message' => $error_message,
                )
            );
        }

        wp_send_json_success(
            array(
                'uploads'    => isset( $data['data'] ) ? $data['data'] : array(),
                'pagination' => isset( $data['meta'] ) ? $data['meta'] : array(),
            )
        );
    }
}
