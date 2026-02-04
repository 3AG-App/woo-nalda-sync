<?php
/**
 * Delivery Note PDF Generator
 *
 * Generates PDF delivery notes for Nalda orders.
 *
 * @package Woo_Nalda_Sync
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Delivery Note PDF class.
 */
class WNS_Delivery_Note_PDF {

    /**
     * Order object.
     *
     * @var WC_Order
     */
    private $order;

    /**
     * Language code for the PDF.
     *
     * @var string
     */
    private $language;

    /**
     * Constructor.
     *
     * @param WC_Order $order    Order object.
     * @param string   $language Language code (e.g., 'en_US', 'de_DE'). Default empty uses site language.
     */
    public function __construct( $order, $language = '' ) {
        $this->order    = $order;
        $this->language = $language;
        
        // Switch to the specified language if provided.
        if ( ! empty( $this->language ) ) {
            $this->switch_language( $this->language );
        }
    }

    /**
     * Switch to a specific language.
     *
     * @param string $locale The locale to switch to.
     */
    private function switch_language( $locale ) {
        // Switch locale for translations.
        switch_to_locale( $locale );
        
        // Reload text domain for the new locale.
        unload_textdomain( 'woo-nalda-sync' );
        load_plugin_textdomain( 'woo-nalda-sync', false, dirname( plugin_basename( WNS_PLUGIN_FILE ) ) . '/languages' );
    }

    /**
     * Generate and output the PDF.
     */
    public function generate() {
        // Check if TCPDF is available, otherwise use FPDF fallback.
        if ( class_exists( 'TCPDF' ) ) {
            $this->generate_with_tcpdf();
        } else {
            $this->generate_with_html_pdf();
        }
    }

    /**
     * Generate PDF using HTML to PDF conversion (built-in approach).
     */
    private function generate_with_html_pdf() {
        $html = $this->get_delivery_note_html();
        
        // Use DOMPDF if available.
        if ( class_exists( 'Dompdf\Dompdf' ) ) {
            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml( $html );
            $dompdf->setPaper( 'A4', 'portrait' );
            $dompdf->render();
            $dompdf->stream( $this->get_filename(), array( 'Attachment' => true ) );
            exit;
        }
        
        // Fallback: Output HTML with print styles.
        $this->output_printable_html( $html );
    }

    /**
     * Generate PDF using TCPDF.
     */
    private function generate_with_tcpdf() {
        $pdf = new TCPDF( 'P', 'mm', 'A4', true, 'UTF-8', false );
        
        // Set document information.
        $pdf->SetCreator( get_bloginfo( 'name' ) );
        $pdf->SetAuthor( get_bloginfo( 'name' ) );
        $pdf->SetTitle( sprintf( __( 'Delivery Note #%s', 'woo-nalda-sync' ), $this->order->get_order_number() ) );
        
        // Remove default header/footer.
        $pdf->setPrintHeader( false );
        $pdf->setPrintFooter( false );
        
        // Set margins.
        $pdf->SetMargins( 15, 15, 15 );
        
        // Add a page.
        $pdf->AddPage();
        
        // Write HTML content.
        $pdf->writeHTML( $this->get_delivery_note_html( false ), true, false, true, false, '' );
        
        // Output PDF.
        $pdf->Output( $this->get_filename(), 'D' );
        exit;
    }

    /**
     * Output printable HTML (fallback when no PDF library is available).
     *
     * @param string $html HTML content.
     */
    private function output_printable_html( $html ) {
        // Get current language for highlighting.
        $current_lang = $this->language ?: get_locale();
        $is_english = ( strpos( $current_lang, 'en' ) === 0 );
        $is_german = ( strpos( $current_lang, 'de' ) === 0 );
        
        // Build language switcher URLs.
        $base_url = remove_query_arg( 'lang' );
        $en_url = add_query_arg( 'lang', 'en_US', $base_url );
        $de_url = add_query_arg( 'lang', 'de_DE', $base_url );
        
        $full_html = '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>' . esc_html( sprintf( __( 'Delivery Note #%s', 'woo-nalda-sync' ), $this->order->get_order_number() ) ) . '</title>
            <style>
                @media print {
                    body { margin: 0; padding: 20px; }
                    .no-print { display: none !important; }
                }
                @page { margin: 15mm; }
                .lang-btn {
                    padding: 8px 16px;
                    font-size: 14px;
                    cursor: pointer;
                    border: 1px solid #ccc;
                    background: #fff;
                    margin: 0 5px;
                    border-radius: 4px;
                    text-decoration: none;
                    color: #333;
                }
                .lang-btn:hover {
                    background: #e0e0e0;
                }
                .lang-btn.active {
                    background: #0073aa;
                    color: #fff;
                    border-color: #0073aa;
                }
            </style>
        </head>
        <body>
            <div class="no-print" style="background: #f0f0f0; padding: 10px; margin-bottom: 20px; text-align: center;">
                <div style="margin-bottom: 10px;">
                    <span style="margin-right: 10px; font-weight: bold;">' . esc_html__( 'Language', 'woo-nalda-sync' ) . ':</span>
                    <a href="' . esc_url( $en_url ) . '" class="lang-btn' . ( $is_english ? ' active' : '' ) . '">English</a>
                    <a href="' . esc_url( $de_url ) . '" class="lang-btn' . ( $is_german ? ' active' : '' ) . '">Deutsch</a>
                </div>
                <button onclick="window.print()" style="padding: 10px 20px; font-size: 16px; cursor: pointer;">
                    ' . esc_html__( 'Print / Save as PDF', 'woo-nalda-sync' ) . '
                </button>
            </div>
            ' . $html . '
        </body>
        </html>';
        
        echo $full_html;
        exit;
    }

    /**
     * Get delivery note HTML content.
     *
     * @param bool $include_full_styles Whether to include full CSS styles.
     * @return string HTML content.
     */
    private function get_delivery_note_html( $include_full_styles = true ) {
        $order = $this->order;
        
        // Get seller info from WooCommerce settings.
        $store_name    = get_bloginfo( 'name' );
        $store_address = WC()->countries->get_base_address();
        $store_address_2 = WC()->countries->get_base_address_2();
        $store_city    = WC()->countries->get_base_city();
        $store_postcode = WC()->countries->get_base_postcode();
        $store_country = WC()->countries->get_base_country();
        $store_state   = WC()->countries->get_base_state();
        
        // Format seller address.
        $seller_address_parts = array_filter( array(
            $store_address,
            $store_address_2,
            trim( $store_postcode . ' ' . $store_city ),
            WC()->countries->get_states( $store_country )[ $store_state ] ?? $store_state,
            WC()->countries->countries[ $store_country ] ?? $store_country,
        ) );
        $seller_address = implode( '<br>', $seller_address_parts );
        
        // Get buyer info (end customer from shipping address).
        $buyer_name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
        if ( empty( $buyer_name ) ) {
            $buyer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        }
        
        $buyer_address_parts = array_filter( array(
            $order->get_shipping_address_1() ?: $order->get_billing_address_1(),
            $order->get_shipping_address_2() ?: $order->get_billing_address_2(),
            trim( ( $order->get_shipping_postcode() ?: $order->get_billing_postcode() ) . ' ' . ( $order->get_shipping_city() ?: $order->get_billing_city() ) ),
            WC()->countries->countries[ $order->get_shipping_country() ?: $order->get_billing_country() ] ?? '',
        ) );
        $buyer_address = implode( '<br>', $buyer_address_parts );
        
        // Get Nalda order ID.
        $nalda_order_id = $order->get_meta( '_nalda_order_id' );
        
        // Get logo from plugin settings, fallback to site logo.
        $logo_html = '';
        $settings = wns_get_settings();
        $delivery_note_logo_id = isset( $settings['delivery_note_logo_id'] ) ? absint( $settings['delivery_note_logo_id'] ) : 0;
        
        if ( $delivery_note_logo_id ) {
            $logo_url = wp_get_attachment_image_url( $delivery_note_logo_id, 'medium' );
            if ( $logo_url ) {
                $logo_html = '<img src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( $store_name ) . '" style="max-height: 60px; max-width: 200px;">';
            }
        }
        
        // Get Nalda logo.
        $nalda_logo_url = WNS_PLUGIN_URL . 'assets/images/nalda-logo.webp';
        $nalda_logo_html = '<img src="' . esc_url( $nalda_logo_url ) . '" alt="Nalda" style="max-height: 40px; max-width: 150px;">';
        
        // Build product rows.
        $items_html = '';
        $row_num = 1;
        $grand_total = 0;
        
        foreach ( $order->get_items() as $item ) {
            $quantity = $item->get_quantity();
            
            // Use the customer price from Nalda (what the end client paid).
            $customer_price = $item->get_meta( '_nalda_customer_price' );
            if ( $customer_price ) {
                $unit_price = floatval( $customer_price );
                $total = $unit_price * $quantity;
            } else {
                // Fallback to order item price if Nalda customer price not available.
                $unit_price = $item->get_total() / max( 1, $quantity );
                $total = $item->get_total();
            }
            $grand_total += $total;
            
            $items_html .= '<tr>
                <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">' . $row_num . '</td>
                <td style="border: 1px solid #ddd; padding: 8px;">' . esc_html( $item->get_name() ) . '</td>
                <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">' . $quantity . '</td>
                <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">' . wc_price( $unit_price, array( 'currency' => $order->get_currency() ) ) . '</td>
                <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">' . wc_price( $total, array( 'currency' => $order->get_currency() ) ) . '</td>
            </tr>';
            
            $row_num++;
        }
        
        // Currency symbol for display.
        $currency = $order->get_currency();
        
        // Build styles.
        $styles = $include_full_styles ? '
        <style>
            body { font-family: Arial, sans-serif; font-size: 12px; line-height: 1.4; color: #333; }
            .delivery-note { max-width: 800px; margin: 0 auto; padding: 20px; }
            .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 30px; }
            .logo { }
            .document-title { text-align: right; }
            .document-title h1 { margin: 0; font-size: 24px; color: #333; }
            .document-title p { margin: 5px 0 0; color: #666; }
            .addresses { display: flex; justify-content: space-between; margin-bottom: 30px; }
            .address-box { width: 45%; }
            .address-box h3 { margin: 0 0 10px; font-size: 14px; color: #666; border-bottom: 2px solid #333; padding-bottom: 5px; }
            .address-box p { margin: 0; }
            .via-nalda { color: #e67e22; font-weight: bold; font-size: 11px; }
            .items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            .items-table th { background: #f5f5f5; border: 1px solid #ddd; padding: 10px 8px; text-align: left; font-weight: bold; }
            .items-table td { border: 1px solid #ddd; padding: 8px; }
            .grand-total { text-align: right; margin-bottom: 30px; }
            .grand-total table { margin-left: auto; }
            .grand-total td { padding: 5px 10px; }
            .grand-total .total-label { font-weight: bold; }
            .grand-total .total-value { font-weight: bold; font-size: 16px; }
            .delivery-info { margin-bottom: 30px; }
            .delivery-info table { width: 100%; }
            .delivery-info td { padding: 8px 5px; vertical-align: top; }
            .checkbox { display: inline-block; width: 14px; height: 14px; border: 1px solid #333; margin-right: 5px; vertical-align: middle; }
            .signature-section { margin-top: 40px; }
            .signature-line { border-bottom: 1px solid #333; width: 250px; margin-top: 40px; }
            .signature-label { font-size: 10px; color: #666; margin-top: 5px; }
            .thank-you { text-align: center; margin-top: 40px; font-style: italic; color: #666; }
        </style>
        ' : '';
        
        // Build HTML.
        $html = $styles . '
        <div class="delivery-note">
            <!-- Header -->
            <table style="width: 100%; margin-bottom: 30px;">
                <tr>
                    <td style="width: 50%; vertical-align: top;">
                        ' . ( $logo_html ?: '<strong style="font-size: 18px;">' . esc_html( $store_name ) . '</strong>' ) . '
                    </td>
                    <td style="width: 50%; text-align: right; vertical-align: top;">
                        <h1 style="margin: 0; font-size: 24px; color: #333;">' . esc_html__( 'Delivery Note', 'woo-nalda-sync' ) . '</h1>
                        <p style="margin: 5px 0 0; color: #666;">
                            ' . esc_html__( 'Order', 'woo-nalda-sync' ) . ' #' . esc_html( $order->get_order_number() ) . '<br>
                            ' . esc_html__( 'Date', 'woo-nalda-sync' ) . ': ' . esc_html( $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) ) . '
                            ' . ( $nalda_order_id ? '<br><span style="color: #e67e22;">Nalda #' . esc_html( $nalda_order_id ) . '</span>' : '' ) . '
                        </p>
                        ' . ( $nalda_order_id ? '<div style="margin-top: 10px;">' . $nalda_logo_html . '</div>' : '' ) . '
                    </td>
                </tr>
            </table>
            
            <!-- Addresses -->
            <table style="width: 100%; margin-bottom: 30px;">
                <tr>
                    <td style="width: 48%; vertical-align: top; padding-right: 20px;">
                        <h3 style="margin: 0 0 10px; font-size: 14px; color: #666; border-bottom: 2px solid #333; padding-bottom: 5px;">' . esc_html__( 'Seller', 'woo-nalda-sync' ) . '</h3>
                        <p style="margin: 0;">
                            <strong>' . esc_html( $store_name ) . '</strong><br>
                            ' . $seller_address . '
                        </p>
                    </td>
                    <td style="width: 48%; vertical-align: top;">
                        <h3 style="margin: 0 0 10px; font-size: 14px; color: #666; border-bottom: 2px solid #333; padding-bottom: 5px;">' . esc_html__( 'Buyer', 'woo-nalda-sync' ) . '</h3>
                        <p style="margin: 0;">
                            <strong>' . esc_html( $buyer_name ) . '</strong><br>
                            ' . $buyer_address . '
                            ' . ( $nalda_order_id ? '<br><span style="color: #e67e22; font-weight: bold; font-size: 11px;">' . esc_html__( 'via Nalda', 'woo-nalda-sync' ) . '</span>' : '' ) . '
                        </p>
                    </td>
                </tr>
            </table>
            
            <!-- Products Table -->
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                <thead>
                    <tr>
                        <th style="background: #f5f5f5; border: 1px solid #ddd; padding: 10px 8px; text-align: center; width: 40px;">' . esc_html__( 'No', 'woo-nalda-sync' ) . '</th>
                        <th style="background: #f5f5f5; border: 1px solid #ddd; padding: 10px 8px; text-align: left;">' . esc_html__( 'Description', 'woo-nalda-sync' ) . '</th>
                        <th style="background: #f5f5f5; border: 1px solid #ddd; padding: 10px 8px; text-align: center; width: 60px;">' . esc_html__( 'Quantity', 'woo-nalda-sync' ) . '</th>
                        <th style="background: #f5f5f5; border: 1px solid #ddd; padding: 10px 8px; text-align: right; width: 90px;">' . esc_html__( 'Unit price', 'woo-nalda-sync' ) . '</th>
                        <th style="background: #f5f5f5; border: 1px solid #ddd; padding: 10px 8px; text-align: right; width: 90px;">' . esc_html__( 'Total', 'woo-nalda-sync' ) . '</th>
                    </tr>
                </thead>
                <tbody>
                    ' . $items_html . '
                </tbody>
            </table>
            
            <!-- Grand Total -->
            <table style="margin-left: auto; margin-bottom: 30px;">
                <tr>
                    <td style="padding: 5px 20px; font-weight: bold; text-align: right;">' . esc_html__( 'Grand Total', 'woo-nalda-sync' ) . ':</td>
                    <td style="padding: 5px 10px; font-weight: bold; font-size: 16px; text-align: right;">' . wc_price( $grand_total, array( 'currency' => $currency ) ) . '</td>
                </tr>
            </table>
            
            <!-- Delivery Information -->
            <table style="width: 100%; margin-bottom: 30px; border: 1px solid #ddd;">
                <tr>
                    <td style="padding: 10px; width: 150px; background: #f5f5f5; font-weight: bold;">' . esc_html__( 'Delivery type', 'woo-nalda-sync' ) . ':</td>
                    <td style="padding: 10px;">
                        ' . $this->get_shipping_methods_html() . '
                    </td>
                </tr>
            </table>
            
        </div>';
        
        return $html;
    }

    /**
     * Get shipping methods HTML from WooCommerce shipping zones.
     *
     * @return string HTML with shipping method checkboxes.
     */
    private function get_shipping_methods_html() {
        $shipping_methods = array();
        
        // Get all shipping zones.
        $zones = WC_Shipping_Zones::get_zones();
        
        // Add methods from each zone.
        foreach ( $zones as $zone ) {
            $zone_obj = new WC_Shipping_Zone( $zone['id'] );
            $methods = $zone_obj->get_shipping_methods( true ); // true = only enabled methods
            
            foreach ( $methods as $method ) {
                $method_title = $method->get_title();
                if ( ! empty( $method_title ) && ! in_array( $method_title, $shipping_methods, true ) ) {
                    $shipping_methods[] = $method_title;
                }
            }
        }
        
        // Also get methods from "Rest of the World" zone (zone 0).
        $zone_zero = new WC_Shipping_Zone( 0 );
        $methods = $zone_zero->get_shipping_methods( true );
        
        foreach ( $methods as $method ) {
            $method_title = $method->get_title();
            if ( ! empty( $method_title ) && ! in_array( $method_title, $shipping_methods, true ) ) {
                $shipping_methods[] = $method_title;
            }
        }
        
        // If no shipping methods found, return a placeholder.
        if ( empty( $shipping_methods ) ) {
            return '<span style="display: inline-block; width: 14px; height: 14px; border: 1px solid #333; margin-right: 5px; vertical-align: middle;">&#160;</span> ' . esc_html__( 'N/A', 'woo-nalda-sync' );
        }
        
        // Build HTML for each shipping method.
        $html_parts = array();
        foreach ( $shipping_methods as $method_name ) {
            $html_parts[] = '<span style="display: inline-block; width: 14px; height: 14px; border: 1px solid #333; margin-right: 5px; vertical-align: middle;">&#160;</span> ' . esc_html( $method_name );
        }
        
        return implode( '&#160;&#160;&#160;', $html_parts );
    }

    /**
     * Get PDF filename.
     *
     * @return string Filename.
     */
    private function get_filename() {
        return sprintf(
            'delivery-note-%s-%s.pdf',
            $this->order->get_order_number(),
            date( 'Y-m-d' )
        );
    }
}
