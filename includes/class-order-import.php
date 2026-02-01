<?php
/**
 * Order Import class
 *
 * @package Woo_Nalda_Sync
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Order Import class
 */
class WNS_Order_Import {

    /**
     * Nalda billing info (hardcoded)
     *
     * @var array
     */
    private $nalda_billing = array(
        'first_name' => 'Nalda',
        'last_name'  => 'Marketplace',
        'company'    => 'Nalda AG',
        'address_1'  => 'Bahnhofstrasse 1',
        'address_2'  => '',
        'city'       => 'Zürich',
        'state'      => 'ZH',
        'postcode'   => '8001',
        'country'    => 'CH',
        'email'      => 'orders@nalda.com',
        'phone'      => '',
    );

    /**
     * Constructor
     */
    public function __construct() {
        // Permanently disable ALL customer-facing emails for Nalda orders
        // Nalda handles all customer communication
        add_filter( 'woocommerce_email_recipient_customer_processing_order', array( $this, 'disable_customer_emails_for_nalda_orders' ), 10, 2 );
        add_filter( 'woocommerce_email_recipient_customer_completed_order', array( $this, 'disable_customer_emails_for_nalda_orders' ), 10, 2 );
        add_filter( 'woocommerce_email_recipient_customer_on_hold_order', array( $this, 'disable_customer_emails_for_nalda_orders' ), 10, 2 );
        add_filter( 'woocommerce_email_recipient_customer_invoice', array( $this, 'disable_customer_emails_for_nalda_orders' ), 10, 2 );
        add_filter( 'woocommerce_email_recipient_customer_note', array( $this, 'disable_customer_emails_for_nalda_orders' ), 10, 2 );
        add_filter( 'woocommerce_email_recipient_customer_refunded_order', array( $this, 'disable_customer_emails_for_nalda_orders' ), 10, 2 );
        add_filter( 'woocommerce_email_recipient_customer_partially_refunded_order', array( $this, 'disable_customer_emails_for_nalda_orders' ), 10, 2 );
        add_filter( 'woocommerce_email_recipient_customer_new_account', array( $this, 'disable_customer_emails_for_nalda_orders' ), 10, 2 );
        add_filter( 'woocommerce_email_recipient_customer_reset_password', array( $this, 'disable_customer_emails_for_nalda_orders' ), 10, 2 );
    }

    /**
     * Disable customer emails for Nalda orders
     *
     * @param string   $recipient Email recipient.
     * @param WC_Order $order     Order object.
     * @return string|false
     */
    public function disable_customer_emails_for_nalda_orders( $recipient, $order ) {
        if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
            return $recipient;
        }

        // Check if this is a Nalda order
        $is_nalda_order = $order->get_meta( '_nalda_order' );

        if ( 'yes' === $is_nalda_order ) {
            // Nalda handles all customer communication
            return false;
        }

        return $recipient;
    }

    /**
     * Run order import
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

        // Check Nalda API key
        $api_key = get_option( 'wns_nalda_api_key', '' );
        if ( empty( $api_key ) ) {
            $error = new WP_Error( 'no_api_key', __( 'Nalda API key is not configured.', 'woo-nalda-sync' ) );
            $this->log_result( $trigger_type, $error, array(), microtime( true ) - $start_time );
            return $error;
        }

        // Fetch orders from Nalda
        $orders = $this->fetch_orders( $api_key );

        if ( is_wp_error( $orders ) ) {
            $this->log_result( $trigger_type, $orders, array(), microtime( true ) - $start_time );
            return $orders;
        }

        if ( empty( $orders ) ) {
            $stats = array(
                'total'    => 0,
                'imported' => 0,
                'updated'  => 0,
                'skipped'  => 0,
                'errors'   => 0,
            );

            $this->log_result( $trigger_type, $stats, array(), microtime( true ) - $start_time );
            update_option( 'wns_last_order_import_time', time() );
            update_option( 'wns_last_order_import_stats', $stats );

            return $stats;
        }

        // Import orders
        $result = $this->import_orders( $orders );

        $duration = microtime( true ) - $start_time;

        $this->log_result( $trigger_type, $result, $result['error_details'], $duration );

        update_option( 'wns_last_order_import_time', time() );
        update_option( 'wns_last_order_import_stats', $result );

        return $result;
    }

    /**
     * Fetch orders from Nalda API
     *
     * @param string $api_key API key.
     * @return array|WP_Error
     */
    private function fetch_orders( $api_key ) {
        // Get range from settings - uses valid Nalda API range values
        $range = get_option( 'wns_order_import_range', 'today' );

        // Validate range
        $valid_ranges = array( 'today', 'yesterday', 'current-month', 'current-year', '3m', '6m', '12m', '24m' );
        if ( ! in_array( $range, $valid_ranges, true ) ) {
            $range = 'today';
        }

        $body = array(
            'range' => $range,
        );

        $response = wp_remote_post(
            'https://sellers-api.nalda.com/orders/items',
            array(
                'timeout' => 60,
                'headers' => array(
                    'X-API-KEY'    => $api_key,
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ),
                'body'    => wp_json_encode( $body ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        // Check both HTTP status and API success flag
        if ( 200 !== $code || empty( $body['success'] ) ) {
            $message = isset( $body['message'] ) ? $body['message'] : __( 'Failed to fetch orders from Nalda.', 'woo-nalda-sync' );
            return new WP_Error( 'fetch_failed', $message );
        }

        // Nalda API returns orders in 'result' field, not 'data'
        return isset( $body['result'] ) ? $body['result'] : array();
    }

    /**
     * Import orders
     *
     * @param array $nalda_orders Nalda orders.
     * @return array
     */
    private function import_orders( $nalda_orders ) {
        $imported      = 0;
        $updated       = 0;
        $skipped       = 0;
        $errors        = 0;
        $error_details = array();

        // Group order items by orderId
        $grouped = array();
        foreach ( $nalda_orders as $item ) {
            $order_id = $item['orderId'];
            if ( ! isset( $grouped[ $order_id ] ) ) {
                $grouped[ $order_id ] = array(
                    'info'  => $item,
                    'items' => array(),
                );
            }
            $grouped[ $order_id ]['items'][] = $item;
        }

        foreach ( $grouped as $nalda_order_id => $order_data ) {
            // Check for existing order
            $existing_order = $this->get_existing_order( $nalda_order_id );

            if ( $existing_order ) {
                // Update existing order's payout status
                $was_updated = $this->update_existing_order( $existing_order, $order_data );
                if ( $was_updated ) {
                    $updated++;
                } else {
                    $skipped++;
                }
                continue;
            }

            try {
                $wc_order = $this->create_wc_order( $nalda_order_id, $order_data );

                if ( is_wp_error( $wc_order ) ) {
                    $errors++;
                    $error_details[] = sprintf(
                        /* translators: 1: Nalda Order ID, 2: Error message */
                        __( 'Order #%1$d: %2$s', 'woo-nalda-sync' ),
                        $nalda_order_id,
                        $wc_order->get_error_message()
                    );
                    continue;
                }

                $imported++;
            } catch ( Exception $e ) {
                $errors++;
                $error_details[] = sprintf(
                    /* translators: 1: Nalda Order ID, 2: Error message */
                    __( 'Order #%1$d: %2$s', 'woo-nalda-sync' ),
                    $nalda_order_id,
                    $e->getMessage()
                );
            }
        }

        return array(
            'total'         => count( $grouped ),
            'imported'      => $imported,
            'updated'       => $updated,
            'skipped'       => $skipped,
            'errors'        => $errors,
            'error_details' => $error_details,
        );
    }

    /**
     * Get existing WooCommerce order by Nalda order ID
     *
     * @param int $nalda_order_id Nalda order ID.
     * @return WC_Order|false
     */
    private function get_existing_order( $nalda_order_id ) {
        $orders = wc_get_orders(
            array(
                'meta_key'   => '_nalda_order_id',
                'meta_value' => $nalda_order_id,
                'limit'      => 1,
            )
        );

        return ! empty( $orders ) ? $orders[0] : false;
    }

    /**
     * Update existing order with latest data from Nalda
     *
     * Currently updates: payout status
     *
     * @param WC_Order $order      Existing WooCommerce order.
     * @param array    $order_data Order data from Nalda.
     * @return bool True if updated, false if no changes.
     */
    private function update_existing_order( $order, $order_data ) {
        $info    = $order_data['info'];
        $updated = false;

        // Update payout status if changed
        $current_payout = $order->get_meta( '_nalda_payout_status' );
        $new_payout     = $info['payoutStatus'] ?? '';

        if ( $current_payout !== $new_payout ) {
            $order->update_meta_data( '_nalda_payout_status', $new_payout );

            // Add order note about payout status change
            $order->add_order_note(
                sprintf(
                    /* translators: 1: Old status, 2: New status */
                    __( 'Nalda payout status updated: %1$s → %2$s', 'woo-nalda-sync' ),
                    $current_payout ?: __( 'None', 'woo-nalda-sync' ),
                    $new_payout ?: __( 'None', 'woo-nalda-sync' )
                )
            );

            $updated = true;
        }

        if ( $updated ) {
            $order->update_meta_data( '_nalda_last_sync', current_time( 'mysql' ) );
            $order->save();
        }

        return $updated;
    }

    /**
     * Create WooCommerce order
     *
     * @param int   $nalda_order_id Nalda order ID.
     * @param array $order_data Order data with info and items.
     * @return WC_Order|WP_Error
     */
    private function create_wc_order( $nalda_order_id, $order_data ) {
        $info  = $order_data['info'];
        $items = $order_data['items'];

        // Calculate total and commission
        $total_commission = 0;
        $total_price      = 0;

        foreach ( $items as $item ) {
            $total_price      += floatval( $item['price'] ) * intval( $item['quantity'] );
            $total_commission += floatval( $item['commission'] ?? 0 );
        }

        // Create order
        $order = wc_create_order();

        if ( is_wp_error( $order ) ) {
            return $order;
        }

        // Add items
        foreach ( $items as $item ) {
            $product = $this->find_product_by_gtin( $item['gtin'] );

            if ( $product ) {
                $item_price = floatval( $item['price'] );
                $item_commission = floatval( $item['commission'] ?? 0 );
                // Price after Nalda commission deduction (per unit)
                $net_price = $item_price - ( $item_commission / intval( $item['quantity'] ) );

                $order_item_id = $order->add_product( $product, intval( $item['quantity'] ), array(
                    'subtotal' => $net_price * intval( $item['quantity'] ),
                    'total'    => $net_price * intval( $item['quantity'] ),
                ) );

                // Store item metadata
                if ( $order_item_id ) {
                    wc_add_order_item_meta( $order_item_id, '_nalda_gtin', $item['gtin'] );
                    wc_add_order_item_meta( $order_item_id, '_nalda_original_price', $item['price'] );
                    wc_add_order_item_meta( $order_item_id, '_nalda_commission', $item['commission'] ?? 0 );
                }
            } else {
                // Add as line item without product link
                $item_price = floatval( $item['price'] );
                $item_commission = floatval( $item['commission'] ?? 0 );
                $net_price = $item_price - ( $item_commission / intval( $item['quantity'] ) );

                $order_item = new WC_Order_Item_Product();
                $order_item->set_name( $item['title'] ?? __( 'Unknown Product', 'woo-nalda-sync' ) );
                $order_item->set_quantity( intval( $item['quantity'] ) );
                $order_item->set_subtotal( $net_price * intval( $item['quantity'] ) );
                $order_item->set_total( $net_price * intval( $item['quantity'] ) );
                $order_item->add_meta_data( '_nalda_gtin', $item['gtin'] );
                $order_item->add_meta_data( '_nalda_original_price', $item['price'] );
                $order_item->add_meta_data( '_nalda_commission', $item['commission'] ?? 0 );
                $order->add_item( $order_item );
            }
        }

        // Set billing address (Nalda)
        $order->set_billing_first_name( $this->nalda_billing['first_name'] );
        $order->set_billing_last_name( $this->nalda_billing['last_name'] );
        $order->set_billing_company( $this->nalda_billing['company'] );
        $order->set_billing_address_1( $this->nalda_billing['address_1'] );
        $order->set_billing_address_2( $this->nalda_billing['address_2'] );
        $order->set_billing_city( $this->nalda_billing['city'] );
        $order->set_billing_state( $this->nalda_billing['state'] );
        $order->set_billing_postcode( $this->nalda_billing['postcode'] );
        $order->set_billing_country( $this->nalda_billing['country'] );
        $order->set_billing_email( $this->nalda_billing['email'] );
        $order->set_billing_phone( $this->nalda_billing['phone'] );

        // Set shipping address (customer from Nalda)
        $order->set_shipping_first_name( $info['firstName'] ?? '' );
        $order->set_shipping_last_name( $info['lastName'] ?? '' );
        $order->set_shipping_address_1( $info['street1'] ?? '' );
        $order->set_shipping_city( $info['city'] ?? '' );
        $order->set_shipping_postcode( $info['postalCode'] ?? '' );
        $order->set_shipping_country( $info['country'] ?? 'CH' );

        // Set order meta
        $order->update_meta_data( '_nalda_order_id', $nalda_order_id );
        $order->update_meta_data( '_nalda_order', 'yes' );
        $order->update_meta_data( '_nalda_total_commission', $total_commission );
        $order->update_meta_data( '_nalda_original_total', $total_price );
        $order->update_meta_data( '_nalda_payout_status', $info['payoutStatus'] ?? '' );
        $order->update_meta_data( '_nalda_created_at', $info['createdAt'] ?? '' );

        // Store delivery state for order status export
        $delivery_status = $info['deliveryStatus'] ?? 'IN_PREPARATION';
        $order->update_meta_data( '_nalda_state', $delivery_status );

        // Store expected delivery date if available
        if ( ! empty( $info['deliveryDatePlanned'] ) ) {
            $delivery_date_formatted = date( 'Y-m-d', strtotime( $info['deliveryDatePlanned'] ) );
            if ( $delivery_date_formatted && '1970-01-01' !== $delivery_date_formatted ) {
                $order->update_meta_data( '_nalda_expected_delivery_date', $delivery_date_formatted );
            }
        }

        // Store end customer email
        $order->update_meta_data( '_nalda_end_customer_email', $info['email'] ?? '' );

        // Set status based on Nalda delivery status
        $status = $this->map_nalda_status_to_wc( $delivery_status );
        $order->set_status( $status );

        // Set order date
        if ( ! empty( $info['createdAt'] ) ) {
            $order->set_date_created( strtotime( $info['createdAt'] ) );
        }

        // Calculate totals
        $order->calculate_totals();

        // Add order note
        $order->add_order_note(
            sprintf(
                /* translators: 1: Nalda order ID, 2: Commission amount, 3: Currency */
                __( 'Order imported from Nalda (Order ID: %1$d). Commission deducted: %2$s %3$s', 'woo-nalda-sync' ),
                $nalda_order_id,
                number_format( $total_commission, 2 ),
                $info['currency'] ?? 'CHF'
            )
        );

        // Save order
        $order->save();

        return $order;
    }

    /**
     * Find product by GTIN
     *
     * @param string $gtin GTIN/EAN.
     * @return WC_Product|null
     */
    private function find_product_by_gtin( $gtin ) {
        if ( empty( $gtin ) ) {
            return null;
        }

        // Search in common GTIN meta fields
        $gtin_fields = array( '_gtin', '_ean', '_barcode', 'gtin', 'ean', 'barcode', '_global_unique_id' );

        foreach ( $gtin_fields as $field ) {
            $products = wc_get_products(
                array(
                    'meta_key'   => $field,
                    'meta_value' => $gtin,
                    'limit'      => 1,
                )
            );

            if ( ! empty( $products ) ) {
                return $products[0];
            }
        }

        // Search by SKU (in case GTIN is used as SKU)
        $product_id = wc_get_product_id_by_sku( $gtin );
        if ( $product_id ) {
            return wc_get_product( $product_id );
        }

        return null;
    }

    /**
     * Map Nalda status to WooCommerce status
     *
     * @param string $nalda_status Nalda delivery status.
     * @return string
     */
    private function map_nalda_status_to_wc( $nalda_status ) {
        $mapping = array(
            'IN_PREPARATION'   => 'processing',
            'IN_DELIVERY'      => 'processing',
            'DELIVERED'        => 'completed',
            'UNDELIVERABLE'    => 'failed',
            'CANCELLED'        => 'cancelled',
            'READY_TO_COLLECT' => 'processing',
            'COLLECTED'        => 'completed',
            'NOT_PICKED_UP'    => 'failed',
            'RETURNED'         => 'refunded',
            'DISPUTE'          => 'on-hold',
        );

        return isset( $mapping[ $nalda_status ] ) ? $mapping[ $nalda_status ] : 'processing';
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
                    'type'    => 'order_import',
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
                /* translators: 1: Imported count, 2: Updated count, 3: Skipped count */
                __( 'Imported %1$d orders, updated %2$d, skipped %3$d.', 'woo-nalda-sync' ),
                $result['imported'],
                $result['updated'] ?? 0,
                $result['skipped']
            );

            WNS()->logs->add(
                array(
                    'type'    => 'order_import',
                    'trigger' => $trigger_type,
                    'status'  => $status,
                    'message' => $message,
                    'stats'   => $result,
                    'errors'  => $errors,
                )
            );
        }
    }
}
