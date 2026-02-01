<?php
/**
 * Settings View
 *
 * @package Woo_Nalda_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$license_valid = WNS()->license->is_valid();
$intervals     = WNS()->scheduler->get_intervals();

// Get current settings
$sftp_host     = get_option( 'wns_sftp_host', '' );
$sftp_port     = get_option( 'wns_sftp_port', '22' );
$sftp_username = get_option( 'wns_sftp_username', '' );
$sftp_password = get_option( 'wns_sftp_password', '' );
$nalda_api_key = get_option( 'wns_nalda_api_key', '' );

$product_export_interval      = get_option( 'wns_product_export_interval', 'daily' );
$product_export_enabled       = get_option( 'wns_product_export_enabled', false );
$product_default_behavior     = get_option( 'wns_product_default_behavior', 'include' );
$order_import_interval        = get_option( 'wns_order_import_interval', 'hourly' );
$order_import_enabled         = get_option( 'wns_order_import_enabled', false );
$order_import_range           = get_option( 'wns_order_import_range', 7 );
$order_status_export_interval = get_option( 'wns_order_status_export_interval', 'hourly' );
$order_status_export_enabled  = get_option( 'wns_order_status_export_enabled', false );

// Get country and currency from WooCommerce settings
$default_country       = WC()->countries->get_base_country();
$default_currency      = get_woocommerce_currency();
$default_delivery_days = get_option( 'wns_default_delivery_days', 3 );
$default_return_days   = get_option( 'wns_default_return_days', 14 );
?>

<div class="wns-wrap">
    <div class="wns-header">
        <div class="wns-header-left">
            <h1><?php esc_html_e( 'Settings', 'woo-nalda-sync' ); ?></h1>
            <p class="wns-subtitle"><?php esc_html_e( 'Configure your Nalda marketplace synchronization settings', 'woo-nalda-sync' ); ?></p>
        </div>
    </div>

    <?php if ( ! $license_valid ) : ?>
        <div class="wns-notice wns-notice-warning">
            <span class="dashicons dashicons-lock"></span>
            <div>
                <strong><?php esc_html_e( 'License Required', 'woo-nalda-sync' ); ?></strong>
                <p><?php esc_html_e( 'Please activate your license before configuring settings.', 'woo-nalda-sync' ); ?></p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wns-license' ) ); ?>" class="wns-btn wns-btn-primary wns-btn-sm">
                    <?php esc_html_e( 'Activate License', 'woo-nalda-sync' ); ?>
                </a>
            </div>
        </div>
    <?php endif; ?>

    <form id="wns-settings-form" class="wns-form <?php echo ! $license_valid ? 'wns-form-disabled' : ''; ?>">
        <!-- API Configuration -->
        <div class="wns-section wns-card">
            <div class="wns-card-header">
                <h2>
                    <span class="dashicons dashicons-admin-network"></span>
                    <?php esc_html_e( 'API Configuration', 'woo-nalda-sync' ); ?>
                </h2>
            </div>
            <div class="wns-card-body">
                <div class="wns-form-row">
                    <label for="wns-nalda-api-key" class="wns-label">
                        <?php esc_html_e( 'Nalda API Key', 'woo-nalda-sync' ); ?>
                        <span class="wns-required">*</span>
                    </label>
                    <div class="wns-input-group">
                        <input type="password" 
                               id="wns-nalda-api-key" 
                               name="nalda_api_key" 
                               value="<?php echo esc_attr( $nalda_api_key ); ?>" 
                               class="wns-input wns-input-lg wns-input-mono"
                               placeholder="Enter your Nalda API key"
                               <?php disabled( ! $license_valid ); ?>>
                        <button type="button" id="wns-test-nalda-api" class="wns-btn wns-btn-secondary" <?php disabled( ! $license_valid ); ?>>
                            <span class="dashicons dashicons-yes-alt"></span>
                            <?php esc_html_e( 'Test Connection', 'woo-nalda-sync' ); ?>
                        </button>
                    </div>
                    <p class="wns-help-text">
                        <?php esc_html_e( 'Your API key from the Nalda marketplace seller dashboard.', 'woo-nalda-sync' ); ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- SFTP Configuration -->
        <div class="wns-section wns-card">
            <div class="wns-card-header">
                <h2>
                    <span class="dashicons dashicons-cloud-upload"></span>
                    <?php esc_html_e( 'SFTP Configuration', 'woo-nalda-sync' ); ?>
                </h2>
            </div>
            <div class="wns-card-body">
                <div class="wns-form-row wns-form-row-2col">
                    <div class="wns-form-col">
                        <label for="wns-sftp-host" class="wns-label">
                            <?php esc_html_e( 'SFTP Host', 'woo-nalda-sync' ); ?>
                            <span class="wns-required">*</span>
                        </label>
                        <input type="text" 
                               id="wns-sftp-host" 
                               name="sftp_host" 
                               value="<?php echo esc_attr( $sftp_host ); ?>" 
                               class="wns-input"
                               placeholder="sftp.nalda.com"
                               <?php disabled( ! $license_valid ); ?>>
                    </div>
                    <div class="wns-form-col">
                        <label for="wns-sftp-port" class="wns-label">
                            <?php esc_html_e( 'SFTP Port', 'woo-nalda-sync' ); ?>
                        </label>
                        <input type="number" 
                               id="wns-sftp-port" 
                               name="sftp_port" 
                               value="<?php echo esc_attr( $sftp_port ); ?>" 
                               class="wns-input"
                               placeholder="22"
                               <?php disabled( ! $license_valid ); ?>>
                    </div>
                </div>

                <div class="wns-form-row wns-form-row-2col">
                    <div class="wns-form-col">
                        <label for="wns-sftp-username" class="wns-label">
                            <?php esc_html_e( 'SFTP Username', 'woo-nalda-sync' ); ?>
                            <span class="wns-required">*</span>
                        </label>
                        <input type="text" 
                               id="wns-sftp-username" 
                               name="sftp_username" 
                               value="<?php echo esc_attr( $sftp_username ); ?>" 
                               class="wns-input"
                               <?php disabled( ! $license_valid ); ?>>
                    </div>
                    <div class="wns-form-col">
                        <label for="wns-sftp-password" class="wns-label">
                            <?php esc_html_e( 'SFTP Password', 'woo-nalda-sync' ); ?>
                            <span class="wns-required">*</span>
                        </label>
                        <input type="password" 
                               id="wns-sftp-password" 
                               name="sftp_password" 
                               value="<?php echo esc_attr( $sftp_password ); ?>" 
                               class="wns-input"
                               <?php disabled( ! $license_valid ); ?>>
                    </div>
                </div>

                <div class="wns-form-row">
                    <button type="button" id="wns-test-sftp" class="wns-btn wns-btn-secondary" <?php disabled( ! $license_valid ); ?>>
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php esc_html_e( 'Test SFTP Connection', 'woo-nalda-sync' ); ?>
                    </button>
                    <p class="wns-help-text">
                        <?php esc_html_e( 'SFTP credentials provided by Nalda for uploading CSV files.', 'woo-nalda-sync' ); ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Product Export Configuration -->
        <div class="wns-section wns-card">
            <div class="wns-card-header">
                <h2>
                    <span class="dashicons dashicons-products"></span>
                    <?php esc_html_e( 'Product Export', 'woo-nalda-sync' ); ?>
                </h2>
            </div>
            <div class="wns-card-body">
                <div class="wns-form-row wns-form-row-2col">
                    <div class="wns-form-col">
                        <label for="wns-product-export-interval" class="wns-label">
                            <?php esc_html_e( 'Export Interval', 'woo-nalda-sync' ); ?>
                        </label>
                        <select id="wns-product-export-interval" name="product_export_interval" class="wns-select" <?php disabled( ! $license_valid ); ?>>
                            <?php foreach ( $intervals as $key => $interval ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $product_export_interval, $key ); ?>>
                                    <?php echo esc_html( $interval['display'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="wns-form-col">
                        <label for="wns-product-default-behavior" class="wns-label">
                            <?php esc_html_e( 'Default Product Behavior', 'woo-nalda-sync' ); ?>
                        </label>
                        <select id="wns-product-default-behavior" name="product_default_behavior" class="wns-select" <?php disabled( ! $license_valid ); ?>>
                            <option value="include" <?php selected( $product_default_behavior, 'include' ); ?>><?php esc_html_e( 'Include all products', 'woo-nalda-sync' ); ?></option>
                            <option value="exclude" <?php selected( $product_default_behavior, 'exclude' ); ?>><?php esc_html_e( 'Exclude all products', 'woo-nalda-sync' ); ?></option>
                        </select>
                        <p class="wns-help-text">
                            <?php esc_html_e( 'Per-product settings override this default. Products without GTIN or price are always excluded.', 'woo-nalda-sync' ); ?>
                        </p>
                    </div>
                </div>

                <div class="wns-form-row">
                    <label class="wns-label">
                        <?php esc_html_e( 'Enable Automatic Export', 'woo-nalda-sync' ); ?>
                    </label>
                    <div class="wns-toggle-row">
                        <label class="wns-switch">
                            <input type="checkbox" id="wns-product-export-enabled" name="product_export_enabled" value="1" <?php checked( $product_export_enabled ); ?> <?php disabled( ! $license_valid ); ?>>
                            <span class="wns-slider"></span>
                        </label>
                        <span class="wns-toggle-label">
                            <?php esc_html_e( 'Enable scheduled product export to Nalda', 'woo-nalda-sync' ); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Order Import Configuration -->
        <div class="wns-section wns-card">
            <div class="wns-card-header">
                <h2>
                    <span class="dashicons dashicons-cart"></span>
                    <?php esc_html_e( 'Order Import', 'woo-nalda-sync' ); ?>
                </h2>
            </div>
            <div class="wns-card-body">
                <div class="wns-form-row wns-form-row-2col">
                    <div class="wns-form-col">
                        <label for="wns-order-import-interval" class="wns-label">
                            <?php esc_html_e( 'Import Interval', 'woo-nalda-sync' ); ?>
                        </label>
                        <select id="wns-order-import-interval" name="order_import_interval" class="wns-select" <?php disabled( ! $license_valid ); ?>>
                            <?php foreach ( $intervals as $key => $interval ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $order_import_interval, $key ); ?>>
                                    <?php echo esc_html( $interval['display'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="wns-form-col">
                        <label for="wns-order-import-range" class="wns-label">
                            <?php esc_html_e( 'Import Range (Days)', 'woo-nalda-sync' ); ?>
                        </label>
                        <input type="number" 
                               id="wns-order-import-range" 
                               name="order_import_range" 
                               value="<?php echo esc_attr( $order_import_range ); ?>" 
                               class="wns-input"
                               min="1"
                               max="30"
                               <?php disabled( ! $license_valid ); ?>>
                        <p class="wns-help-text">
                            <?php esc_html_e( 'Number of days back to look for new orders.', 'woo-nalda-sync' ); ?>
                        </p>
                    </div>
                </div>

                <div class="wns-form-row">
                    <label class="wns-label">
                        <?php esc_html_e( 'Enable Automatic Import', 'woo-nalda-sync' ); ?>
                    </label>
                    <div class="wns-toggle-row">
                        <label class="wns-switch">
                            <input type="checkbox" id="wns-order-import-enabled" name="order_import_enabled" value="1" <?php checked( $order_import_enabled ); ?> <?php disabled( ! $license_valid ); ?>>
                            <span class="wns-slider"></span>
                        </label>
                        <span class="wns-toggle-label">
                            <?php esc_html_e( 'Enable scheduled order import from Nalda', 'woo-nalda-sync' ); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Order Status Export Configuration -->
        <div class="wns-section wns-card">
            <div class="wns-card-header">
                <h2>
                    <span class="dashicons dashicons-yes-alt"></span>
                    <?php esc_html_e( 'Order Status Export', 'woo-nalda-sync' ); ?>
                </h2>
            </div>
            <div class="wns-card-body">
                <div class="wns-form-row">
                    <label for="wns-order-status-export-interval" class="wns-label">
                        <?php esc_html_e( 'Export Interval', 'woo-nalda-sync' ); ?>
                    </label>
                    <select id="wns-order-status-export-interval" name="order_status_export_interval" class="wns-select" <?php disabled( ! $license_valid ); ?>>
                        <?php foreach ( $intervals as $key => $interval ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $order_status_export_interval, $key ); ?>>
                                <?php echo esc_html( $interval['display'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="wns-form-row">
                    <label class="wns-label">
                        <?php esc_html_e( 'Enable Automatic Export', 'woo-nalda-sync' ); ?>
                    </label>
                    <div class="wns-toggle-row">
                        <label class="wns-switch">
                            <input type="checkbox" id="wns-order-status-export-enabled" name="order_status_export_enabled" value="1" <?php checked( $order_status_export_enabled ); ?> <?php disabled( ! $license_valid ); ?>>
                            <span class="wns-slider"></span>
                        </label>
                        <span class="wns-toggle-label">
                            <?php esc_html_e( 'Enable scheduled order status export to Nalda', 'woo-nalda-sync' ); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Product Defaults -->
        <div class="wns-section wns-card">
            <div class="wns-card-header">
                <h2>
                    <span class="dashicons dashicons-admin-settings"></span>
                    <?php esc_html_e( 'Product Defaults', 'woo-nalda-sync' ); ?>
                </h2>
            </div>
            <div class="wns-card-body">
                <div class="wns-form-row wns-form-row-2col">
                    <div class="wns-form-col">
                        <label class="wns-label">
                            <?php esc_html_e( 'Store Country', 'woo-nalda-sync' ); ?>
                        </label>
                        <input type="text" 
                               value="<?php echo esc_attr( $default_country ); ?>" 
                               class="wns-input"
                               readonly
                               disabled>
                        <p class="wns-help-text">
                            <?php
                            printf(
                                /* translators: %s: link to WooCommerce settings */
                                esc_html__( 'From WooCommerce settings. %s', 'woo-nalda-sync' ),
                                '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=general' ) ) . '">' . esc_html__( 'Change in WooCommerce', 'woo-nalda-sync' ) . '</a>'
                            );
                            ?>
                        </p>
                    </div>
                    <div class="wns-form-col">
                        <label class="wns-label">
                            <?php esc_html_e( 'Store Currency', 'woo-nalda-sync' ); ?>
                        </label>
                        <input type="text" 
                               value="<?php echo esc_attr( $default_currency ); ?>" 
                               class="wns-input"
                               readonly
                               disabled>
                        <p class="wns-help-text">
                            <?php
                            printf(
                                /* translators: %s: link to WooCommerce settings */
                                esc_html__( 'From WooCommerce settings. %s', 'woo-nalda-sync' ),
                                '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=general' ) ) . '">' . esc_html__( 'Change in WooCommerce', 'woo-nalda-sync' ) . '</a>'
                            );
                            ?>
                        </p>
                    </div>
                </div>

                <div class="wns-form-row wns-form-row-2col">
                    <div class="wns-form-col">
                        <label for="wns-default-delivery-days" class="wns-label">
                            <?php esc_html_e( 'Default Delivery Days', 'woo-nalda-sync' ); ?>
                        </label>
                        <input type="number" 
                               id="wns-default-delivery-days" 
                               name="default_delivery_days" 
                               value="<?php echo esc_attr( $default_delivery_days ); ?>" 
                               class="wns-input"
                               min="1"
                               <?php disabled( ! $license_valid ); ?>>
                    </div>
                    <div class="wns-form-col">
                        <label for="wns-default-return-days" class="wns-label">
                            <?php esc_html_e( 'Default Return Days', 'woo-nalda-sync' ); ?>
                        </label>
                        <input type="number" 
                               id="wns-default-return-days" 
                               name="default_return_days" 
                               value="<?php echo esc_attr( $default_return_days ); ?>" 
                               class="wns-input"
                               min="0"
                               <?php disabled( ! $license_valid ); ?>>
                    </div>
                </div>
            </div>
        </div>

        <!-- Info Box -->
        <div class="wns-section wns-card wns-card-info">
            <div class="wns-card-body">
                <div class="wns-info-grid">
                    <div class="wns-info-item">
                        <span class="dashicons dashicons-shield"></span>
                        <div>
                            <strong><?php esc_html_e( 'Watchdog Protection', 'woo-nalda-sync' ); ?></strong>
                            <p><?php esc_html_e( 'A watchdog cron runs every hour to ensure all sync schedules are working properly.', 'woo-nalda-sync' ); ?></p>
                        </div>
                    </div>
                    <div class="wns-info-item">
                        <span class="dashicons dashicons-performance"></span>
                        <div>
                            <strong><?php esc_html_e( 'Commission Handling', 'woo-nalda-sync' ); ?></strong>
                            <p><?php esc_html_e( 'Order amounts are automatically adjusted to reflect Nalda commission deductions.', 'woo-nalda-sync' ); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Save Button -->
        <div class="wns-form-actions">
            <button type="submit" class="wns-btn wns-btn-primary wns-btn-lg" <?php disabled( ! $license_valid ); ?>>
                <span class="dashicons dashicons-saved"></span>
                <?php esc_html_e( 'Save Settings', 'woo-nalda-sync' ); ?>
            </button>
        </div>
    </form>
</div>
