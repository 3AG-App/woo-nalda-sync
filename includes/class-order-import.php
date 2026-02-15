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
        'company'    => 'Nalda Marketplace AG',
        'address_1'  => 'Grabenstrasse 15a',
        'address_2'  => '',
        'city'       => 'Baar',
        'state'      => 'ZG',
        'postcode'   => '6340',
        'country'    => 'CH',
        'email'      => 'orders@nalda.com',
        'phone'      => '',
        'vat_number' => 'CHE-353.496.457 MWST',
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

        // Add Nalda order details to admin emails
        add_action( 'woocommerce_email_after_order_table', array( $this, 'add_nalda_details_to_email' ), 10, 4 );
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
        $nalda_order_id = $order->get_meta( '_nalda_order_id' );

        if ( ! empty( $nalda_order_id ) ) {
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
        // Get range from settings
        $range = get_option( 'wns_order_import_range', 'today' );

        // Convert range to custom dates with buffer to avoid timezone issues
        $to_date = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
        $to_date->modify( '+1 day' ); // Add 1 future date buffer
        
        $from_date = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
        
        // Calculate from date based on range
        switch ( $range ) {
            case 'today':
                // Today only - no modification needed for from_date
                break;
            case 'yesterday':
                $from_date->modify( '-1 day' );
                break;
            case 'current-month':
                $from_date->modify( 'first day of this month' );
                break;
            case 'current-year':
                $from_date->modify( 'first day of January this year' );
                break;
            case '3m':
                $from_date->modify( '-3 months' );
                break;
            case '6m':
                $from_date->modify( '-6 months' );
                break;
            case '12m':
                $from_date->modify( '-12 months' );
                break;
            case '24m':
                $from_date->modify( '-24 months' );
                break;
            default:
                // Default to today
                break;
        }
        
        $from_date->modify( '-1 day' ); // Add 1 past date buffer
        
        // Format dates as ISO 8601 (YYYY-MM-DD) and always use custom range
        $body = array(
            'range' => 'custom',
            'from'  => $from_date->format( 'Y-m-d' ),
            'to'    => $to_date->format( 'Y-m-d' ),
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
     * Currently updates: payout status, payment status, and re-links unlinked products
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

            // Update payment status based on new payout status
            $payout_status_lower = strtolower( $new_payout );
            if ( 'paid_out' === $payout_status_lower && ! $order->is_paid() ) {
                // Nalda has now paid us - set payment method and mark order as paid
                $order->set_payment_method( 'nalda' );
                $order->set_payment_method_title( 'Nalda Marketplace' );
                $order->set_date_paid( time() );
                $order->add_order_note(
                    __( 'Payment received from Nalda Marketplace.', 'woo-nalda-sync' ),
                    false,
                    true
                );
            } elseif ( 'paid_out' !== $payout_status_lower && $order->is_paid() ) {
                // Payout status changed from paid to unpaid (rare, but handle it)
                // Remove payment method and mark as unpaid
                $order->set_payment_method( '' );
                $order->set_payment_method_title( '' );
                $order->set_date_paid( null );
                $order->add_order_note(
                    __( 'Payment status reverted - Nalda payout status changed.', 'woo-nalda-sync' ),
                    false,
                    true
                );
            }

            $updated = true;
        }

        // Store Nalda delivery status only if it's not set (site is source of truth)
        // Note: API returns 'status' field (lowercase like 'delivered'), we normalize to uppercase
        $current_delivery_status = $order->get_meta( '_nalda_delivery_status' );
        $new_delivery_status     = isset( $info['status'] ) ? strtoupper( $info['status'] ) : '';

        if ( empty( $current_delivery_status ) && ! empty( $new_delivery_status ) ) {
            $order->update_meta_data( '_nalda_delivery_status', $new_delivery_status );
            $updated = true;
        }

        // Try to re-link unlinked products
        $relinked_products = $this->relink_unlinked_products( $order );
        if ( $relinked_products > 0 ) {
            $updated = true;
        }

        if ( $updated ) {
            $order->update_meta_data( '_nalda_last_sync', current_time( 'mysql' ) );
            $order->save();
        }

        return $updated;
    }

    /**
     * Re-link unlinked products in an order
     *
     * Attempts to find and link products that were not found during initial import.
     *
     * @param WC_Order $order WooCommerce order.
     * @return int Number of products re-linked.
     */
    private function relink_unlinked_products( $order ) {
        $relinked = 0;

        foreach ( $order->get_items() as $item_id => $item ) {
            // Skip if already linked to a product
            if ( $item->get_product_id() > 0 ) {
                continue;
            }

            // Get GTIN from item meta
            $gtin = $item->get_meta( '_nalda_gtin' );
            if ( empty( $gtin ) ) {
                continue;
            }

            // Try to find the product
            $product = $this->find_product_by_gtin( $gtin );
            if ( ! $product ) {
                continue;
            }

            // Link the product to the order item
            $item->set_product_id( $product->get_id() );
            
            // Set variation ID if it's a variation
            if ( $product->is_type( 'variation' ) ) {
                $item->set_variation_id( $product->get_id() );
                $item->set_product_id( $product->get_parent_id() );
            }

            // Update item name to match product
            $item->set_name( $product->get_name() );

            $item->save();

            $order->add_order_note(
                sprintf(
                    /* translators: 1: Product name, 2: GTIN */
                    __( 'Product re-linked: %1$s (GTIN: %2$s)', 'woo-nalda-sync' ),
                    $product->get_name(),
                    $gtin
                )
            );

            $relinked++;
        }

        return $relinked;
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

        // Disable WooCommerce emails during order creation to prevent emails with 0 amounts.
        // We'll re-enable and trigger emails manually after totals are calculated.
        add_filter( 'woocommerce_email_enabled_new_order', '__return_false' );
        add_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_false' );
        add_filter( 'woocommerce_email_enabled_customer_on_hold_order', '__return_false' );

        // Create order with pending status first (don't trigger status change emails yet)
        $order = wc_create_order( array(
            'status' => 'pending',
        ) );

        if ( is_wp_error( $order ) ) {
            // Re-enable emails
            remove_filter( 'woocommerce_email_enabled_new_order', '__return_false' );
            remove_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_false' );
            remove_filter( 'woocommerce_email_enabled_customer_on_hold_order', '__return_false' );
            return $order;
        }

        // Add items
        foreach ( $items as $item ) {
            $product = $this->find_product_by_gtin( $item['gtin'] );

            $quantity = max( 1, intval( $item['quantity'] ) ); // Prevent division by zero
            $item_price = floatval( $item['price'] );
            $item_commission = floatval( $item['commission'] ?? 0 );
            // Price after Nalda commission deduction (per unit)
            $commission_per_item = $item_commission / $quantity;
            $net_price = $item_price - $commission_per_item;

            if ( $product ) {
                $order_item_id = $order->add_product( $product, $quantity, array(
                    'subtotal' => $net_price * $quantity,
                    'total'    => $net_price * $quantity,
                ) );

                // Store item metadata
                if ( $order_item_id ) {
                    wc_add_order_item_meta( $order_item_id, '_nalda_gtin', $item['gtin'] );
                    wc_add_order_item_meta( $order_item_id, '_nalda_customer_price', $item_price );
                    wc_add_order_item_meta( $order_item_id, '_nalda_original_price', $item['price'] );
                    wc_add_order_item_meta( $order_item_id, '_nalda_commission', $item_commission );
                    wc_add_order_item_meta( $order_item_id, '_nalda_net_price', $net_price );
                }
            } else {
                // Add as line item without product link
                $order_item = new WC_Order_Item_Product();
                $order_item->set_name( $item['title'] ?? __( 'Unknown Product', 'woo-nalda-sync' ) );
                $order_item->set_quantity( $quantity );
                $order_item->set_subtotal( $net_price * $quantity );
                $order_item->set_total( $net_price * $quantity );
                $order_item->add_meta_data( '_nalda_gtin', $item['gtin'] );
                $order_item->add_meta_data( '_nalda_customer_price', $item_price );
                $order_item->add_meta_data( '_nalda_original_price', $item['price'] );
                $order_item->add_meta_data( '_nalda_commission', $item_commission );
                $order_item->add_meta_data( '_nalda_net_price', $net_price );
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

        // Add VAT number to billing
        $order->update_meta_data( '_billing_vat_number', $this->nalda_billing['vat_number'] );

        // Set shipping address (customer from Nalda)
        $order->set_shipping_first_name( $info['firstName'] ?? '' );
        $order->set_shipping_last_name( $info['lastName'] ?? '' );
        $order->set_shipping_address_1( $info['street1'] ?? '' );
        $order->set_shipping_city( $info['city'] ?? '' );
        $order->set_shipping_postcode( $info['postalCode'] ?? '' );
        $order->set_shipping_country( $info['country'] ?? 'CH' );

        // Set order currency from API response
        if ( ! empty( $info['currency'] ) ) {
            $order->set_currency( $info['currency'] );
        }

        // Set order meta
        $order->update_meta_data( '_nalda_order_id', $nalda_order_id );
        $order->update_meta_data( '_nalda_total_commission', $total_commission );
        $order->update_meta_data( '_nalda_original_total', $total_price );
        $order->update_meta_data( '_nalda_payout_status', $info['payoutStatus'] ?? '' );
        $order->update_meta_data( '_nalda_created_at', $info['createdAt'] ?? '' );

        // Store delivery status for order status export
        // API returns 'status' field (lowercase like 'delivered'), we normalize to uppercase
        $delivery_status = isset( $info['status'] ) ? strtoupper( $info['status'] ) : 'IN_PREPARATION';
        $order->update_meta_data( '_nalda_delivery_status', $delivery_status );

        // Store expected delivery date if available
        if ( ! empty( $info['deliveryDatePlanned'] ) ) {
            $delivery_date_formatted = date( 'Y-m-d', strtotime( $info['deliveryDatePlanned'] ) );
            if ( $delivery_date_formatted && '1970-01-01' !== $delivery_date_formatted ) {
                $order->update_meta_data( '_nalda_expected_delivery_date', $delivery_date_formatted );
            }
        }

        // Store end customer email
        $order->update_meta_data( '_nalda_end_customer_email', $info['email'] ?? '' );

        // Set order date
        if ( ! empty( $info['createdAt'] ) ) {
            $order->set_date_created( strtotime( $info['createdAt'] ) );
        }

        // Set payment method and status based on Nalda payout status.
        // Only set payment method and mark as paid if Nalda has actually paid us out.
        $payout_status       = $info['payoutStatus'] ?? '';
        $payout_status_lower = strtolower( $payout_status );

        if ( 'paid_out' === $payout_status_lower ) {
            // Nalda has paid us - set payment method and mark order as paid.
            $order->set_payment_method( 'nalda' );
            $order->set_payment_method_title( 'Nalda Marketplace' );
            $order->set_date_paid( time() );
            $order->add_order_note(
                __( 'Payment received from Nalda Marketplace.', 'woo-nalda-sync' ),
                false,
                true
            );
        } else {
            // Nalda hasn't paid us yet - leave as unpaid with no payment method.
            // Customer paid Nalda, but we're waiting for payout.
            $order->set_date_paid( null );
        }

        // Calculate totals (this sets the order total correctly)
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

        // Save order with all data (still pending status)
        $order->save();

        // Now update status to appropriate WooCommerce status based on Nalda delivery status.
        // This is done AFTER save so the totals are already in the database.
        $wc_status = $this->map_nalda_status_to_wc( $delivery_status );
        $order->set_status( $wc_status, __( 'Order imported from Nalda Marketplace.', 'woo-nalda-sync' ) );
        $order->save();

        // Re-enable WooCommerce emails
        remove_filter( 'woocommerce_email_enabled_new_order', '__return_false' );
        remove_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_false' );
        remove_filter( 'woocommerce_email_enabled_customer_on_hold_order', '__return_false' );

        // Manually trigger the new order email now that totals are correct.
        // Only trigger if order status is processing (most common case).
        // Reload the order from database to ensure we have fresh data.
        $order = wc_get_order( $order->get_id() );
        if ( $order && 'processing' === $wc_status ) {
            do_action( 'woocommerce_order_status_pending_to_processing_notification', $order->get_id(), $order );
        }

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

        // Normalize GTIN - remove any whitespace and leading zeros for comparison
        $gtin_normalized = ltrim( trim( $gtin ), '0' );

        // WooCommerce 8.4+ has native GTIN support via global_unique_id
        // Search using the native field first
        $products = wc_get_products(
            array(
                'global_unique_id' => $gtin,
                'limit'            => 1,
            )
        );

        if ( ! empty( $products ) ) {
            return $products[0];
        }

        // Try normalized GTIN (without leading zeros)
        if ( $gtin !== $gtin_normalized ) {
            $products = wc_get_products(
                array(
                    'global_unique_id' => $gtin_normalized,
                    'limit'            => 1,
                )
            );

            if ( ! empty( $products ) ) {
                return $products[0];
            }
        }

        // Search in common GTIN meta fields
        $gtin_fields = array( '_gtin', '_ean', '_barcode', 'gtin', 'ean', 'barcode' );

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

            // Also try normalized GTIN
            if ( $gtin !== $gtin_normalized ) {
                $products = wc_get_products(
                    array(
                        'meta_key'   => $field,
                        'meta_value' => $gtin_normalized,
                        'limit'      => 1,
                    )
                );

                if ( ! empty( $products ) ) {
                    return $products[0];
                }
            }
        }

        // Search by SKU (in case GTIN is used as SKU)
        $product_id = wc_get_product_id_by_sku( $gtin );
        if ( $product_id ) {
            return wc_get_product( $product_id );
        }

        // Try normalized SKU search
        if ( $gtin !== $gtin_normalized ) {
            $product_id = wc_get_product_id_by_sku( $gtin_normalized );
            if ( $product_id ) {
                return wc_get_product( $product_id );
            }
        }

        return null;
    }

    /**
     * Add Nalda order details to admin email
     *
     * Displays additional information like total amount, commission,
     * shipping info, and payout status in admin new order emails.
     *
     * @param WC_Order $order         Order object.
     * @param bool     $sent_to_admin Whether this is an admin email.
     * @param bool     $plain_text    Whether this is plain text email.
     * @param WC_Email $email         Email object.
     */
    public function add_nalda_details_to_email( $order, $sent_to_admin, $plain_text, $email = null ) {
        // Only add to admin emails for Nalda orders
        if ( ! $sent_to_admin ) {
            return;
        }

        $nalda_order_id = $order->get_meta( '_nalda_order_id' );
        if ( empty( $nalda_order_id ) ) {
            return;
        }

        $currency          = $order->get_currency();
        $original_total    = $order->get_meta( '_nalda_original_total' );
        $total_commission   = $order->get_meta( '_nalda_total_commission' );
        $payout_status     = $order->get_meta( '_nalda_payout_status' );
        $delivery_status   = $order->get_meta( '_nalda_delivery_status' );
        $delivery_date     = $order->get_meta( '_nalda_expected_delivery_date' );
        $end_customer_email = $order->get_meta( '_nalda_end_customer_email' );

        // Shipping address
        $shipping_name    = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
        $shipping_address = $order->get_shipping_address_1();
        $shipping_city    = $order->get_shipping_city();
        $shipping_postcode = $order->get_shipping_postcode();
        $shipping_country = $order->get_shipping_country();

        if ( $plain_text ) {
            $this->render_nalda_email_details_plain( array(
                'nalda_order_id'     => $nalda_order_id,
                'currency'           => $currency,
                'original_total'     => $original_total,
                'total_commission'   => $total_commission,
                'order_total'        => $order->get_total(),
                'payout_status'      => $payout_status,
                'delivery_status'    => $delivery_status,
                'delivery_date'      => $delivery_date,
                'end_customer_email' => $end_customer_email,
                'shipping_name'      => $shipping_name,
                'shipping_address'   => $shipping_address,
                'shipping_city'      => $shipping_city,
                'shipping_postcode'  => $shipping_postcode,
                'shipping_country'   => $shipping_country,
            ) );
        } else {
            $this->render_nalda_email_details_html( array(
                'nalda_order_id'     => $nalda_order_id,
                'currency'           => $currency,
                'original_total'     => $original_total,
                'total_commission'   => $total_commission,
                'order_total'        => $order->get_total(),
                'payout_status'      => $payout_status,
                'delivery_status'    => $delivery_status,
                'delivery_date'      => $delivery_date,
                'end_customer_email' => $end_customer_email,
                'shipping_name'      => $shipping_name,
                'shipping_address'   => $shipping_address,
                'shipping_city'      => $shipping_city,
                'shipping_postcode'  => $shipping_postcode,
                'shipping_country'   => $shipping_country,
            ) );
        }
    }

    /**
     * Render Nalda details in HTML email format
     *
     * @param array $data Email data.
     */
    private function render_nalda_email_details_html( $data ) {
        $currency = $data['currency'];
        ?>
        <h2 style="margin-top: 20px;"><?php esc_html_e( 'Nalda Marketplace Details', 'woo-nalda-sync' ); ?></h2>
        <table cellspacing="0" cellpadding="6" border="1" style="width: 100%; border: 1px solid #e5e5e5; border-collapse: collapse; margin-bottom: 20px;">
            <tbody>
                <tr>
                    <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8; border: 1px solid #e5e5e5;"><?php esc_html_e( 'Nalda Order ID', 'woo-nalda-sync' ); ?></th>
                    <td style="padding: 12px; border: 1px solid #e5e5e5;"><?php echo esc_html( $data['nalda_order_id'] ); ?></td>
                </tr>
                <tr>
                    <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8; border: 1px solid #e5e5e5;"><?php esc_html_e( 'Customer Total (incl. commission)', 'woo-nalda-sync' ); ?></th>
                    <td style="padding: 12px; border: 1px solid #e5e5e5;"><?php echo esc_html( number_format( floatval( $data['original_total'] ), 2 ) . ' ' . $currency ); ?></td>
                </tr>
                <tr>
                    <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8; border: 1px solid #e5e5e5;"><?php esc_html_e( 'Nalda Commission', 'woo-nalda-sync' ); ?></th>
                    <td style="padding: 12px; border: 1px solid #e5e5e5;"><?php echo esc_html( number_format( floatval( $data['total_commission'] ), 2 ) . ' ' . $currency ); ?></td>
                </tr>
                <tr>
                    <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8; border: 1px solid #e5e5e5;"><?php esc_html_e( 'Net Amount (your payout)', 'woo-nalda-sync' ); ?></th>
                    <td style="padding: 12px; border: 1px solid #e5e5e5;"><strong><?php echo esc_html( number_format( floatval( $data['order_total'] ), 2 ) . ' ' . $currency ); ?></strong></td>
                </tr>
                <tr>
                    <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8; border: 1px solid #e5e5e5;"><?php esc_html_e( 'Payout Status', 'woo-nalda-sync' ); ?></th>
                    <td style="padding: 12px; border: 1px solid #e5e5e5;"><?php echo esc_html( $data['payout_status'] ?: __( 'Pending', 'woo-nalda-sync' ) ); ?></td>
                </tr>
                <tr>
                    <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8; border: 1px solid #e5e5e5;"><?php esc_html_e( 'Delivery Status', 'woo-nalda-sync' ); ?></th>
                    <td style="padding: 12px; border: 1px solid #e5e5e5;"><?php echo esc_html( $data['delivery_status'] ?: __( 'N/A', 'woo-nalda-sync' ) ); ?></td>
                </tr>
                <?php if ( ! empty( $data['delivery_date'] ) ) : ?>
                <tr>
                    <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8; border: 1px solid #e5e5e5;"><?php esc_html_e( 'Expected Delivery', 'woo-nalda-sync' ); ?></th>
                    <td style="padding: 12px; border: 1px solid #e5e5e5;"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $data['delivery_date'] ) ) ); ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <h2><?php esc_html_e( 'Ship To (End Customer)', 'woo-nalda-sync' ); ?></h2>
        <table cellspacing="0" cellpadding="6" border="1" style="width: 100%; border: 1px solid #e5e5e5; border-collapse: collapse; margin-bottom: 20px;">
            <tbody>
                <tr>
                    <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8; border: 1px solid #e5e5e5;"><?php esc_html_e( 'Name', 'woo-nalda-sync' ); ?></th>
                    <td style="padding: 12px; border: 1px solid #e5e5e5;"><?php echo esc_html( $data['shipping_name'] ); ?></td>
                </tr>
                <tr>
                    <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8; border: 1px solid #e5e5e5;"><?php esc_html_e( 'Address', 'woo-nalda-sync' ); ?></th>
                    <td style="padding: 12px; border: 1px solid #e5e5e5;"><?php echo esc_html( $data['shipping_address'] ); ?></td>
                </tr>
                <tr>
                    <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8; border: 1px solid #e5e5e5;"><?php esc_html_e( 'City / Postcode', 'woo-nalda-sync' ); ?></th>
                    <td style="padding: 12px; border: 1px solid #e5e5e5;"><?php echo esc_html( $data['shipping_postcode'] . ' ' . $data['shipping_city'] ); ?></td>
                </tr>
                <tr>
                    <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8; border: 1px solid #e5e5e5;"><?php esc_html_e( 'Country', 'woo-nalda-sync' ); ?></th>
                    <td style="padding: 12px; border: 1px solid #e5e5e5;"><?php echo esc_html( WC()->countries->countries[ $data['shipping_country'] ] ?? $data['shipping_country'] ); ?></td>
                </tr>
                <?php if ( ! empty( $data['end_customer_email'] ) ) : ?>
                <tr>
                    <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8; border: 1px solid #e5e5e5;"><?php esc_html_e( 'Customer Email', 'woo-nalda-sync' ); ?></th>
                    <td style="padding: 12px; border: 1px solid #e5e5e5;"><?php echo esc_html( $data['end_customer_email'] ); ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render Nalda details in plain text email format
     *
     * @param array $data Email data.
     */
    private function render_nalda_email_details_plain( $data ) {
        $currency = $data['currency'];

        echo "\n";
        echo "==========\n";
        echo esc_html__( 'NALDA MARKETPLACE DETAILS', 'woo-nalda-sync' ) . "\n";
        echo "==========\n\n";

        /* translators: %s: Nalda order ID */
        printf( esc_html__( 'Nalda Order ID: %s', 'woo-nalda-sync' ) . "\n", esc_html( $data['nalda_order_id'] ) );
        /* translators: %s: Amount with currency */
        printf( esc_html__( 'Customer Total (incl. commission): %s', 'woo-nalda-sync' ) . "\n", esc_html( number_format( floatval( $data['original_total'] ), 2 ) . ' ' . $currency ) );
        /* translators: %s: Amount with currency */
        printf( esc_html__( 'Nalda Commission: %s', 'woo-nalda-sync' ) . "\n", esc_html( number_format( floatval( $data['total_commission'] ), 2 ) . ' ' . $currency ) );
        /* translators: %s: Amount with currency */
        printf( esc_html__( 'Net Amount (your payout): %s', 'woo-nalda-sync' ) . "\n", esc_html( number_format( floatval( $data['order_total'] ), 2 ) . ' ' . $currency ) );
        /* translators: %s: Payout status */
        printf( esc_html__( 'Payout Status: %s', 'woo-nalda-sync' ) . "\n", esc_html( $data['payout_status'] ?: __( 'Pending', 'woo-nalda-sync' ) ) );
        /* translators: %s: Delivery status */
        printf( esc_html__( 'Delivery Status: %s', 'woo-nalda-sync' ) . "\n", esc_html( $data['delivery_status'] ?: __( 'N/A', 'woo-nalda-sync' ) ) );

        if ( ! empty( $data['delivery_date'] ) ) {
            /* translators: %s: Delivery date */
            printf( esc_html__( 'Expected Delivery: %s', 'woo-nalda-sync' ) . "\n", esc_html( date_i18n( get_option( 'date_format' ), strtotime( $data['delivery_date'] ) ) ) );
        }

        echo "\n";
        echo "----------\n";
        echo esc_html__( 'SHIP TO (END CUSTOMER)', 'woo-nalda-sync' ) . "\n";
        echo "----------\n\n";

        /* translators: %s: Customer name */
        printf( esc_html__( 'Name: %s', 'woo-nalda-sync' ) . "\n", esc_html( $data['shipping_name'] ) );
        /* translators: %s: Street address */
        printf( esc_html__( 'Address: %s', 'woo-nalda-sync' ) . "\n", esc_html( $data['shipping_address'] ) );
        /* translators: %s: City with postcode */
        printf( esc_html__( 'City / Postcode: %s', 'woo-nalda-sync' ) . "\n", esc_html( $data['shipping_postcode'] . ' ' . $data['shipping_city'] ) );
        /* translators: %s: Country name */
        printf( esc_html__( 'Country: %s', 'woo-nalda-sync' ) . "\n", esc_html( WC()->countries->countries[ $data['shipping_country'] ] ?? $data['shipping_country'] ) );

        if ( ! empty( $data['end_customer_email'] ) ) {
            /* translators: %s: Customer email */
            printf( esc_html__( 'Customer Email: %s', 'woo-nalda-sync' ) . "\n", esc_html( $data['end_customer_email'] ) );
        }

        echo "\n";
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
