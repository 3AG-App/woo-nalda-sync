<?php
/**
 * License Management Class
 * 
 * Handles license validation, activation, deactivation, and status checks
 * using the 3AG License API v3.
 *
 * @see /docs/api/LICENSE_API.md for API documentation
 * @package Woo_Nalda_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * License class
 */
class WNS_License {

    /**
     * API Base URL - uses global constant for consistency
     */
    const API_URL = WNS_API_BASE_URL;

    /**
     * Product slug for API
     */
    const PRODUCT_SLUG = 'woo-nalda-sync';

    /**
     * Option keys
     */
    const OPTION_LICENSE_KEY    = 'wns_license_key';
    const OPTION_LICENSE_STATUS = 'wns_license_status';
    const OPTION_LICENSE_DATA   = 'wns_license_data';
    const OPTION_LAST_CHECK     = 'wns_license_last_check';

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'wns_license_check', array( $this, 'daily_check' ) );

        // Schedule daily license check if not exists
        if ( ! wp_next_scheduled( 'wns_license_check' ) ) {
            wp_schedule_event( time(), 'daily', 'wns_license_check' );
        }
    }

    /**
     * Get current domain (normalized)
     *
     * @return string The clean domain
     */
    private function get_domain() {
        return wns_get_domain();
    }

    /**
     * Make API request to the 3AG License API
     *
     * @param string $endpoint The API endpoint.
     * @param array  $body     The request body.
     * @return array Response with 'success', 'data', 'message' keys
     */
    private function api_request( $endpoint, $body ) {
        $response = wp_remote_post(
            self::API_URL . $endpoint,
            array(
                'timeout' => 30,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ),
                'body'    => wp_json_encode( $body ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return array(
                'success' => false,
                'message' => $response->get_error_message(),
            );
        }

        $code          = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $data          = json_decode( $response_body, true );

        // 204 No Content - successful deactivation
        if ( 204 === $code ) {
            return array(
                'success' => true,
                'message' => __( 'Operation successful.', 'woo-nalda-sync' ),
            );
        }

        // 2xx Success responses
        if ( $code >= 200 && $code < 300 ) {
            return array(
                'success' => true,
                'data'    => isset( $data['data'] ) ? $data['data'] : $data,
            );
        }

        // Error responses (401, 403, 404, 422, etc.)
        return array(
            'success' => false,
            'message' => isset( $data['message'] ) ? $data['message'] : __( 'Unknown error occurred.', 'woo-nalda-sync' ),
            'errors'  => isset( $data['errors'] ) ? $data['errors'] : array(),
        );
    }

    /**
     * Validate license and get full license details
     *
     * Use this for:
     * - Displaying license status on settings pages
     * - Periodic license verification (daily cron)
     * - Checking if activation is required
     *
     * @param string|null $license_key The license key (uses stored key if null).
     * @return array API response with license data
     */
    public function validate( $license_key = null ) {
        if ( ! $license_key ) {
            $license_key = get_option( self::OPTION_LICENSE_KEY );
        }

        if ( ! $license_key ) {
            return array(
                'success' => false,
                'message' => __( 'No license key found.', 'woo-nalda-sync' ),
            );
        }

        $result = $this->api_request(
            '/licenses/validate',
            array(
                'license_key'  => $license_key,
                'product_slug' => self::PRODUCT_SLUG,
                'domain'       => $this->get_domain(),
            )
        );

        if ( $result['success'] && isset( $result['data'] ) ) {
            update_option( self::OPTION_LAST_CHECK, time() );

            // Check both valid and activated flags
            $is_valid     = ! empty( $result['data']['valid'] );
            $is_activated = ! empty( $result['data']['activated'] );

            if ( $is_valid && $is_activated ) {
                update_option( self::OPTION_LICENSE_STATUS, 'active' );
                update_option( self::OPTION_LICENSE_DATA, $result['data'] );
            } elseif ( $is_valid && ! $is_activated ) {
                // License is valid but not activated on this domain
                update_option( self::OPTION_LICENSE_STATUS, 'not_activated' );
                update_option( self::OPTION_LICENSE_DATA, $result['data'] );
            } else {
                // License is not valid (expired, suspended, etc.)
                update_option( self::OPTION_LICENSE_STATUS, 'invalid' );
                update_option( self::OPTION_LICENSE_DATA, $result['data'] );
            }
        } elseif ( ! $result['success'] ) {
            // API error (401 invalid key, 422 validation error, etc.)
            // Clear local status but keep the key so user knows what was entered
            update_option( self::OPTION_LICENSE_STATUS, 'invalid' );
            delete_option( self::OPTION_LICENSE_DATA );
        }

        return $result;
    }

    /**
     * Activate license for this domain
     *
     * Call this when:
     * - Plugin is first installed
     * - User enters a new license key
     *
     * @param string $license_key The license key to activate.
     * @return array API response
     */
    public function activate( $license_key ) {
        if ( empty( $license_key ) ) {
            return array(
                'success' => false,
                'message' => __( 'License key is required.', 'woo-nalda-sync' ),
            );
        }

        $result = $this->api_request(
            '/licenses/activate',
            array(
                'license_key'  => $license_key,
                'product_slug' => self::PRODUCT_SLUG,
                'domain'       => $this->get_domain(),
            )
        );

        if ( $result['success'] && isset( $result['data'] ) ) {
            // Store license info locally
            update_option( self::OPTION_LICENSE_KEY, $license_key );
            update_option( self::OPTION_LICENSE_STATUS, 'active' );
            update_option( self::OPTION_LICENSE_DATA, $result['data'] );
            update_option( self::OPTION_LAST_CHECK, time() );
        }

        return $result;
    }

    /**
     * Deactivate license for this domain
     *
     * Call this when:
     * - Plugin is deactivated or uninstalled
     * - User wants to move license to another domain
     *
     * @param string|null $license_key Optional license key.
     * @return array API response
     */
    public function deactivate( $license_key = null ) {
        if ( ! $license_key ) {
            $license_key = get_option( self::OPTION_LICENSE_KEY );
        }

        if ( ! $license_key ) {
            return array(
                'success' => false,
                'message' => __( 'No license key found.', 'woo-nalda-sync' ),
            );
        }

        $result = $this->api_request(
            '/licenses/deactivate',
            array(
                'license_key'  => $license_key,
                'product_slug' => self::PRODUCT_SLUG,
                'domain'       => $this->get_domain(),
            )
        );

        // Always clear local data on deactivation attempt
        // (even if API fails, we want to allow re-activation)
        $this->clear_local_data();

        return $result;
    }

    /**
     * Clear all local license data
     */
    private function clear_local_data() {
        delete_option( self::OPTION_LICENSE_KEY );
        delete_option( self::OPTION_LICENSE_STATUS );
        delete_option( self::OPTION_LICENSE_DATA );
        delete_option( self::OPTION_LAST_CHECK );
        delete_option( 'wns_syncs_disabled_by_license' );
    }

    /**
     * Daily license verification (cron job)
     *
     * Verifies the license is still valid and activated.
     * Disables sync if license becomes invalid.
     * Re-enables sync if license becomes valid again (if it was previously disabled by license check).
     */
    public function daily_check() {
        $license_key = get_option( self::OPTION_LICENSE_KEY );
        if ( ! $license_key ) {
            return;
        }

        $result = $this->validate( $license_key );

        // Check if license is no longer valid or activated
        $is_valid = $result['success']
            && isset( $result['data']['valid'] )
            && true === $result['data']['valid'];

        $is_activated = $result['success']
            && isset( $result['data']['activated'] )
            && true === $result['data']['activated'];

        if ( $is_valid && $is_activated ) {
            // License is valid - check if we need to restore sync
            $this->maybe_restore_sync();
        } else {
            // License is no longer valid, disable sync
            $this->disable_sync_due_to_license( $result );
        }
    }

    /**
     * Disable sync due to license issues
     * Stores the previous state so it can be restored later
     *
     * @param array $result The API validation result.
     */
    private function disable_sync_due_to_license( $result ) {
        // Check current states of all syncs
        $product_export_enabled      = get_option( 'wns_product_export_enabled', false );
        $order_import_enabled        = get_option( 'wns_order_import_enabled', false );
        $order_status_export_enabled = get_option( 'wns_order_status_export_enabled', false );

        // Only take action if at least one sync is currently enabled
        if ( ! $product_export_enabled && ! $order_import_enabled && ! $order_status_export_enabled ) {
            return;
        }

        // Store the previous states so we can restore them later
        // This is important because we have 3 independent syncs
        $previous_states = array(
            'product_export'      => $product_export_enabled,
            'order_import'        => $order_import_enabled,
            'order_status_export' => $order_status_export_enabled,
        );
        update_option( 'wns_syncs_disabled_by_license', $previous_states );

        // Disable all syncs
        update_option( 'wns_product_export_enabled', false );
        update_option( 'wns_order_import_enabled', false );
        update_option( 'wns_order_status_export_enabled', false );

        // Clear scheduled events
        wp_clear_scheduled_hook( 'wns_product_export_event' );
        wp_clear_scheduled_hook( 'wns_order_import_event' );
        wp_clear_scheduled_hook( 'wns_order_status_export_event' );

        // Determine the reason
        $reason   = __( 'License validation failed.', 'woo-nalda-sync' );
        $is_valid = isset( $result['data']['valid'] ) && true === $result['data']['valid'];

        if ( $result['success'] && isset( $result['data'] ) ) {
            if ( ! $is_valid ) {
                $status = isset( $result['data']['status'] ) ? $result['data']['status'] : 'unknown';
                $reason = sprintf(
                    /* translators: %s: license status */
                    __( 'License is %s.', 'woo-nalda-sync' ),
                    $status
                );
            } else {
                $reason = __( 'License is not activated on this domain.', 'woo-nalda-sync' );
            }
        }

        // Log the event
        if ( function_exists( 'WNS' ) && WNS()->logs ) {
            WNS()->logs->add(
                array(
                    'type'    => 'license',
                    'status'  => 'error',
                    'message' => $reason . ' ' . __( 'All syncs have been disabled.', 'woo-nalda-sync' ),
                )
            );
        }
    }

    /**
     * Restore syncs if they were previously disabled due to license issues
     */
    private function maybe_restore_sync() {
        $previous_states = get_option( 'wns_syncs_disabled_by_license', false );

        if ( ! $previous_states || ! is_array( $previous_states ) ) {
            return;
        }

        // Clear the stored states
        delete_option( 'wns_syncs_disabled_by_license' );

        $restored_syncs = array();

        // Restore product export if it was previously enabled
        if ( ! empty( $previous_states['product_export'] ) ) {
            update_option( 'wns_product_export_enabled', true );
            if ( function_exists( 'WNS' ) && WNS()->scheduler ) {
                WNS()->scheduler->reschedule_product_export();
            }
            $restored_syncs[] = __( 'Product Export', 'woo-nalda-sync' );
        }

        // Restore order import if it was previously enabled
        if ( ! empty( $previous_states['order_import'] ) ) {
            update_option( 'wns_order_import_enabled', true );
            if ( function_exists( 'WNS' ) && WNS()->scheduler ) {
                WNS()->scheduler->reschedule_order_import();
            }
            $restored_syncs[] = __( 'Order Import', 'woo-nalda-sync' );
        }

        // Restore order status export if it was previously enabled
        if ( ! empty( $previous_states['order_status_export'] ) ) {
            update_option( 'wns_order_status_export_enabled', true );
            if ( function_exists( 'WNS' ) && WNS()->scheduler ) {
                WNS()->scheduler->reschedule_order_status_export();
            }
            $restored_syncs[] = __( 'Order Status Export', 'woo-nalda-sync' );
        }

        // Log the restoration
        if ( ! empty( $restored_syncs ) && function_exists( 'WNS' ) && WNS()->logs ) {
            WNS()->logs->add(
                array(
                    'type'    => 'license',
                    'status'  => 'success',
                    'message' => sprintf(
                        /* translators: %s: list of restored syncs */
                        __( 'License is now valid. Restored syncs: %s', 'woo-nalda-sync' ),
                        implode( ', ', $restored_syncs )
                    ),
                )
            );
        }
    }

    /**
     * Check if license is valid and activated
     *
     * @return bool True if license is valid and activated on this domain
     */
    public function is_valid() {
        $status      = get_option( self::OPTION_LICENSE_STATUS );
        $license_key = get_option( self::OPTION_LICENSE_KEY );

        return ! empty( $license_key ) && 'active' === $status;
    }

    /**
     * Check if license needs activation
     *
     * @return bool True if license is valid but not activated on this domain
     */
    public function needs_activation() {
        $status = get_option( self::OPTION_LICENSE_STATUS );
        return 'not_activated' === $status;
    }

    /**
     * Get stored license data
     *
     * @return array License data including expires_at, activations, product, package
     */
    public function get_license_data() {
        return get_option( self::OPTION_LICENSE_DATA, array() );
    }

    /**
     * Get stored license key
     *
     * @return string|false The license key or false if not set
     */
    public function get_key() {
        return get_option( self::OPTION_LICENSE_KEY, false );
    }

    /**
     * Get license status
     *
     * @return string Status: 'active', 'not_activated', 'invalid', or empty string
     */
    public function get_status() {
        return get_option( self::OPTION_LICENSE_STATUS, '' );
    }

    /**
     * Get last verification timestamp
     *
     * @return int|false Unix timestamp or false if never checked
     */
    public function get_last_check() {
        return get_option( self::OPTION_LAST_CHECK, false );
    }

    /**
     * Get license expiry date
     *
     * @return string|null ISO 8601 date string or null for lifetime licenses
     */
    public function get_expiry() {
        $data = $this->get_license_data();
        return isset( $data['expires_at'] ) ? $data['expires_at'] : null;
    }

    /**
     * Check if license is expired
     *
     * @return bool True if license has passed its expiration date
     */
    public function is_expired() {
        $expires_at = $this->get_expiry();

        if ( ! $expires_at ) {
            // Lifetime license - never expires
            return false;
        }

        $expiry_time = strtotime( $expires_at );
        return $expiry_time < time();
    }

    /**
     * Get remaining days until expiry
     *
     * @return int|null Days remaining, 0 if expired, null for lifetime licenses
     */
    public function get_remaining_days() {
        $expires_at = $this->get_expiry();

        if ( ! $expires_at ) {
            return null; // Lifetime license
        }

        $expiry_time = strtotime( $expires_at );
        $diff        = $expiry_time - time();

        return max( 0, (int) floor( $diff / DAY_IN_SECONDS ) );
    }

    /**
     * Get activations info
     *
     * @return array Array with 'limit' and 'used' keys
     */
    public function get_activations() {
        $data = $this->get_license_data();
        return isset( $data['activations'] ) ? $data['activations'] : array(
            'limit' => 0,
            'used'  => 0,
        );
    }

    /**
     * Get product name
     *
     * @return string Product name
     */
    public function get_product_name() {
        $data = $this->get_license_data();
        return isset( $data['product'] ) ? $data['product'] : '';
    }

    /**
     * Get package/tier name
     *
     * @return string Package name
     */
    public function get_package() {
        $data = $this->get_license_data();
        return isset( $data['package'] ) ? $data['package'] : '';
    }

    /**
     * Get license API status (active, paused, suspended, expired, cancelled)
     *
     * @return string License status from API
     */
    public function get_api_status() {
        $data = $this->get_license_data();
        return isset( $data['status'] ) ? $data['status'] : '';
    }
}
