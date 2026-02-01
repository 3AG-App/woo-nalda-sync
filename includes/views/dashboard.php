<?php
/**
 * Dashboard View
 *
 * @package Woo_Nalda_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$license_valid = WNS()->license->is_valid();

// Get sync status and stats
$product_export_enabled      = get_option( 'wns_product_export_enabled', false );
$order_import_enabled        = get_option( 'wns_order_import_enabled', false );
$order_status_export_enabled = get_option( 'wns_order_status_export_enabled', false );

$product_export_interval      = get_option( 'wns_product_export_interval', 'daily' );
$order_import_interval        = get_option( 'wns_order_import_interval', 'hourly' );
$order_status_export_interval = get_option( 'wns_order_status_export_interval', 'hourly' );

$intervals = WNS()->scheduler->get_intervals();

$last_product_export = get_option( 'wns_last_product_export_time', 0 );
$last_order_import   = get_option( 'wns_last_order_import_time', 0 );
$last_status_export  = get_option( 'wns_last_order_status_export_time', 0 );

$product_export_stats = get_option( 'wns_last_product_export_stats', array() );
$order_import_stats   = get_option( 'wns_last_order_import_stats', array() );
$status_export_stats  = get_option( 'wns_last_order_status_export_stats', array() );

// Get recent logs
$recent_logs = WNS()->logs->get_logs( array( 'per_page' => 5 ) );

// Get 30 day stats
$stats = WNS()->logs->get_stats( 30 );
?>

<div class="wns-wrap">
    <div class="wns-header">
        <div class="wns-header-left">
            <h1><?php esc_html_e( 'Nalda Sync Dashboard', 'woo-nalda-sync' ); ?></h1>
            <p class="wns-subtitle"><?php esc_html_e( 'Manage your WooCommerce to Nalda marketplace synchronization', 'woo-nalda-sync' ); ?></p>
        </div>
        <div class="wns-header-right">
            <?php if ( $license_valid ) : ?>
                <span class="wns-license-badge wns-license-active">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <?php esc_html_e( 'License Active', 'woo-nalda-sync' ); ?>
                </span>
            <?php else : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wns-license' ) ); ?>" class="wns-license-badge wns-license-inactive">
                    <span class="dashicons dashicons-warning"></span>
                    <?php esc_html_e( 'License Required', 'woo-nalda-sync' ); ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ( ! $license_valid ) : ?>
        <div class="wns-notice wns-notice-warning">
            <span class="dashicons dashicons-lock"></span>
            <div>
                <strong><?php esc_html_e( 'License Required', 'woo-nalda-sync' ); ?></strong>
                <p><?php esc_html_e( 'Please activate your license to enable Nalda synchronization features.', 'woo-nalda-sync' ); ?></p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wns-license' ) ); ?>" class="wns-btn wns-btn-primary wns-btn-sm">
                    <?php esc_html_e( 'Activate License', 'woo-nalda-sync' ); ?>
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- Sync Cards -->
    <div class="wns-section">
        <h2 class="wns-section-title"><?php esc_html_e( 'Sync Operations', 'woo-nalda-sync' ); ?></h2>
        <div class="wns-sync-cards">
            <!-- Product Export Card -->
            <div class="wns-sync-card">
                <div class="wns-sync-card-header">
                    <div class="wns-sync-card-title">
                        <div class="wns-action-icon wns-icon-products">
                            <span class="dashicons dashicons-products"></span>
                        </div>
                        <h3><?php esc_html_e( 'Product Export', 'woo-nalda-sync' ); ?></h3>
                    </div>
                    <label class="wns-switch" <?php echo ! $license_valid ? 'title="' . esc_attr__( 'License required', 'woo-nalda-sync' ) . '"' : ''; ?>>
                        <input type="checkbox" class="wns-toggle-sync" data-sync-type="product_export" <?php checked( $product_export_enabled ); ?> <?php disabled( ! $license_valid ); ?>>
                        <span class="wns-slider"></span>
                    </label>
                </div>
                <div class="wns-sync-card-body">
                    <div class="wns-sync-stats">
                        <div class="wns-sync-stat">
                            <span class="wns-sync-stat-value"><?php echo esc_html( $product_export_stats['exported'] ?? '0' ); ?></span>
                            <span class="wns-sync-stat-label"><?php esc_html_e( 'Last Exported', 'woo-nalda-sync' ); ?></span>
                        </div>
                        <div class="wns-sync-stat">
                            <span class="wns-sync-stat-value">
                                <?php echo $last_product_export ? esc_html( human_time_diff( $last_product_export ) ) : '—'; ?>
                            </span>
                            <span class="wns-sync-stat-label"><?php esc_html_e( 'Last Run', 'woo-nalda-sync' ); ?></span>
                        </div>
                    </div>
                    <div class="wns-sync-schedule">
                        <span class="wns-sync-schedule-info">
                            <?php esc_html_e( 'Schedule:', 'woo-nalda-sync' ); ?>
                            <strong><?php echo esc_html( $intervals[ $product_export_interval ]['display'] ?? $product_export_interval ); ?></strong>
                        </span>
                    </div>
                    <div class="wns-sync-actions">
                        <button type="button" class="wns-btn wns-btn-primary wns-run-now" data-sync-type="product_export" <?php disabled( ! $license_valid ); ?>>
                            <span class="dashicons dashicons-update"></span>
                            <?php esc_html_e( 'Run Now', 'woo-nalda-sync' ); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Order Import Card -->
            <div class="wns-sync-card">
                <div class="wns-sync-card-header">
                    <div class="wns-sync-card-title">
                        <div class="wns-action-icon wns-icon-orders">
                            <span class="dashicons dashicons-cart"></span>
                        </div>
                        <h3><?php esc_html_e( 'Order Import', 'woo-nalda-sync' ); ?></h3>
                    </div>
                    <label class="wns-switch" <?php echo ! $license_valid ? 'title="' . esc_attr__( 'License required', 'woo-nalda-sync' ) . '"' : ''; ?>>
                        <input type="checkbox" class="wns-toggle-sync" data-sync-type="order_import" <?php checked( $order_import_enabled ); ?> <?php disabled( ! $license_valid ); ?>>
                        <span class="wns-slider"></span>
                    </label>
                </div>
                <div class="wns-sync-card-body">
                    <div class="wns-sync-stats">
                        <div class="wns-sync-stat">
                            <span class="wns-sync-stat-value"><?php echo esc_html( $order_import_stats['imported'] ?? '0' ); ?></span>
                            <span class="wns-sync-stat-label"><?php esc_html_e( 'Last Imported', 'woo-nalda-sync' ); ?></span>
                        </div>
                        <div class="wns-sync-stat">
                            <span class="wns-sync-stat-value">
                                <?php echo $last_order_import ? esc_html( human_time_diff( $last_order_import ) ) : '—'; ?>
                            </span>
                            <span class="wns-sync-stat-label"><?php esc_html_e( 'Last Run', 'woo-nalda-sync' ); ?></span>
                        </div>
                    </div>
                    <div class="wns-sync-schedule">
                        <span class="wns-sync-schedule-info">
                            <?php esc_html_e( 'Schedule:', 'woo-nalda-sync' ); ?>
                            <strong><?php echo esc_html( $intervals[ $order_import_interval ]['display'] ?? $order_import_interval ); ?></strong>
                        </span>
                    </div>
                    <div class="wns-sync-actions">
                        <button type="button" class="wns-btn wns-btn-primary wns-run-now" data-sync-type="order_import" <?php disabled( ! $license_valid ); ?>>
                            <span class="dashicons dashicons-download"></span>
                            <?php esc_html_e( 'Run Now', 'woo-nalda-sync' ); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Order Status Export Card -->
            <div class="wns-sync-card">
                <div class="wns-sync-card-header">
                    <div class="wns-sync-card-title">
                        <div class="wns-action-icon wns-status-active">
                            <span class="dashicons dashicons-yes-alt"></span>
                        </div>
                        <h3><?php esc_html_e( 'Status Export', 'woo-nalda-sync' ); ?></h3>
                    </div>
                    <label class="wns-switch" <?php echo ! $license_valid ? 'title="' . esc_attr__( 'License required', 'woo-nalda-sync' ) . '"' : ''; ?>>
                        <input type="checkbox" class="wns-toggle-sync" data-sync-type="order_status_export" <?php checked( $order_status_export_enabled ); ?> <?php disabled( ! $license_valid ); ?>>
                        <span class="wns-slider"></span>
                    </label>
                </div>
                <div class="wns-sync-card-body">
                    <div class="wns-sync-stats">
                        <div class="wns-sync-stat">
                            <span class="wns-sync-stat-value"><?php echo esc_html( $status_export_stats['exported'] ?? '0' ); ?></span>
                            <span class="wns-sync-stat-label"><?php esc_html_e( 'Last Exported', 'woo-nalda-sync' ); ?></span>
                        </div>
                        <div class="wns-sync-stat">
                            <span class="wns-sync-stat-value">
                                <?php echo $last_status_export ? esc_html( human_time_diff( $last_status_export ) ) : '—'; ?>
                            </span>
                            <span class="wns-sync-stat-label"><?php esc_html_e( 'Last Run', 'woo-nalda-sync' ); ?></span>
                        </div>
                    </div>
                    <div class="wns-sync-schedule">
                        <span class="wns-sync-schedule-info">
                            <?php esc_html_e( 'Schedule:', 'woo-nalda-sync' ); ?>
                            <strong><?php echo esc_html( $intervals[ $order_status_export_interval ]['display'] ?? $order_status_export_interval ); ?></strong>
                        </span>
                    </div>
                    <div class="wns-sync-actions">
                        <button type="button" class="wns-btn wns-btn-primary wns-run-now" data-sync-type="order_status_export" <?php disabled( ! $license_valid ); ?>>
                            <span class="dashicons dashicons-upload"></span>
                            <?php esc_html_e( 'Run Now', 'woo-nalda-sync' ); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="wns-section">
        <h2 class="wns-section-title"><?php esc_html_e( 'Last 30 Days Statistics', 'woo-nalda-sync' ); ?></h2>
        <div class="wns-stats-grid">
            <div class="wns-stat-card">
                <div class="wns-stat-icon wns-stat-icon-syncs">
                    <span class="dashicons dashicons-update"></span>
                </div>
                <div class="wns-stat-content">
                    <span class="wns-stat-value"><?php echo esc_html( $stats['total_syncs'] ?? 0 ); ?></span>
                    <span class="wns-stat-label"><?php esc_html_e( 'Total Syncs', 'woo-nalda-sync' ); ?></span>
                </div>
            </div>

            <div class="wns-stat-card">
                <div class="wns-stat-icon wns-stat-icon-success">
                    <span class="dashicons dashicons-yes-alt"></span>
                </div>
                <div class="wns-stat-content">
                    <span class="wns-stat-value"><?php echo esc_html( $stats['success_rate'] ?? 0 ); ?>%</span>
                    <span class="wns-stat-label"><?php esc_html_e( 'Success Rate', 'woo-nalda-sync' ); ?></span>
                </div>
            </div>

            <div class="wns-stat-card">
                <div class="wns-stat-icon wns-stat-icon-products">
                    <span class="dashicons dashicons-products"></span>
                </div>
                <div class="wns-stat-content">
                    <span class="wns-stat-value"><?php echo esc_html( number_format( $stats['total_exported'] ?? 0 ) ); ?></span>
                    <span class="wns-stat-label"><?php esc_html_e( 'Products Exported', 'woo-nalda-sync' ); ?></span>
                </div>
            </div>

            <div class="wns-stat-card">
                <div class="wns-stat-icon wns-stat-icon-orders">
                    <span class="dashicons dashicons-cart"></span>
                </div>
                <div class="wns-stat-content">
                    <span class="wns-stat-value"><?php echo esc_html( number_format( $stats['total_imported'] ?? 0 ) ); ?></span>
                    <span class="wns-stat-label"><?php esc_html_e( 'Orders Imported', 'woo-nalda-sync' ); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="wns-section">
        <div class="wns-section-header">
            <h2 class="wns-section-title"><?php esc_html_e( 'Recent Activity', 'woo-nalda-sync' ); ?></h2>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wns-logs' ) ); ?>" class="wns-link">
                <?php esc_html_e( 'View All', 'woo-nalda-sync' ); ?> →
            </a>
        </div>
        
        <?php if ( empty( $recent_logs ) ) : ?>
            <div class="wns-empty-state">
                <span class="dashicons dashicons-format-aside"></span>
                <p><?php esc_html_e( 'No sync activity yet.', 'woo-nalda-sync' ); ?></p>
            </div>
        <?php else : ?>
            <div class="wns-activity-list">
                <?php foreach ( $recent_logs as $log ) : ?>
                    <div class="wns-activity-item">
                        <div class="wns-activity-status wns-activity-status-<?php echo esc_attr( $log->status ); ?>">
                            <span class="dashicons dashicons-<?php echo $log->status === 'success' ? 'yes-alt' : ( $log->status === 'error' ? 'dismiss' : 'warning' ); ?>"></span>
                        </div>
                        <div class="wns-activity-content">
                            <p class="wns-activity-message"><?php echo esc_html( $log->message ); ?></p>
                            <div class="wns-activity-meta">
                                <span class="wns-type-badge wns-type-<?php echo esc_attr( $log->type ); ?>">
                                    <?php echo esc_html( ucwords( str_replace( '_', ' ', $log->type ) ) ); ?>
                                </span>
                                <span class="wns-activity-time wns-local-time" data-timestamp="<?php echo esc_attr( strtotime( $log->created_at . ' UTC' ) ); ?>">
                                    <?php echo esc_html( human_time_diff( strtotime( $log->created_at . ' UTC' ), time() ) ); ?> <?php esc_html_e( 'ago', 'woo-nalda-sync' ); ?>
                                </span>
                            </div>
                        </div>
                        <button type="button" class="wns-btn wns-btn-ghost wns-view-log" data-log-id="<?php echo esc_attr( $log->id ); ?>">
                            <span class="dashicons dashicons-visibility"></span>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Log Details Modal -->
<div id="wns-log-modal" class="wns-modal">
    <div class="wns-modal-content">
        <div class="wns-modal-header">
            <h3><?php esc_html_e( 'Log Details', 'woo-nalda-sync' ); ?></h3>
            <button type="button" class="wns-modal-close">&times;</button>
        </div>
        <div class="wns-modal-body" id="wns-log-modal-body">
            <!-- Content loaded via AJAX -->
        </div>
    </div>
</div>
