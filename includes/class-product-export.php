<?php
/**
 * Product Export class
 *
 * @package Woo_Nalda_Sync
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Product Export class
 */
class WNS_Product_Export {

    /**
     * CSV columns (36 columns as per Nalda template)
     *
     * @var array
     */
    private $csv_columns = array(
        'gtin',
        'title',
        'country',
        'condition',
        'price',
        'tax',
        'currency',
        'delivery_time_days',
        'stock',
        'return_days',
        'main_image_url',
        'brand',
        'category',
        'google_category',
        'seller_category',
        'description',
        'length_mm',
        'width_mm',
        'height_mm',
        'weight_g',
        'shipping_length_mm',
        'shipping_width_mm',
        'shipping_height_mm',
        'shipping_weight_g',
        'volume_ml',
        'size',
        'colour',
        'image_2_url',
        'image_3_url',
        'image_4_url',
        'image_5_url',
        'delete_product',
        'author',
        'language',
        'format',
        'year',
        'publisher',
    );

    /**
     * Constructor
     */
    public function __construct() {
        // Nothing to initialize
    }

    /**
     * Run product export
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

        // Get products to export
        $products = $this->get_products();

        if ( empty( $products ) ) {
            $stats = array(
                'total'    => 0,
                'exported' => 0,
                'skipped'  => 0,
                'errors'   => 0,
            );

            $this->log_result( $trigger_type, $stats, array(), microtime( true ) - $start_time );
            update_option( 'wns_last_product_export_time', time() );
            update_option( 'wns_last_product_export_stats', $stats );

            return $stats;
        }

        // Generate CSV
        $csv_data = $this->generate_csv( $products );

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
            'total'    => count( $products ),
            'exported' => $csv_data['exported'],
            'skipped'  => $csv_data['skipped'],
            'errors'   => count( $csv_data['errors'] ),
        );

        $duration = microtime( true ) - $start_time;

        $this->log_result( $trigger_type, $stats, $csv_data['errors'], $duration );

        update_option( 'wns_last_product_export_time', time() );
        update_option( 'wns_last_product_export_stats', $stats );

        return $stats;
    }

    /**
     * Get products to export
     *
     * @return array
     */
    private function get_products() {
        $default_behavior = get_option( 'wns_product_default_behavior', 'include' );

        $args = array(
            'status'    => 'publish',
            'limit'     => -1,
            'orderby'   => 'ID',
            'order'     => 'ASC',
        );

        $products = wc_get_products( $args );
        $filtered = array();

        foreach ( $products as $product ) {
            if ( ! $this->should_export_product( $product, $default_behavior ) ) {
                continue;
            }

            $filtered[] = $product;
        }

        return $filtered;
    }

    /**
     * Check if product should be exported
     *
     * @param WC_Product $product Product object.
     * @param string     $default_behavior Default behavior (include/exclude).
     * @return bool
     */
    private function should_export_product( $product, $default_behavior ) {
        $export_setting = get_post_meta( $product->get_id(), '_wns_nalda_export', true );

        // Determine inclusion based on setting
        if ( 'include' === $export_setting ) {
            $should_include = true;
        } elseif ( 'exclude' === $export_setting ) {
            $should_include = false;
        } else {
            // Follow default
            $should_include = ( 'include' === $default_behavior );
        }

        if ( ! $should_include ) {
            return false;
        }

        // Must have GTIN
        $gtin = $this->get_product_gtin( $product );
        if ( empty( $gtin ) ) {
            return false;
        }

        // Must have price
        $price = $product->get_price();
        if ( empty( $price ) || $price <= 0 ) {
            return false;
        }

        return true;
    }

    /**
     * Get product GTIN
     *
     * @param WC_Product $product Product object.
     * @return string
     */
    private function get_product_gtin( $product ) {
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
            $gtin = $product->get_global_unique_id();
            if ( ! empty( $gtin ) ) {
                return $gtin;
            }
        }

        return '';
    }

    /**
     * Generate CSV
     *
     * @param array $products Products to export.
     * @return array|WP_Error
     */
    private function generate_csv( $products ) {
        $output   = fopen( 'php://temp', 'r+' );
        $exported = 0;
        $skipped  = 0;
        $errors   = array();

        // Write header
        fputcsv( $output, $this->csv_columns );

        foreach ( $products as $product ) {
            try {
                $row = $this->map_product_to_csv( $product );
                fputcsv( $output, $row );
                $exported++;
            } catch ( Exception $e ) {
                $errors[] = sprintf(
                    /* translators: 1: Product ID, 2: Error message */
                    __( 'Product #%1$d: %2$s', 'woo-nalda-sync' ),
                    $product->get_id(),
                    $e->getMessage()
                );
                $skipped++;
            }
        }

        rewind( $output );
        $csv = stream_get_contents( $output );
        fclose( $output );

        $filename = 'products_' . gmdate( 'Y-m-d_H-i-s' ) . '.csv';

        return array(
            'csv'      => $csv,
            'filename' => $filename,
            'exported' => $exported,
            'skipped'  => $skipped,
            'errors'   => $errors,
        );
    }

    /**
     * Map product to CSV row
     *
     * @param WC_Product $product Product object.
     * @return array
     */
    private function map_product_to_csv( $product ) {
        // Get country and currency from WooCommerce settings
        $default_country       = WC()->countries->get_base_country();
        $default_currency      = get_woocommerce_currency();
        $default_delivery_days = get_option( 'wns_default_delivery_days', '5' );
        $default_return_days   = get_option( 'wns_default_return_days', '14' );

        // Get images
        $images     = $this->get_product_images( $product );
        $main_image = isset( $images[0] ) ? $images[0] : '';

        // Get dimensions
        $length = $product->get_length();
        $width  = $product->get_width();
        $height = $product->get_height();
        $weight = $product->get_weight();

        // Convert to mm/g if needed (assuming WooCommerce uses cm/kg)
        $length_mm = ! empty( $length ) ? floatval( $length ) * 10 : '';
        $width_mm  = ! empty( $width ) ? floatval( $width ) * 10 : '';
        $height_mm = ! empty( $height ) ? floatval( $height ) * 10 : '';
        $weight_g  = ! empty( $weight ) ? floatval( $weight ) * 1000 : '';

        // Get categories
        $categories      = $this->get_product_categories( $product );
        $category        = isset( $categories[0] ) ? $categories[0] : '';
        $seller_category = implode( ' > ', $categories );

        // Get brand (from attribute or custom field)
        $brand = $this->get_product_brand( $product );

        // Get price and tax
        $price_incl_tax = wc_get_price_including_tax( $product );
        $price_excl_tax = wc_get_price_excluding_tax( $product );
        $tax            = $price_incl_tax - $price_excl_tax;

        return array(
            $this->get_product_gtin( $product ),                                  // gtin
            $product->get_name(),                                                  // title
            $default_country,                                                      // country
            $product->get_meta( '_condition' ) ?: 'new',                          // condition
            number_format( $price_incl_tax, 2, '.', '' ),                         // price
            number_format( $tax, 2, '.', '' ),                                    // tax
            $default_currency,                                                     // currency
            $default_delivery_days,                                                // delivery_time_days
            $product->get_stock_quantity() ?: 0,                                  // stock
            $default_return_days,                                                  // return_days
            $main_image,                                                           // main_image_url
            $brand,                                                                // brand
            $category,                                                             // category
            $product->get_meta( '_google_category' ) ?: '',                       // google_category
            $seller_category,                                                      // seller_category
            wp_strip_all_tags( $product->get_description() ),                     // description
            $length_mm,                                                            // length_mm
            $width_mm,                                                             // width_mm
            $height_mm,                                                            // height_mm
            $weight_g,                                                             // weight_g
            $length_mm,                                                            // shipping_length_mm
            $width_mm,                                                             // shipping_width_mm
            $height_mm,                                                            // shipping_height_mm
            $weight_g,                                                             // shipping_weight_g
            $product->get_meta( '_volume_ml' ) ?: '',                             // volume_ml
            $product->get_meta( '_size' ) ?: $this->get_attribute( $product, 'size' ),   // size
            $product->get_meta( '_colour' ) ?: $this->get_attribute( $product, 'color' ), // colour
            isset( $images[1] ) ? $images[1] : '',                                // image_2_url
            isset( $images[2] ) ? $images[2] : '',                                // image_3_url
            isset( $images[3] ) ? $images[3] : '',                                // image_4_url
            isset( $images[4] ) ? $images[4] : '',                                // image_5_url
            '',                                                                    // delete_product
            $product->get_meta( '_author' ) ?: '',                                // author
            $product->get_meta( '_language' ) ?: '',                              // language
            $product->get_meta( '_format' ) ?: '',                                // format
            $product->get_meta( '_year' ) ?: '',                                  // year
            $product->get_meta( '_publisher' ) ?: '',                             // publisher
        );
    }

    /**
     * Get product images
     *
     * @param WC_Product $product Product object.
     * @return array
     */
    private function get_product_images( $product ) {
        $images = array();

        // Main image
        $main_image_id = $product->get_image_id();
        if ( $main_image_id ) {
            $images[] = wp_get_attachment_url( $main_image_id );
        }

        // Gallery images
        $gallery_ids = $product->get_gallery_image_ids();
        foreach ( $gallery_ids as $image_id ) {
            $images[] = wp_get_attachment_url( $image_id );
        }

        return array_slice( $images, 0, 5 ); // Max 5 images
    }

    /**
     * Get product categories
     *
     * @param WC_Product $product Product object.
     * @return array
     */
    private function get_product_categories( $product ) {
        $categories = array();
        $term_ids   = $product->get_category_ids();

        foreach ( $term_ids as $term_id ) {
            $term = get_term( $term_id, 'product_cat' );
            if ( $term && ! is_wp_error( $term ) ) {
                $categories[] = $term->name;
            }
        }

        return $categories;
    }

    /**
     * Get product brand
     *
     * @param WC_Product $product Product object.
     * @return string
     */
    private function get_product_brand( $product ) {
        // Check common brand meta fields
        $brand_fields = array( '_brand', 'brand', '_manufacturer' );

        foreach ( $brand_fields as $field ) {
            $brand = $product->get_meta( $field );
            if ( ! empty( $brand ) ) {
                return $brand;
            }
        }

        // Check brand attribute
        $brand = $this->get_attribute( $product, 'brand' );
        if ( ! empty( $brand ) ) {
            return $brand;
        }

        // Check brand taxonomy (popular brand plugins)
        $terms = wp_get_post_terms( $product->get_id(), 'product_brand', array( 'fields' => 'names' ) );
        if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
            return $terms[0];
        }

        return '';
    }

    /**
     * Get product attribute
     *
     * @param WC_Product $product Product object.
     * @param string     $attribute_name Attribute name.
     * @return string
     */
    private function get_attribute( $product, $attribute_name ) {
        $attribute = $product->get_attribute( $attribute_name );
        return ! empty( $attribute ) ? $attribute : '';
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

        // Create temporary file
        $temp_file = wp_tempnam( $filename );
        file_put_contents( $temp_file, $csv );

        $boundary = wp_generate_password( 24, false );

        // Build multipart body
        $body = '';
        
        // Add form fields
        $fields = array(
            'license_key'   => $license_key,
            'product_slug'  => WNS_PRODUCT_SLUG,
            'domain'        => $this->get_domain(),
            'csv_type'      => 'products',
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

        // Clean up temp file
        @unlink( $temp_file );

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
                    'type'    => 'product_export',
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
                __( 'Exported %1$d products, skipped %2$d.', 'woo-nalda-sync' ),
                $result['exported'],
                $result['skipped']
            );

            WNS()->logs->add(
                array(
                    'type'    => 'product_export',
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
