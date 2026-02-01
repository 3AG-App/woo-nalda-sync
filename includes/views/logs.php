<?php
/**
 * Logs View
 *
 * @package Woo_Nalda_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Get filters
$type_filter   = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : '';
$status_filter = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
$paged         = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
$per_page      = 20;

// Build query args
$args = array(
    'per_page' => $per_page,
    'page'     => $paged,
);

if ( $type_filter ) {
    $args['type'] = $type_filter;
}

if ( $status_filter ) {
    $args['status'] = $status_filter;
}

// Get logs
$logs       = WNS()->logs->get_logs( $args );
$total_logs = WNS()->logs->get_total( $args );
$total_pages = ceil( $total_logs / $per_page );
?>

<div class="wns-wrap">
    <div class="wns-header">
        <div class="wns-header-left">
            <h1><?php esc_html_e( 'Sync Logs', 'woo-nalda-sync' ); ?></h1>
            <p class="wns-subtitle"><?php esc_html_e( 'View history of all sync operations', 'woo-nalda-sync' ); ?></p>
        </div>
        <div class="wns-header-right">
            <button type="button" id="wns-clear-logs" class="wns-btn wns-btn-danger">
                <span class="dashicons dashicons-trash"></span>
                <?php esc_html_e( 'Clear Logs', 'woo-nalda-sync' ); ?>
            </button>
        </div>
    </div>

    <!-- Filters -->
    <div class="wns-section">
        <div class="wns-filters">
            <form method="get" action="">
                <input type="hidden" name="page" value="wns-logs">
                
                <div class="wns-filter-group">
                    <label><?php esc_html_e( 'Type', 'woo-nalda-sync' ); ?></label>
                    <select name="type" class="wns-select">
                        <option value=""><?php esc_html_e( 'All Types', 'woo-nalda-sync' ); ?></option>
                        <option value="product_export" <?php selected( $type_filter, 'product_export' ); ?>><?php esc_html_e( 'Product Export', 'woo-nalda-sync' ); ?></option>
                        <option value="order_import" <?php selected( $type_filter, 'order_import' ); ?>><?php esc_html_e( 'Order Import', 'woo-nalda-sync' ); ?></option>
                        <option value="order_status_export" <?php selected( $type_filter, 'order_status_export' ); ?>><?php esc_html_e( 'Order Status Export', 'woo-nalda-sync' ); ?></option>
                        <option value="watchdog" <?php selected( $type_filter, 'watchdog' ); ?>><?php esc_html_e( 'Watchdog', 'woo-nalda-sync' ); ?></option>
                        <option value="license" <?php selected( $type_filter, 'license' ); ?>><?php esc_html_e( 'License', 'woo-nalda-sync' ); ?></option>
                    </select>
                </div>
                
                <div class="wns-filter-group">
                    <label><?php esc_html_e( 'Status', 'woo-nalda-sync' ); ?></label>
                    <select name="status" class="wns-select">
                        <option value=""><?php esc_html_e( 'All Statuses', 'woo-nalda-sync' ); ?></option>
                        <option value="success" <?php selected( $status_filter, 'success' ); ?>><?php esc_html_e( 'Success', 'woo-nalda-sync' ); ?></option>
                        <option value="error" <?php selected( $status_filter, 'error' ); ?>><?php esc_html_e( 'Error', 'woo-nalda-sync' ); ?></option>
                        <option value="warning" <?php selected( $status_filter, 'warning' ); ?>><?php esc_html_e( 'Warning', 'woo-nalda-sync' ); ?></option>
                    </select>
                </div>
                
                <button type="submit" class="wns-btn wns-btn-secondary">
                    <span class="dashicons dashicons-filter"></span>
                    <?php esc_html_e( 'Filter', 'woo-nalda-sync' ); ?>
                </button>
                
                <?php if ( $type_filter || $status_filter ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=wns-logs' ) ); ?>" class="wns-btn wns-btn-ghost">
                        <?php esc_html_e( 'Reset', 'woo-nalda-sync' ); ?>
                    </a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Logs Table -->
    <div class="wns-section">
        <?php if ( empty( $logs ) ) : ?>
            <div class="wns-empty-state">
                <span class="dashicons dashicons-format-aside"></span>
                <p><?php esc_html_e( 'No logs found.', 'woo-nalda-sync' ); ?></p>
            </div>
        <?php else : ?>
            <div class="wns-table-wrap">
                <table class="wns-table">
                    <thead>
                        <tr>
                            <th class="wns-col-status"><?php esc_html_e( 'Status', 'woo-nalda-sync' ); ?></th>
                            <th class="wns-col-type"><?php esc_html_e( 'Type', 'woo-nalda-sync' ); ?></th>
                            <th class="wns-col-trigger"><?php esc_html_e( 'Trigger', 'woo-nalda-sync' ); ?></th>
                            <th class="wns-col-message"><?php esc_html_e( 'Message', 'woo-nalda-sync' ); ?></th>
                            <th class="wns-col-stats"><?php esc_html_e( 'Stats', 'woo-nalda-sync' ); ?></th>
                            <th class="wns-col-duration"><?php esc_html_e( 'Duration', 'woo-nalda-sync' ); ?></th>
                            <th class="wns-col-date"><?php esc_html_e( 'Date', 'woo-nalda-sync' ); ?></th>
                            <th class="wns-col-actions"><?php esc_html_e( 'Actions', 'woo-nalda-sync' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $logs as $log ) : 
                            $stats = ! empty( $log->stats ) ? json_decode( $log->stats, true ) : array();
                        ?>
                            <tr class="wns-log-row wns-log-<?php echo esc_attr( $log->status ); ?>">
                                <td class="wns-col-status">
                                    <span class="wns-status-badge wns-status-<?php echo esc_attr( $log->status ); ?>">
                                        <?php 
                                        switch ( $log->status ) {
                                            case 'success':
                                                echo '<span class="dashicons dashicons-yes-alt"></span>';
                                                break;
                                            case 'error':
                                                echo '<span class="dashicons dashicons-dismiss"></span>';
                                                break;
                                            case 'warning':
                                                echo '<span class="dashicons dashicons-warning"></span>';
                                                break;
                                        }
                                        ?>
                                    </span>
                                </td>
                                <td class="wns-col-type">
                                    <span class="wns-type-badge wns-type-<?php echo esc_attr( $log->type ); ?>">
                                        <?php echo esc_html( ucwords( str_replace( '_', ' ', $log->type ) ) ); ?>
                                    </span>
                                </td>
                                <td class="wns-col-trigger">
                                    <?php if ( $log->trigger_type ) : ?>
                                        <span class="wns-trigger-badge wns-trigger-<?php echo esc_attr( $log->trigger_type ); ?>">
                                            <?php 
                                            echo $log->trigger_type === 'scheduled' 
                                                ? '<span class="dashicons dashicons-clock"></span>' 
                                                : '<span class="dashicons dashicons-admin-users"></span>'; 
                                            ?>
                                            <?php echo esc_html( ucfirst( $log->trigger_type ) ); ?>
                                        </span>
                                    <?php else : ?>
                                        <span class="wns-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="wns-col-message">
                                    <span class="wns-message-text"><?php echo esc_html( $log->message ); ?></span>
                                </td>
                                <td class="wns-col-stats">
                                    <?php if ( ! empty( $stats ) && is_array( $stats ) ) : ?>
                                        <div class="wns-mini-stats">
                                            <?php if ( isset( $stats['exported'] ) ) : ?>
                                                <span class="wns-mini-stat wns-mini-stat-success" title="<?php esc_attr_e( 'Exported', 'woo-nalda-sync' ); ?>">
                                                    <span class="dashicons dashicons-upload"></span>
                                                    <?php echo esc_html( $stats['exported'] ); ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ( isset( $stats['imported'] ) ) : ?>
                                                <span class="wns-mini-stat wns-mini-stat-success" title="<?php esc_attr_e( 'Imported', 'woo-nalda-sync' ); ?>">
                                                    <span class="dashicons dashicons-download"></span>
                                                    <?php echo esc_html( $stats['imported'] ); ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ( isset( $stats['skipped'] ) && $stats['skipped'] > 0 ) : ?>
                                                <span class="wns-mini-stat wns-mini-stat-warning" title="<?php esc_attr_e( 'Skipped', 'woo-nalda-sync' ); ?>">
                                                    <span class="dashicons dashicons-minus"></span>
                                                    <?php echo esc_html( $stats['skipped'] ); ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ( isset( $stats['errors'] ) && $stats['errors'] > 0 ) : ?>
                                                <span class="wns-mini-stat wns-mini-stat-error" title="<?php esc_attr_e( 'Errors', 'woo-nalda-sync' ); ?>">
                                                    <span class="dashicons dashicons-dismiss"></span>
                                                    <?php echo esc_html( $stats['errors'] ); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else : ?>
                                        <span class="wns-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="wns-col-duration">
                                    <?php if ( $log->duration > 0 ) : ?>
                                        <?php echo esc_html( number_format( $log->duration, 2 ) ); ?>s
                                    <?php else : ?>
                                        <span class="wns-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="wns-col-date">
                                    <span class="wns-date wns-local-time" data-timestamp="<?php echo esc_attr( strtotime( $log->created_at . ' UTC' ) ); ?>" title="<?php echo esc_attr( get_date_from_gmt( $log->created_at, 'Y-m-d H:i:s' ) ); ?>">
                                        <?php echo esc_html( human_time_diff( strtotime( $log->created_at . ' UTC' ), time() ) ); ?> <?php esc_html_e( 'ago', 'woo-nalda-sync' ); ?>
                                    </span>
                                </td>
                                <td class="wns-col-actions">
                                    <button type="button" class="wns-btn wns-btn-icon wns-view-log" data-log-id="<?php echo esc_attr( $log->id ); ?>" title="<?php esc_attr_e( 'View Details', 'woo-nalda-sync' ); ?>">
                                        <span class="dashicons dashicons-visibility"></span>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ( $total_pages > 1 ) : ?>
                <div class="wns-pagination">
                    <span class="wns-pagination-info">
                        <?php 
                        printf(
                            esc_html__( 'Showing %1$d-%2$d of %3$d logs', 'woo-nalda-sync' ),
                            ( ( $paged - 1 ) * $per_page ) + 1,
                            min( $paged * $per_page, $total_logs ),
                            $total_logs
                        );
                        ?>
                    </span>
                    <div class="wns-pagination-links">
                        <?php if ( $paged > 1 ) : ?>
                            <a href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1 ) ); ?>" class="wns-btn wns-btn-secondary wns-btn-sm">
                                <?php esc_html_e( '← Previous', 'woo-nalda-sync' ); ?>
                            </a>
                        <?php endif; ?>
                        
                        <span class="wns-page-num">
                            <?php printf( esc_html__( 'Page %1$d of %2$d', 'woo-nalda-sync' ), $paged, $total_pages ); ?>
                        </span>
                        
                        <?php if ( $paged < $total_pages ) : ?>
                            <a href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1 ) ); ?>" class="wns-btn wns-btn-secondary wns-btn-sm">
                                <?php esc_html_e( 'Next →', 'woo-nalda-sync' ); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
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
