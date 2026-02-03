<?php
/**
 * WooCommerce Nalda Marketplace Sync Uninstall
 *
 * Fires when the plugin is deleted.
 * Cleans up all plugin data from the database.
 *
 * @package Woo_Nalda_Sync
 * @since 1.0.0
 */

// Exit if not called from WordPress uninstaller
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Verify this is a valid uninstall request
if ( ! current_user_can( 'activate_plugins' ) ) {
    return;
}

/**
 * Deactivate license from 3AG License API
 * This frees up the activation slot for use on another domain
 */
function wns_uninstall_deactivate_license() {
    $license_key = get_option( 'wns_license_key' );

    if ( empty( $license_key ) ) {
        return;
    }

    // Get the domain
    $site_url = site_url();
    $parsed   = wp_parse_url( $site_url );
    $domain   = isset( $parsed['host'] ) ? $parsed['host'] : '';
    $domain   = preg_replace( '/^www\./', '', $domain );
    $domain   = preg_replace( '/:\d+$/', '', $domain );

    if ( empty( $domain ) ) {
        return;
    }

    // Make API request to deactivate
    wp_remote_post(
        'https://3ag.app/api/v3/licenses/deactivate',
        array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ),
            'body'    => wp_json_encode(
                array(
                    'license_key'  => $license_key,
                    'product_slug' => 'woo-nalda-sync',
                    'domain'       => $domain,
                )
            ),
        )
    );

    // We don't need to check the response - best effort deactivation
    // The license will still be deleted locally regardless
}

/**
 * Clean up all plugin data
 */
function wns_uninstall_cleanup() {
    global $wpdb;

    // First, deactivate the license from the API
    wns_uninstall_deactivate_license();

    // Delete all plugin options
    $options_to_delete = array(
        // SFTP settings
        'wns_sftp_host',
        'wns_sftp_port',
        'wns_sftp_username',
        'wns_sftp_password',
        // Nalda API settings
        'wns_nalda_api_key',
        // Product export settings
        'wns_product_export_enabled',
        'wns_product_export_interval',
        'wns_product_default_behavior',
        // Order import settings
        'wns_order_import_enabled',
        'wns_order_import_interval',
        'wns_order_import_range',
        // Order status export settings
        'wns_order_status_export_enabled',
        'wns_order_status_export_interval',
        // Product default settings (country and currency are from WooCommerce)
        'wns_default_delivery_days',
        'wns_default_return_days',
        // Legacy options (for cleanup from older versions)
        'wns_default_country',
        'wns_default_currency',
        // License
        'wns_license_key',
        'wns_license_status',
        'wns_license_data',
        'wns_license_last_check',
        'wns_syncs_disabled_by_license',
        // Sync tracking
        'wns_last_product_export_time',
        'wns_last_product_export_stats',
        'wns_last_order_import_time',
        'wns_last_order_import_stats',
        'wns_last_order_status_export_time',
        'wns_last_order_status_export_stats',
        'wns_watchdog_last_check',
        // Database
        'wns_db_version',
    );

    foreach ( $options_to_delete as $option ) {
        delete_option( $option );
    }

    // Delete transients
    $transients_to_delete = array(
        'wns_update_data',
        'wns_product_export_lock',
        'wns_order_import_lock',
        'wns_order_status_export_lock',
        'wns_product_export_running',
        'wns_order_import_running',
        'wns_order_status_export_running',
        'wns_update_info',
    );

    foreach ( $transients_to_delete as $transient ) {
        delete_transient( $transient );
    }

    // Unschedule all cron events
    $cron_hooks = array(
        'wns_product_export_event',
        'wns_order_import_event',
        'wns_order_status_export_event',
        'wns_watchdog_check',
        'wns_license_check',
        'wns_update_check',
    );

    foreach ( $cron_hooks as $hook ) {
        $timestamp = wp_next_scheduled( $hook );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, $hook );
        }
        // Clear all events for this hook
        wp_clear_scheduled_hook( $hook );
    }

    // Drop the logs table
    $table_name = $wpdb->prefix . 'wns_logs';
    $wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

    // Optionally clean up product meta data (uncomment if desired on full uninstall)
    // $wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_wns_nalda_export' ) );

    // Optionally clean up order meta (uncomment if desired on full uninstall)
    // $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_nalda_%'" );
    // $wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_key LIKE '_nalda_%'" );

    // Clear any cached data
    wp_cache_flush();
}

// Run cleanup
wns_uninstall_cleanup();
