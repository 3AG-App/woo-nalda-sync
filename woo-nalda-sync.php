<?php
/**
 * Plugin Name: WooCommerce Nalda Marketplace Sync
 * Plugin URI: https://3ag.de/plugins/woo-nalda-sync
 * Description: Sync WooCommerce products and orders with Nalda.com marketplace. Export products, import orders, and update order statuses.
 * Version: 1.0.3
 * Author: 3AG Systems
 * Author URI: https://3ag.de
 * Text Domain: woo-nalda-sync
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 8.5
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 *
 * @package Woo_Nalda_Sync
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'WNS_VERSION', '1.0.3' );
define( 'WNS_PLUGIN_FILE', __FILE__ );
define( 'WNS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WNS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WNS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'WNS_PRODUCT_SLUG', 'woo-nalda-sync' );
define( 'WNS_API_BASE_URL', 'https://3ag.app/api/v3' );

/**
 * Get clean domain for license validation
 * 
 * @return string The clean domain
 */
function wns_get_domain() {
    $site_url = site_url();
    $parsed   = wp_parse_url( $site_url );
    $domain   = isset( $parsed['host'] ) ? $parsed['host'] : '';

    // Remove www prefix
    $domain = preg_replace( '/^www\./', '', $domain );

    // Remove port if present
    $domain = preg_replace( '/:\d+$/', '', $domain );

    return $domain;
}

/**
 * Main Plugin Class
 */
final class Woo_Nalda_Sync {

    /**
     * Single instance of the class
     *
     * @var Woo_Nalda_Sync
     */
    private static $instance = null;

    /**
     * Plugin components
     */
    public $license;
    public $admin;
    public $ajax;
    public $scheduler;
    public $logs;
    public $updater;
    public $product_export;
    public $order_import;
    public $order_status_export;

    /**
     * Get single instance of the class
     *
     * @return Woo_Nalda_Sync
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Include required files
     */
    private function includes() {
        // Core classes
        require_once WNS_PLUGIN_DIR . 'includes/class-license.php';
        require_once WNS_PLUGIN_DIR . 'includes/class-logs.php';
        require_once WNS_PLUGIN_DIR . 'includes/class-admin.php';
        require_once WNS_PLUGIN_DIR . 'includes/class-ajax.php';
        require_once WNS_PLUGIN_DIR . 'includes/class-scheduler.php';
        require_once WNS_PLUGIN_DIR . 'includes/class-updater.php';

        // Sync classes
        require_once WNS_PLUGIN_DIR . 'includes/class-product-export.php';
        require_once WNS_PLUGIN_DIR . 'includes/class-order-import.php';
        require_once WNS_PLUGIN_DIR . 'includes/class-order-status-export.php';
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Check WooCommerce dependency
        add_action( 'plugins_loaded', array( $this, 'check_woocommerce' ) );

        // Activation and deactivation hooks
        register_activation_hook( WNS_PLUGIN_FILE, array( $this, 'activate' ) );
        register_deactivation_hook( WNS_PLUGIN_FILE, array( $this, 'deactivate' ) );

        // Declare HPOS compatibility
        add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );

        // Initialize components
        add_action( 'plugins_loaded', array( $this, 'init_components' ), 20 );

        // Load text domain
        add_action( 'init', array( $this, 'load_textdomain' ) );
    }

    /**
     * Check if WooCommerce is active
     */
    public function check_woocommerce() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
            return false;
        }
        return true;
    }

    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><?php esc_html_e( 'WooCommerce Nalda Sync requires WooCommerce to be installed and activated.', 'woo-nalda-sync' ); ?></p>
        </div>
        <?php
    }

    /**
     * Declare HPOS compatibility
     */
    public function declare_hpos_compatibility() {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WNS_PLUGIN_FILE, true );
        }
    }

    /**
     * Initialize plugin components
     */
    public function init_components() {
        if ( ! $this->check_woocommerce() ) {
            return;
        }

        $this->license              = new WNS_License();
        $this->logs                 = new WNS_Logs();
        $this->admin                = new WNS_Admin();
        $this->ajax                 = new WNS_Ajax();
        $this->scheduler            = new WNS_Scheduler();
        $this->updater              = new WNS_Updater();
        $this->product_export       = new WNS_Product_Export();
        $this->order_import         = new WNS_Order_Import();
        $this->order_status_export  = new WNS_Order_Status_Export();
    }

    /**
     * Load plugin text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain( 'woo-nalda-sync', false, dirname( WNS_PLUGIN_BASENAME ) . '/languages' );
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Create logs table
        $this->create_logs_table();

        // Set default options
        $this->set_default_options();

        // Clear any scheduled events
        wp_clear_scheduled_hook( 'wns_product_export_event' );
        wp_clear_scheduled_hook( 'wns_order_import_event' );
        wp_clear_scheduled_hook( 'wns_order_status_export_event' );
        wp_clear_scheduled_hook( 'wns_watchdog_check' );
        wp_clear_scheduled_hook( 'wns_license_check' );

        // Schedule watchdog
        if ( ! wp_next_scheduled( 'wns_watchdog_check' ) ) {
            wp_schedule_event( time(), 'hourly', 'wns_watchdog_check' );
        }

        // Schedule daily license check
        if ( ! wp_next_scheduled( 'wns_license_check' ) ) {
            wp_schedule_event( time(), 'daily', 'wns_license_check' );
        }

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear all scheduled events
        wp_clear_scheduled_hook( 'wns_product_export_event' );
        wp_clear_scheduled_hook( 'wns_order_import_event' );
        wp_clear_scheduled_hook( 'wns_order_status_export_event' );
        wp_clear_scheduled_hook( 'wns_watchdog_check' );
        wp_clear_scheduled_hook( 'wns_license_check' );

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Create logs table
     */
    private function create_logs_table() {
        // Use the static method from WNS_Logs class
        WNS_Logs::create_table();
    }

    /**
     * Get custom cron intervals definition
     * Shared between main plugin and scheduler for DRY
     *
     * @return array
     */
    public static function get_custom_cron_intervals() {
        return WNS_Scheduler::get_custom_cron_intervals();
    }

    /**
     * Set default options
     */
    private function set_default_options() {
        // SFTP settings
        add_option( 'wns_sftp_host', '' );
        add_option( 'wns_sftp_port', '2022' );
        add_option( 'wns_sftp_username', '' );
        add_option( 'wns_sftp_password', '' );

        // Nalda API settings
        add_option( 'wns_nalda_api_key', '' );

        // Product export settings
        add_option( 'wns_product_export_enabled', false );
        add_option( 'wns_product_export_interval', 'daily' );
        add_option( 'wns_product_default_behavior', 'include' ); // include or exclude

        // Order import settings
        add_option( 'wns_order_import_enabled', false );
        add_option( 'wns_order_import_interval', 'hourly' );
        add_option( 'wns_order_import_range', 'today' );

        // Order status export settings
        add_option( 'wns_order_status_export_enabled', false );
        add_option( 'wns_order_status_export_interval', 'hourly' );

        // Product default settings (country and currency are taken from WooCommerce settings)
        add_option( 'wns_default_delivery_days', '5' );
        add_option( 'wns_default_return_days', '14' );

        // License settings
        add_option( 'wns_license_key', '' );
        add_option( 'wns_license_status', '' );
        add_option( 'wns_license_data', array() );
        add_option( 'wns_license_last_check', 0 );

        // Stats
        add_option( 'wns_last_product_export_time', 0 );
        add_option( 'wns_last_product_export_stats', array() );
        add_option( 'wns_last_order_import_time', 0 );
        add_option( 'wns_last_order_import_stats', array() );
        add_option( 'wns_last_order_status_export_time', 0 );
        add_option( 'wns_last_order_status_export_stats', array() );
    }

    /**
     * Get plugin instance
     *
     * @return Woo_Nalda_Sync
     */
    public static function get_instance() {
        return self::instance();
    }
}

/**
 * Returns the main instance of Woo_Nalda_Sync
 *
 * @return Woo_Nalda_Sync
 */
function WNS() {
    return Woo_Nalda_Sync::instance();
}

/**
 * Get plugin settings
 *
 * @return array All plugin settings
 */
function wns_get_settings() {
    return array(
        'sftp_host'                    => get_option( 'wns_sftp_host', '' ),
        'sftp_port'                    => get_option( 'wns_sftp_port', '2022' ),
        'sftp_username'                => get_option( 'wns_sftp_username', '' ),
        'sftp_password'                => get_option( 'wns_sftp_password', '' ),
        'nalda_api_key'                => get_option( 'wns_nalda_api_key', '' ),
        'product_export_enabled'       => get_option( 'wns_product_export_enabled', false ),
        'product_export_interval'      => get_option( 'wns_product_export_interval', 'daily' ),
        'product_default_behavior'     => get_option( 'wns_product_default_behavior', 'include' ),
        'order_import_enabled'         => get_option( 'wns_order_import_enabled', false ),
        'order_import_interval'        => get_option( 'wns_order_import_interval', 'hourly' ),
        'order_import_range'           => get_option( 'wns_order_import_range', 'today' ),
        'order_status_export_enabled'  => get_option( 'wns_order_status_export_enabled', false ),
        'order_status_export_interval' => get_option( 'wns_order_status_export_interval', 'hourly' ),
        'default_delivery_days'        => get_option( 'wns_default_delivery_days', '5' ),
        'default_return_days'          => get_option( 'wns_default_return_days', '14' ),
        'delivery_note_logo_id'        => get_option( 'wns_delivery_note_logo_id', 0 ),
    );
}

// Initialize the plugin
WNS();
