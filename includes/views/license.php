<?php
/**
 * License View
 *
 * @package Woo_Nalda_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$license_key    = get_option( 'wns_license_key', '' );
$license_status = get_option( 'wns_license_status', '' );
$license_data   = WNS()->license->get_license_data();
$last_check     = get_option( 'wns_license_last_check', 0 );

$is_active    = $license_status === 'active';
$expires_at   = isset( $license_data['expires_at'] ) ? $license_data['expires_at'] : null;
$activations  = isset( $license_data['activations'] ) ? $license_data['activations'] : null;
$product_name = isset( $license_data['product'] ) ? $license_data['product'] : '';
$package      = isset( $license_data['package'] ) ? $license_data['package'] : '';
?>

<div class="wns-wrap">
    <div class="wns-header">
        <div class="wns-header-left">
            <h1><?php esc_html_e( 'License', 'woo-nalda-sync' ); ?></h1>
            <p class="wns-subtitle"><?php esc_html_e( 'Manage your plugin license activation', 'woo-nalda-sync' ); ?></p>
        </div>
    </div>

    <div class="wns-license-container">
        <!-- License Status Card -->
        <div class="wns-section wns-card wns-license-card <?php echo $is_active ? 'wns-license-active' : 'wns-license-inactive'; ?>">
            <div class="wns-card-body">
                <div class="wns-license-status-display">
                    <div class="wns-license-icon">
                        <?php if ( $is_active ) : ?>
                            <span class="dashicons dashicons-yes-alt"></span>
                        <?php else : ?>
                            <span class="dashicons dashicons-lock"></span>
                        <?php endif; ?>
                    </div>
                    <div class="wns-license-info">
                        <h2>
                            <?php if ( $is_active ) : ?>
                                <?php esc_html_e( 'License Active', 'woo-nalda-sync' ); ?>
                            <?php else : ?>
                                <?php esc_html_e( 'License Not Active', 'woo-nalda-sync' ); ?>
                            <?php endif; ?>
                        </h2>
                        <?php if ( $is_active && $product_name ) : ?>
                            <p class="wns-license-product">
                                <?php echo esc_html( $product_name ); ?>
                                <?php if ( $package ) : ?>
                                    <span class="wns-license-package"><?php echo esc_html( $package ); ?></span>
                                <?php endif; ?>
                            </p>
                        <?php elseif ( ! $is_active ) : ?>
                            <p><?php esc_html_e( 'Enter your license key to activate premium features.', 'woo-nalda-sync' ); ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ( $is_active ) : ?>
                    <div class="wns-license-details">
                        <div class="wns-license-detail-grid">
                            <!-- Expiration -->
                            <div class="wns-license-detail-item">
                                <span class="wns-detail-label"><?php esc_html_e( 'Expires', 'woo-nalda-sync' ); ?></span>
                                <span class="wns-detail-value">
                                    <?php if ( $expires_at ) : ?>
                                        <?php 
                                        $expiry_date = strtotime( $expires_at );
                                        $remaining   = WNS()->license->get_remaining_days();
                                        echo esc_html( wp_date( 'F j, Y', $expiry_date ) );
                                        if ( $remaining !== null ) {
                                            if ( $remaining > 0 ) {
                                                echo ' <span class="wns-days-remaining">(' . sprintf( esc_html__( '%d days left', 'woo-nalda-sync' ), $remaining ) . ')</span>';
                                            } else {
                                                echo ' <span class="wns-expired">(' . esc_html__( 'Expired', 'woo-nalda-sync' ) . ')</span>';
                                            }
                                        }
                                        ?>
                                    <?php else : ?>
                                        <span class="wns-lifetime"><?php esc_html_e( 'Lifetime', 'woo-nalda-sync' ); ?></span>
                                    <?php endif; ?>
                                </span>
                            </div>

                            <!-- Activations -->
                            <?php if ( $activations ) : ?>
                                <div class="wns-license-detail-item">
                                    <span class="wns-detail-label"><?php esc_html_e( 'Activations', 'woo-nalda-sync' ); ?></span>
                                    <span class="wns-detail-value">
                                        <?php 
                                        printf(
                                            esc_html__( '%1$d of %2$d used', 'woo-nalda-sync' ),
                                            intval( $activations['used'] ),
                                            intval( $activations['limit'] )
                                        ); 
                                        ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <!-- Last Verified -->
                            <?php if ( $last_check ) : ?>
                                <div class="wns-license-detail-item">
                                    <span class="wns-detail-label"><?php esc_html_e( 'Last Verified', 'woo-nalda-sync' ); ?></span>
                                    <span class="wns-detail-value wns-local-time" data-timestamp="<?php echo esc_attr( $last_check ); ?>">
                                        <?php echo esc_html( human_time_diff( $last_check, time() ) ); ?> <?php esc_html_e( 'ago', 'woo-nalda-sync' ); ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- License Form Card -->
        <div class="wns-section wns-card">
            <div class="wns-card-header">
                <h2>
                    <span class="dashicons dashicons-admin-network"></span>
                    <?php echo $is_active ? esc_html__( 'License Management', 'woo-nalda-sync' ) : esc_html__( 'Activate License', 'woo-nalda-sync' ); ?>
                </h2>
            </div>
            <div class="wns-card-body">
                <?php if ( $is_active ) : ?>
                    <!-- Active License Actions -->
                    <div class="wns-license-key-display">
                        <label class="wns-label"><?php esc_html_e( 'Current License Key', 'woo-nalda-sync' ); ?></label>
                        <div class="wns-license-key-masked">
                            <span class="wns-key-value">
                                <?php 
                                $key_length = strlen( $license_key );
                                if ( $key_length > 8 ) {
                                    $masked_key = substr( $license_key, 0, 4 ) . str_repeat( '•', $key_length - 8 ) . substr( $license_key, -4 );
                                } elseif ( $key_length > 4 ) {
                                    $masked_key = substr( $license_key, 0, 2 ) . str_repeat( '•', $key_length - 2 );
                                } else {
                                    $masked_key = str_repeat( '•', $key_length );
                                }
                                echo esc_html( $masked_key );
                                ?>
                            </span>
                        </div>
                    </div>

                    <div class="wns-license-actions">
                        <button type="button" id="wns-check-license" class="wns-btn wns-btn-secondary">
                            <span class="dashicons dashicons-update"></span>
                            <?php esc_html_e( 'Verify License', 'woo-nalda-sync' ); ?>
                        </button>
                        <button type="button" id="wns-deactivate-license" class="wns-btn wns-btn-danger">
                            <span class="dashicons dashicons-dismiss"></span>
                            <?php esc_html_e( 'Deactivate License', 'woo-nalda-sync' ); ?>
                        </button>
                    </div>
                <?php else : ?>
                    <!-- License Activation Form -->
                    <form id="wns-license-form" class="wns-form">
                        <div class="wns-form-row">
                            <label for="wns-license-key" class="wns-label">
                                <?php esc_html_e( 'License Key', 'woo-nalda-sync' ); ?>
                                <span class="wns-required">*</span>
                            </label>
                            <div class="wns-input-group">
                                <input type="text" 
                                       id="wns-license-key" 
                                       name="license_key" 
                                       value="" 
                                       class="wns-input wns-input-lg wns-input-mono"
                                       placeholder="XXXX-XXXX-XXXX-XXXX"
                                       autocomplete="off">
                                <button type="submit" class="wns-btn wns-btn-primary">
                                    <span class="dashicons dashicons-yes"></span>
                                    <?php esc_html_e( 'Activate', 'woo-nalda-sync' ); ?>
                                </button>
                            </div>
                            <p class="wns-help-text">
                                <?php esc_html_e( 'Enter the license key you received after purchase.', 'woo-nalda-sync' ); ?>
                            </p>
                        </div>
                    </form>
                <?php endif; ?>

                <div class="wns-license-help">
                    <p>
                        <span class="dashicons dashicons-info-outline"></span>
                        <?php 
                        if ( $is_active ) {
                            printf(
                                esc_html__( 'Need more licenses? %s', 'woo-nalda-sync' ),
                                '<a href="https://3ag.app/products/woo-nalda-sync" target="_blank">' . esc_html__( 'Purchase one here', 'woo-nalda-sync' ) . '</a>'
                            );
                        } else {
                            printf(
                                esc_html__( 'Don\'t have a license? %s', 'woo-nalda-sync' ),
                                '<a href="https://3ag.app/products/woo-nalda-sync" target="_blank">' . esc_html__( 'Purchase one here', 'woo-nalda-sync' ) . '</a>'
                            );
                        }
                        ?>
                    </p>
                    <p>
                        <span class="dashicons dashicons-admin-users"></span>
                        <?php 
                        printf(
                            esc_html__( 'Manage your licenses and domain activations: %s', 'woo-nalda-sync' ),
                            '<a href="https://3ag.app/dashboard/licenses" target="_blank">' . esc_html__( 'License Dashboard', 'woo-nalda-sync' ) . '</a>'
                        ); 
                        ?>
                    </p>
                    <p>
                        <span class="dashicons dashicons-email"></span>
                        <?php 
                        printf(
                            esc_html__( 'Need help? Contact support: %s', 'woo-nalda-sync' ),
                            '<a href="mailto:info@3ag.app">info@3ag.app</a>'
                        ); 
                        ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Plugin Updates Card -->
        <?php 
        $update_data     = get_transient( 'wns_update_data' );
        $current_version = WNS_VERSION;
        $has_update      = $update_data && ! empty( $update_data['version'] ) && version_compare( $current_version, $update_data['version'], '<' );
        ?>
        <div class="wns-section wns-card">
            <div class="wns-card-header">
                <h2>
                    <span class="dashicons dashicons-update"></span>
                    <?php esc_html_e( 'Plugin Updates', 'woo-nalda-sync' ); ?>
                </h2>
            </div>
            <div class="wns-card-body">
                <div class="wns-update-status">
                    <div class="wns-version-info">
                        <div class="wns-version-row">
                            <span class="wns-version-label"><?php esc_html_e( 'Installed Version:', 'woo-nalda-sync' ); ?></span>
                            <span class="wns-version-value"><?php echo esc_html( $current_version ); ?></span>
                        </div>
                        <?php if ( $update_data && ! empty( $update_data['version'] ) ) : ?>
                        <div class="wns-version-row">
                            <span class="wns-version-label"><?php esc_html_e( 'Latest Version:', 'woo-nalda-sync' ); ?></span>
                            <span class="wns-version-value <?php echo $has_update ? 'wns-version-new' : ''; ?>">
                                <?php echo esc_html( $update_data['version'] ); ?>
                                <?php if ( $has_update ) : ?>
                                    <span class="wns-update-badge"><?php esc_html_e( 'Update Available', 'woo-nalda-sync' ); ?></span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php if ( ! empty( $update_data['checked'] ) ) : ?>
                        <div class="wns-version-row">
                            <span class="wns-version-label"><?php esc_html_e( 'Last Checked:', 'woo-nalda-sync' ); ?></span>
                            <span class="wns-version-value wns-muted wns-local-time" data-timestamp="<?php echo esc_attr( $update_data['checked'] ); ?>">
                                <?php echo esc_html( human_time_diff( $update_data['checked'], time() ) ); ?> <?php esc_html_e( 'ago', 'woo-nalda-sync' ); ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    
                    <div class="wns-update-actions">
                        <button type="button" id="wns-check-update" class="wns-btn wns-btn-secondary">
                            <span class="dashicons dashicons-update"></span>
                            <?php esc_html_e( 'Check for Updates', 'woo-nalda-sync' ); ?>
                        </button>
                        <?php if ( $has_update ) : ?>
                        <button type="button" id="wns-install-update" class="wns-btn wns-btn-primary" data-version="<?php echo esc_attr( $update_data['version'] ); ?>">
                            <span class="dashicons dashicons-download"></span>
                            <?php printf( esc_html__( 'Update to %s', 'woo-nalda-sync' ), esc_html( $update_data['version'] ) ); ?>
                        </button>
                        <?php endif; ?>
                    </div>
                    
                    <p class="wns-help-text wns-muted" style="margin-top: 15px;">
                        <span class="dashicons dashicons-external"></span>
                        <?php 
                        printf(
                            esc_html__( 'Updates are fetched from %s', 'woo-nalda-sync' ),
                            '<a href="https://github.com/3AG-App/woo-nalda-sync/releases" target="_blank">GitHub Releases</a>'
                        ); 
                        ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Features Info -->
        <div class="wns-section wns-card wns-card-info">
            <div class="wns-card-header">
                <h2>
                    <span class="dashicons dashicons-star-filled"></span>
                    <?php esc_html_e( 'Premium Features', 'woo-nalda-sync' ); ?>
                </h2>
            </div>
            <div class="wns-card-body">
                <ul class="wns-features-list">
                    <li>
                        <span class="dashicons dashicons-yes"></span>
                        <?php esc_html_e( 'Automatic product export to Nalda marketplace', 'woo-nalda-sync' ); ?>
                    </li>
                    <li>
                        <span class="dashicons dashicons-yes"></span>
                        <?php esc_html_e( 'Order import with commission handling', 'woo-nalda-sync' ); ?>
                    </li>
                    <li>
                        <span class="dashicons dashicons-yes"></span>
                        <?php esc_html_e( 'Automatic order status synchronization', 'woo-nalda-sync' ); ?>
                    </li>
                    <li>
                        <span class="dashicons dashicons-yes"></span>
                        <?php esc_html_e( 'Detailed sync logs and history', 'woo-nalda-sync' ); ?>
                    </li>
                    <li>
                        <span class="dashicons dashicons-yes"></span>
                        <?php esc_html_e( 'Watchdog cron for reliability', 'woo-nalda-sync' ); ?>
                    </li>
                    <li>
                        <span class="dashicons dashicons-yes"></span>
                        <?php esc_html_e( 'Priority email support', 'woo-nalda-sync' ); ?>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>
