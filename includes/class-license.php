<?php
/**
 * License Management Class
 * 
 * Handles license validation, activation, deactivation, and status checks
 * using the 3AG License API.
 *
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
     * @deprecated Use WNS_API_BASE_URL constant instead
     */
    const API_URL = WNS_API_BASE_URL;

    /**
     * Product slug for API
     */
    const PRODUCT_SLUG = 'woo-nalda-sync';

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
     * Get current domain
     *
     * @return string
     */
    private function get_domain() {
        return wns_get_domain();
    }

    /**
     * Make API request
     *
     * @param string $endpoint API endpoint.
     * @param array  $body Request body.
     * @return array
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

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( 204 === $code ) {
            return array(
                'success' => true,
                'message' => __( 'Operation successful.', 'woo-nalda-sync' ),
            );
        }

        if ( $code >= 200 && $code < 300 ) {
            return array(
                'success' => true,
                'data'    => isset( $data['data'] ) ? $data['data'] : $data,
            );
        }

        return array(
            'success' => false,
            'message' => isset( $data['message'] ) ? $data['message'] : __( 'Unknown error occurred.', 'woo-nalda-sync' ),
            'errors'  => isset( $data['errors'] ) ? $data['errors'] : array(),
        );
    }

    /**
     * Validate license key
     *
     * @param string $license_key License key.
     * @return array
     */
    public function validate( $license_key ) {
        return $this->api_request(
            '/licenses/validate',
            array(
                'license_key'  => $license_key,
                'product_slug' => self::PRODUCT_SLUG,
            )
        );
    }

    /**
     * Activate license for this domain
     *
     * @param string $license_key License key.
     * @return array
     */
    public function activate( $license_key ) {
        $result = $this->api_request(
            '/licenses/activate',
            array(
                'license_key'  => $license_key,
                'product_slug' => self::PRODUCT_SLUG,
                'domain'       => $this->get_domain(),
            )
        );

        if ( $result['success'] ) {
            update_option( 'wns_license_key', $license_key );
            update_option( 'wns_license_status', 'active' );
            update_option( 'wns_license_data', $result['data'] );
            update_option( 'wns_license_last_check', time() );
        }

        return $result;
    }

    /**
     * Deactivate license for this domain
     *
     * @param string $license_key Optional license key.
     * @return array
     */
    public function deactivate( $license_key = null ) {
        if ( ! $license_key ) {
            $license_key = get_option( 'wns_license_key' );
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

        if ( $result['success'] ) {
            delete_option( 'wns_license_key' );
            delete_option( 'wns_license_status' );
            delete_option( 'wns_license_data' );
            delete_option( 'wns_license_last_check' );
        }

        return $result;
    }

    /**
     * Check license status
     *
     * @param string $license_key Optional license key.
     * @return array
     */
    public function check( $license_key = null ) {
        if ( ! $license_key ) {
            $license_key = get_option( 'wns_license_key' );
        }

        if ( ! $license_key ) {
            return array(
                'success'   => false,
                'activated' => false,
                'message'   => __( 'No license key found.', 'woo-nalda-sync' ),
            );
        }

        $result = $this->api_request(
            '/licenses/check',
            array(
                'license_key'  => $license_key,
                'product_slug' => self::PRODUCT_SLUG,
                'domain'       => $this->get_domain(),
            )
        );

        if ( $result['success'] && isset( $result['data']['activated'] ) ) {
            update_option( 'wns_license_last_check', time() );

            if ( $result['data']['activated'] ) {
                update_option( 'wns_license_status', 'active' );
                if ( isset( $result['data']['license'] ) ) {
                    update_option( 'wns_license_data', $result['data']['license'] );
                }
            } else {
                update_option( 'wns_license_status', 'inactive' );
            }
        }

        return $result;
    }

    /**
     * Daily license verification
     */
    public function daily_check() {
        $license_key = get_option( 'wns_license_key' );
        if ( ! $license_key ) {
            return;
        }

        $result = $this->check( $license_key );

        if ( ! $result['success'] || ( isset( $result['data']['activated'] ) && ! $result['data']['activated'] ) ) {
            // License is no longer valid, disable all syncs
            update_option( 'wns_product_export_enabled', false );
            update_option( 'wns_order_import_enabled', false );
            update_option( 'wns_order_status_export_enabled', false );

            // Clear scheduled events
            wp_clear_scheduled_hook( 'wns_product_export_event' );
            wp_clear_scheduled_hook( 'wns_order_import_event' );
            wp_clear_scheduled_hook( 'wns_order_status_export_event' );

            // Log the event
            if ( function_exists( 'WNS' ) && WNS()->logs ) {
                WNS()->logs->add(
                    array(
                        'type'    => 'license',
                        'status'  => 'error',
                        'message' => __( 'License validation failed. All syncs have been disabled.', 'woo-nalda-sync' ),
                    )
                );
            }
        }
    }

    /**
     * Check if license is valid
     *
     * @return bool
     */
    public function is_valid() {
        $status      = get_option( 'wns_license_status' );
        $license_key = get_option( 'wns_license_key' );

        return $license_key && 'active' === $status;
    }

    /**
     * Get license data
     *
     * @return array
     */
    public function get_license_data() {
        return get_option( 'wns_license_data', array() );
    }

    /**
     * Get license expiry
     *
     * @return string|null
     */
    public function get_expiry() {
        $data = $this->get_license_data();
        return isset( $data['expires_at'] ) ? $data['expires_at'] : null;
    }

    /**
     * Check if license is expired
     *
     * @return bool
     */
    public function is_expired() {
        $expires_at = $this->get_expiry();

        if ( ! $expires_at ) {
            // Lifetime license
            return false;
        }

        $expiry_time = strtotime( $expires_at );
        return $expiry_time < time();
    }

    /**
     * Get remaining days
     *
     * @return int|null
     */
    public function get_remaining_days() {
        $expires_at = $this->get_expiry();

        if ( ! $expires_at ) {
            return null; // Lifetime
        }

        $expiry_time = strtotime( $expires_at );
        $diff        = $expiry_time - time();

        return max( 0, floor( $diff / DAY_IN_SECONDS ) );
    }

    /**
     * Get activations info
     *
     * @return array
     */
    public function get_activations() {
        $data = $this->get_license_data();
        return isset( $data['activations'] ) ? $data['activations'] : array(
            'limit' => 0,
            'used'  => 0,
        );
    }
}
