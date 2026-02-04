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
     * WooCommerce to Nalda status mapping
     *
     * @var array
     */
    private $status_mapping = array(
        'pending'    => 'IN_PREPARATION',
        'processing' => 'IN_PREPARATION',
        'on-hold'    => 'IN_PREPARATION',
        'completed'  => 'DELIVERED',
        'cancelled'  => 'CANCELLED',
        'refunded'   => 'RETURNED',
        'failed'     => 'DISPUTE',
    );

    /**
     * Constructor
     */
    public function __construct() {
        // Track order status changes
        add_action( 'woocommerce_order_status_changed', array( $this, 'track_status_change' ), 10, 4 );
    }

    /**
     * Track order status changes for Nalda orders
     *
     * @param int      $order_id Order ID.
     * @param string   $old_status Old status.
     * @param string   $new_status New status.
     * @param WC_Order $order Order object.
     */
    public function track_status_change( $order_id, $old_status, $new_status, $order ) {
        // Check if this is a Nalda order
        $nalda_order = $order->get_meta( '_nalda_order' );
        if ( 'yes' !== $nalda_order ) {
            return;
        }

        // Mark as needing export
        $order->update_meta_data( '_nalda_status_needs_export', 'yes' );
        $order->update_meta_data( '_nalda_last_wc_status', $new_status );
        $order->save();
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

        // Mark orders as exported
        foreach ( $orders as $order ) {
            $order->update_meta_data( '_nalda_status_needs_export', 'no' );
            $order->update_meta_data( '_nalda_last_status_export', time() );
            $order->save();
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
     * Get orders to export
     *
     * @return array
     */
    private function get_orders_to_export() {
        $args = array(
            'limit'      => -1,
            'orderby'    => 'ID',
            'order'      => 'ASC',
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key'   => '_nalda_order',
                    'value' => 'yes',
                ),
                array(
                    'relation' => 'OR',
                    array(
                        'key'   => '_nalda_status_needs_export',
                        'value' => 'yes',
                    ),
                    array(
                        'key'     => '_nalda_status_needs_export',
                        'compare' => 'NOT EXISTS',
                    ),
                ),
            ),
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

        $filename = 'order_status_' . gmdate( 'Y-m-d_H-i-s' ) . '.csv';

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
        $wc_status      = $order->get_status();
        $nalda_status   = $this->get_nalda_status( $wc_status );

        // Get expected delivery date (estimated from order date + delivery days)
        $delivery_days = get_option( 'wns_default_delivery_days', '5' );
        $order_date    = $order->get_date_created();
        
        if ( $order_date ) {
            $expected_delivery = clone $order_date;
            $expected_delivery->modify( "+{$delivery_days} days" );
            $expected_delivery_str = $expected_delivery->format( 'd.m.y' );
        } else {
            $expected_delivery_str = gmdate( 'd.m.y', strtotime( "+{$delivery_days} days" ) );
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
     * Get Nalda status from WooCommerce status
     *
     * @param string $wc_status WooCommerce status.
     * @return string
     */
    private function get_nalda_status( $wc_status ) {
        // Handle custom shipped/in-transit status if available
        if ( in_array( $wc_status, array( 'shipped', 'in-transit' ), true ) ) {
            return 'IN_DELIVERY';
        }

        return isset( $this->status_mapping[ $wc_status ] ) ? $this->status_mapping[ $wc_status ] : 'IN_PREPARATION';
    }

    /**
     * Get tracking code from order
     *
     * @param WC_Order $order Order object.
     * @return string
     */
    private function get_tracking_code( $order ) {
        // Check common tracking meta fields
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
            $message = isset( $body['message'] ) ? $body['message'] : __( 'CSV upload failed.', 'woo-nalda-sync' );
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
