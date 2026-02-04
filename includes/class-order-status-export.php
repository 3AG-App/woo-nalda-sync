<?php
/**
 * Order Status Export class
 *
 * @package Woo_Nalda_Sync
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Order Status Export class
 */
class WNS_Order_Status_Export {

    /**
     * Valid Nalda states
     *
     * @var array
     */
    private $valid_states = array(
        'IN_PREPARATION',
        'READY_TO_COLLECT',
        'IN_DELIVERY',
        'DELIVERED',
        'CANCELLED',
        'RETURNED',
        'DISPUTE',
    );

    /**
     * Constructor
     */
    public function __construct() {
        // No hooks needed - we export all Nalda orders from last 60 days
    }

    /**
     * Run order status export
     *
     * @param string $trigger_type Trigger type (manual, scheduled).
     * @return array|WP_Error
     */
    public function run( $trigger_type = 'manual' ) {
        $start_time = microtime( true );

        // Check license
        if ( ! WNS()->license->is_valid() ) {
            $error = new WP_Error( 'no_license', __( 'License is not active.', 'woo-nalda-sync' ) );
            $this->log_result( $trigger_type, $error, array(), microtime( true ) - $start_time );
            return $error;
        }

        // Check SFTP credentials
        $sftp_host     = get_option( 'wns_sftp_host', '' );
        $sftp_username = get_option( 'wns_sftp_username', '' );
        $sftp_password = get_option( 'wns_sftp_password', '' );

        if ( empty( $sftp_host ) || empty( $sftp_username ) || empty( $sftp_password ) ) {
            $error = new WP_Error( 'no_sftp', __( 'SFTP credentials are not configured.', 'woo-nalda-sync' ) );
            $this->log_result( $trigger_type, $error, array(), microtime( true ) - $start_time );
            return $error;
        }

        // Get Nalda orders that need status export
        $orders = $this->get_orders_to_export();

        if ( empty( $orders ) ) {
            $stats = array(
                'total'    => 0,
                'exported' => 0,
                'skipped'  => 0,
                'errors'   => 0,
            );

            $this->log_result( $trigger_type, $stats, array(), microtime( true ) - $start_time );
            update_option( 'wns_last_order_status_export_time', time() );
            update_option( 'wns_last_order_status_export_stats', $stats );

            return $stats;
        }

        // Generate CSV
        $csv_data = $this->generate_csv( $orders );

        if ( is_wp_error( $csv_data ) ) {
            $this->log_result( $trigger_type, $csv_data, array(), microtime( true ) - $start_time );
            return $csv_data;
        }

        // Upload CSV
        $result = $this->upload_csv( $csv_data['csv'], $csv_data['filename'] );

        if ( is_wp_error( $result ) ) {
            $this->log_result( $trigger_type, $result, array(), microtime( true ) - $start_time );
            return $result;
        }

        $stats = array(
            'total'    => count( $orders ),
            'exported' => $csv_data['exported'],
            'skipped'  => $csv_data['skipped'],
            'errors'   => count( $csv_data['errors'] ),
        );

        $duration = microtime( true ) - $start_time;

        $this->log_result( $trigger_type, $stats, $csv_data['errors'], $duration );

        update_option( 'wns_last_order_status_export_time', time() );
        update_option( 'wns_last_order_status_export_stats', $stats );

        return $stats;
    }

    /**
     * Get orders to export (all Nalda orders from last 60 days)
     *
     * @return array
     */
    private function get_orders_to_export() {
        $args = array(
            'limit'      => -1,
            'orderby'    => 'date',
            'order'      => 'DESC',
            'date_after' => gmdate( 'Y-m-d', strtotime( '-60 days' ) ),
            'meta_key'   => '_nalda_order_id',
            'meta_compare' => 'EXISTS',
        );

        return wc_get_orders( $args );
    }

    /**
     * Generate CSV
     *
     * @param array $orders Orders to export.
     * @return array|WP_Error
     */
    private function generate_csv( $orders ) {
        $output   = fopen( 'php://temp', 'r+' );
        $exported = 0;
        $skipped  = 0;
        $errors   = array();

        // Write header
        fputcsv( $output, array( 'orderId', 'gtin', 'state', 'expectedDeliveryDate', 'trackingCode' ) );

        foreach ( $orders as $order ) {
            try {
                $rows = $this->map_order_to_csv( $order );

                foreach ( $rows as $row ) {
                    fputcsv( $output, $row );
                    $exported++;
                }
            } catch ( Exception $e ) {
                $errors[] = sprintf(
                    /* translators: 1: Order ID, 2: Error message */
                    __( 'Order #%1$d: %2$s', 'woo-nalda-sync' ),
                    $order->get_id(),
                    $e->getMessage()
                );
                $skipped++;
            }
        }

        rewind( $output );
        $csv = stream_get_contents( $output );
        fclose( $output );

        $filename = 'order_status_' . gmdate( 'Y-m-d' ) . '.csv';

        return array(
            'csv'      => $csv,
            'filename' => $filename,
            'exported' => $exported,
            'skipped'  => $skipped,
            'errors'   => $errors,
        );
    }

    /**
     * Map order to CSV rows (one per line item)
     *
     * @param WC_Order $order Order object.
     * @return array
     */
    private function map_order_to_csv( $order ) {
        $rows = array();

        $nalda_order_id = $order->get_meta( '_nalda_order_id' );
        
        // Get the Nalda state directly from order meta (set in Delivery Information)
        $nalda_status = $order->get_meta( '_nalda_state' );
        
        // Skip orders without a Nalda state set
        if ( empty( $nalda_status ) ) {
            return $rows;
        }
        
        // Validate the state is a valid Nalda state
        if ( ! in_array( strtoupper( $nalda_status ), $this->valid_states, true ) ) {
            return $rows;
        }
        
        // Ensure uppercase
        $nalda_status = strtoupper( $nalda_status );

        // Get expected delivery date from order meta first, then fallback to calculation
        $expected_delivery_meta = $order->get_meta( '_nalda_expected_delivery_date' );
        
        if ( ! empty( $expected_delivery_meta ) ) {
            // Convert meta value to dd.mm.yy format
            $timestamp = strtotime( $expected_delivery_meta );
            if ( $timestamp ) {
                $expected_delivery_str = gmdate( 'd.m.y', $timestamp );
            } else {
                // If parsing fails, use the value as-is (might already be in correct format)
                $expected_delivery_str = $expected_delivery_meta;
            }
        } else {
            // Fallback: calculate from order date + delivery days
            $delivery_days = get_option( 'wns_default_delivery_days', '5' );
            $order_date    = $order->get_date_created();
            
            if ( $order_date ) {
                $expected_delivery = clone $order_date;
                $expected_delivery->modify( "+{$delivery_days} days" );
                $expected_delivery_str = $expected_delivery->format( 'd.m.y' );
            } else {
                $expected_delivery_str = gmdate( 'd.m.y', strtotime( "+{$delivery_days} days" ) );
            }
        }

        // Get tracking code if available
        $tracking_code = $this->get_tracking_code( $order );

        // Generate a row for each order item
        foreach ( $order->get_items() as $item ) {
            $gtin = $item->get_meta( '_nalda_gtin' );

            if ( empty( $gtin ) ) {
                // Try to get GTIN from product
                $product = $item->get_product();
                if ( $product ) {
                    $gtin = $this->get_product_gtin( $product );
                }
            }

            if ( empty( $gtin ) ) {
                continue; // Skip items without GTIN
            }

            $rows[] = array(
                $nalda_order_id,
                $gtin,
                $nalda_status,
                $expected_delivery_str,
                $tracking_code,
            );
        }

        return $rows;
    }

    /**
     * Check if order has valid Nalda state for export
     *
     * @param WC_Order $order Order object.
     * @return bool
     */
    private function has_valid_nalda_state( $order ) {
        $nalda_state = $order->get_meta( '_nalda_state' );
        
        if ( empty( $nalda_state ) ) {
            return false;
        }
        
        return in_array( strtoupper( $nalda_state ), $this->valid_states, true );
    }

    /**
     * Get tracking code from order
     *
     * @param WC_Order $order Order object.
     * @return string
     */
    private function get_tracking_code( $order ) {
        // First check our own Nalda tracking code field
        $nalda_tracking = $order->get_meta( '_nalda_tracking_code' );
        if ( ! empty( $nalda_tracking ) ) {
            return $nalda_tracking;
        }

        // Check common tracking meta fields from other plugins
        $tracking_fields = array(
            '_tracking_number',
            '_wc_shipment_tracking_items',
            'tracking_number',
            '_tracking_code',
        );

        foreach ( $tracking_fields as $field ) {
            $tracking = $order->get_meta( $field );

            if ( ! empty( $tracking ) ) {
                // Handle WooCommerce Shipment Tracking format
                if ( is_array( $tracking ) && isset( $tracking[0]['tracking_number'] ) ) {
                    return $tracking[0]['tracking_number'];
                }

                if ( is_string( $tracking ) ) {
                    return $tracking;
                }
            }
        }

        return '';
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
     * Upload CSV via API
     *
     * @param string $csv CSV content.
     * @param string $filename Filename.
     * @return true|WP_Error
     */
    private function upload_csv( $csv, $filename ) {
        $license_key   = get_option( 'wns_license_key', '' );
        $sftp_host     = get_option( 'wns_sftp_host', '' );
        $sftp_port     = get_option( 'wns_sftp_port', '2022' );
        $sftp_username = get_option( 'wns_sftp_username', '' );
        $sftp_password = get_option( 'wns_sftp_password', '' );

        $boundary = wp_generate_password( 24, false );

        // Build multipart body
        $body = '';
        
        // Add form fields
        $fields = array(
            'license_key'   => $license_key,
            'product_slug'  => WNS_PRODUCT_SLUG,
            'domain'        => $this->get_domain(),
            'csv_type'      => 'orders',
            'sftp_host'     => $sftp_host,
            'sftp_port'     => $sftp_port,
            'sftp_username' => $sftp_username,
            'sftp_password' => $sftp_password,
        );

        foreach ( $fields as $name => $value ) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
            $body .= "{$value}\r\n";
        }

        // Add file
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"csv_file\"; filename=\"{$filename}\"\r\n";
        $body .= "Content-Type: text/csv\r\n\r\n";
        $body .= $csv . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $response = wp_remote_post(
            WNS_API_BASE_URL . '/nalda/csv-upload',
            array(
                'timeout' => 60,
                'headers' => array(
                    'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
                ),
                'body'    => $body,
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $code && 201 !== $code ) {
            $api_message = isset( $body['message'] ) ? $body['message'] : __( 'Unknown error', 'woo-nalda-sync' );
            /* translators: 1: HTTP response code, 2: Error message from API */
            $message = sprintf( __( 'CSV upload failed. Response code: %1$d. Message: %2$s', 'woo-nalda-sync' ), $code, $api_message );
            return new WP_Error( 'upload_failed', $message );
        }

        return true;
    }

    /**
     * Log result
     *
     * @param string         $trigger_type Trigger type.
     * @param array|WP_Error $result       Result.
     * @param array          $errors       Errors.
     * @param float          $duration     Duration.
     */
    private function log_result( $trigger_type, $result, $errors = array(), $duration = 0 ) {
        if ( is_wp_error( $result ) ) {
            WNS()->logs->add(
                array(
                    'type'    => 'order_status_export',
                    'trigger' => $trigger_type,
                    'status'  => 'error',
                    'message' => $result->get_error_message(),
                    'stats'   => array(),
                    'errors'  => array( $result->get_error_message() ),
                )
            );
        } else {
            $status = ( isset( $result['errors'] ) && $result['errors'] > 0 ) ? 'warning' : 'success';

            $message = sprintf(
                /* translators: 1: Exported count, 2: Skipped count */
                __( 'Exported %1$d order statuses, skipped %2$d.', 'woo-nalda-sync' ),
                $result['exported'],
                $result['skipped']
            );

            WNS()->logs->add(
                array(
                    'type'    => 'order_status_export',
                    'trigger' => $trigger_type,
                    'status'  => $status,
                    'message' => $message,
                    'stats'   => $result,
                    'errors'  => $errors,
                )
            );
        }
    }

    /**
     * Get domain
     *
     * @return string
     */
    private function get_domain() {
        $site_url = get_site_url();
        $parsed   = wp_parse_url( $site_url );
        return isset( $parsed['host'] ) ? $parsed['host'] : '';
    }
}
