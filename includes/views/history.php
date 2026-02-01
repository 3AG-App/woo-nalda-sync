<?php
/**
 * Upload History View
 *
 * @package Woo_Nalda_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$license_valid = WNS()->license->is_valid();

// Get filters
$type_filter = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : '';
$paged       = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
$per_page    = 15;

// Fetch upload history from API
$uploads     = array();
$total_pages = 1;
$total_items = 0;
$api_error   = '';

if ( $license_valid ) {
    $license_key = get_option( 'wns_license_key', '' );
    $domain      = wns_get_domain();

    if ( ! empty( $license_key ) ) {
        // Build API URL
        $query_args = array(
            'license_key' => $license_key,
            'domain'      => $domain,
            'page'        => $paged,
            'per_page'    => $per_page,
        );

        // Add type filter if set (API will support this parameter)
        if ( $type_filter ) {
            $query_args['type'] = $type_filter;
        }

        $api_url = add_query_arg( $query_args, WNS_API_BASE_URL . '/nalda/csv-upload/list' );

        // Make API request
        $response = wp_remote_get(
            $api_url,
            array(
                'timeout' => 30,
                'headers' => array(
                    'Accept' => 'application/json',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            $api_error = $response->get_error_message();
        } else {
            $status_code = wp_remote_retrieve_response_code( $response );
            $body        = wp_remote_retrieve_body( $response );
            $data        = json_decode( $body, true );

            if ( $status_code === 200 ) {
                $uploads     = isset( $data['data'] ) ? $data['data'] : array();
                $total_items = isset( $data['meta']['total'] ) ? $data['meta']['total'] : count( $uploads );
                $total_pages = isset( $data['meta']['last_page'] ) ? $data['meta']['last_page'] : 1;
            } else {
                $api_error = isset( $data['message'] ) ? $data['message'] : __( 'Failed to fetch upload history.', 'woo-nalda-sync' );
            }
        }
    }
}
?>

<div class="wns-wrap">
    <div class="wns-header">
        <div class="wns-header-left">
            <h1><?php esc_html_e( 'CSV Upload History', 'woo-nalda-sync' ); ?></h1>
            <p class="wns-subtitle"><?php esc_html_e( 'View all CSV files uploaded to the Nalda SFTP server', 'woo-nalda-sync' ); ?></p>
        </div>
        <div class="wns-header-right">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wns-history' ) ); ?>" class="wns-btn wns-btn-secondary">
                <span class="dashicons dashicons-update"></span>
                <?php esc_html_e( 'Refresh', 'woo-nalda-sync' ); ?>
            </a>
        </div>
    </div>

    <?php if ( ! $license_valid ) : ?>
        <div class="wns-notice wns-notice-warning">
            <span class="dashicons dashicons-lock"></span>
            <div>
                <strong><?php esc_html_e( 'License Required', 'woo-nalda-sync' ); ?></strong>
                <p><?php esc_html_e( 'Please activate your license to view upload history.', 'woo-nalda-sync' ); ?></p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wns-license' ) ); ?>" class="wns-btn wns-btn-primary wns-btn-sm">
                    <?php esc_html_e( 'Activate License', 'woo-nalda-sync' ); ?>
                </a>
            </div>
        </div>
    <?php elseif ( $api_error ) : ?>
        <div class="wns-notice wns-notice-error">
            <span class="dashicons dashicons-warning"></span>
            <div>
                <strong><?php esc_html_e( 'Error', 'woo-nalda-sync' ); ?></strong>
                <p><?php echo esc_html( $api_error ); ?></p>
            </div>
        </div>
    <?php else : ?>
        <!-- Filters -->
        <div class="wns-section">
            <div class="wns-filters">
                <form method="get" action="">
                    <input type="hidden" name="page" value="wns-history">
                    
                    <div class="wns-filter-group">
                        <label><?php esc_html_e( 'Type', 'woo-nalda-sync' ); ?></label>
                        <select name="type" class="wns-select">
                            <option value=""><?php esc_html_e( 'All Types', 'woo-nalda-sync' ); ?></option>
                            <option value="products" <?php selected( $type_filter, 'products' ); ?>><?php esc_html_e( 'Products', 'woo-nalda-sync' ); ?></option>
                            <option value="orders" <?php selected( $type_filter, 'orders' ); ?>><?php esc_html_e( 'Order Status', 'woo-nalda-sync' ); ?></option>
                        </select>
                    </div>
                    
                    <button type="submit" class="wns-btn wns-btn-secondary">
                        <span class="dashicons dashicons-filter"></span>
                        <?php esc_html_e( 'Filter', 'woo-nalda-sync' ); ?>
                    </button>
                    
                    <?php if ( $type_filter ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wns-history' ) ); ?>" class="wns-btn wns-btn-ghost">
                            <?php esc_html_e( 'Reset', 'woo-nalda-sync' ); ?>
                        </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Uploads Table -->
        <div class="wns-section">
            <?php if ( empty( $uploads ) ) : ?>
                <div class="wns-empty-state">
                    <span class="dashicons dashicons-cloud-upload"></span>
                    <p><?php esc_html_e( 'No CSV uploads found.', 'woo-nalda-sync' ); ?></p>
                    <p class="wns-muted"><?php esc_html_e( 'Run a product export or order status export to see your upload history.', 'woo-nalda-sync' ); ?></p>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=wns-dashboard' ) ); ?>" class="wns-btn wns-btn-primary wns-btn-sm">
                        <?php esc_html_e( 'Go to Dashboard', 'woo-nalda-sync' ); ?>
                    </a>
                </div>
            <?php else : ?>
                <div class="wns-table-wrap">
                    <table class="wns-table">
                        <thead>
                            <tr>
                                <th class="wns-col-id"><?php esc_html_e( 'ID', 'woo-nalda-sync' ); ?></th>
                                <th class="wns-col-type"><?php esc_html_e( 'Type', 'woo-nalda-sync' ); ?></th>
                                <th class="wns-col-filename"><?php esc_html_e( 'File Name', 'woo-nalda-sync' ); ?></th>
                                <th class="wns-col-status"><?php esc_html_e( 'Status', 'woo-nalda-sync' ); ?></th>
                                <th class="wns-col-date"><?php esc_html_e( 'Date', 'woo-nalda-sync' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $uploads as $upload ) : 
                                $status       = isset( $upload['status'] ) ? $upload['status'] : 'unknown';
                                $status_class = in_array( $status, array( 'uploaded', 'success', 'completed' ), true ) ? 'success' : ( in_array( $status, array( 'failed', 'error' ), true ) ? 'error' : 'warning' );
                                $type_class   = $upload['type'] === 'products' ? 'products' : 'orders';
                                $type_label   = $upload['type'] === 'products' ? __( 'Products', 'woo-nalda-sync' ) : __( 'Order Status', 'woo-nalda-sync' );
                                $date         = strtotime( $upload['created_at'] );
                            ?>
                                <tr class="wns-upload-row wns-upload-<?php echo esc_attr( $status_class ); ?>">
                                    <td class="wns-col-id">
                                        <span class="wns-upload-id">#<?php echo esc_html( $upload['id'] ); ?></span>
                                    </td>
                                    <td class="wns-col-type">
                                        <span class="wns-type-badge wns-type-<?php echo esc_attr( $type_class ); ?>">
                                            <?php if ( $upload['type'] === 'products' ) : ?>
                                                <span class="dashicons dashicons-products"></span>
                                            <?php else : ?>
                                                <span class="dashicons dashicons-yes-alt"></span>
                                            <?php endif; ?>
                                            <?php echo esc_html( $type_label ); ?>
                                        </span>
                                    </td>
                                    <td class="wns-col-filename">
                                        <span class="wns-filename" title="<?php echo esc_attr( $upload['file_name'] ); ?>">
                                            <?php echo esc_html( $upload['file_name'] ); ?>
                                        </span>
                                    </td>
                                    <td class="wns-col-status">
                                        <span class="wns-status-badge wns-status-<?php echo esc_attr( $status_class ); ?>">
                                            <?php if ( $status_class === 'success' ) : ?>
                                                <span class="dashicons dashicons-yes-alt"></span>
                                            <?php elseif ( $status_class === 'error' ) : ?>
                                                <span class="dashicons dashicons-dismiss"></span>
                                            <?php else : ?>
                                                <span class="dashicons dashicons-clock"></span>
                                            <?php endif; ?>
                                            <?php echo esc_html( $status ); ?>
                                        </span>
                                    </td>
                                    <td class="wns-col-date">
                                        <span class="wns-date wns-local-time" data-timestamp="<?php echo esc_attr( $date ); ?>" title="<?php echo esc_attr( date_i18n( 'Y-m-d H:i:s', $date ) ); ?>">
                                            <?php echo esc_html( human_time_diff( $date, time() ) ); ?> <?php esc_html_e( 'ago', 'woo-nalda-sync' ); ?>
                                        </span>
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
                                esc_html__( 'Showing %1$d-%2$d of %3$d uploads', 'woo-nalda-sync' ),
                                ( ( $paged - 1 ) * $per_page ) + 1,
                                min( $paged * $per_page, $total_items ),
                                $total_items
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

        <!-- Legend -->
        <div class="wns-section">
            <div class="wns-card">
                <div class="wns-card-header">
                    <h3><?php esc_html_e( 'Legend', 'woo-nalda-sync' ); ?></h3>
                </div>
                <div class="wns-card-body">
                    <div class="wns-legend-row">
                        <div class="wns-legend-section">
                            <h4><?php esc_html_e( 'Upload Types', 'woo-nalda-sync' ); ?></h4>
                            <div class="wns-legend-items">
                                <div class="wns-legend-item">
                                    <span class="wns-type-badge wns-type-products">
                                        <span class="dashicons dashicons-products"></span>
                                        <?php esc_html_e( 'Products', 'woo-nalda-sync' ); ?>
                                    </span>
                                    <span class="wns-legend-desc"><?php esc_html_e( 'Product catalog export', 'woo-nalda-sync' ); ?></span>
                                </div>
                                <div class="wns-legend-item">
                                    <span class="wns-type-badge wns-type-orders">
                                        <span class="dashicons dashicons-yes-alt"></span>
                                        <?php esc_html_e( 'Order Status', 'woo-nalda-sync' ); ?>
                                    </span>
                                    <span class="wns-legend-desc"><?php esc_html_e( 'Order status updates', 'woo-nalda-sync' ); ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="wns-legend-section">
                            <h4><?php esc_html_e( 'Upload Status', 'woo-nalda-sync' ); ?></h4>
                            <div class="wns-legend-items">
                                <div class="wns-legend-item">
                                    <span class="wns-status-badge wns-status-success">
                                        <span class="dashicons dashicons-yes-alt"></span>
                                        <?php esc_html_e( 'Uploaded', 'woo-nalda-sync' ); ?>
                                    </span>
                                    <span class="wns-legend-desc"><?php esc_html_e( 'Successfully uploaded to SFTP', 'woo-nalda-sync' ); ?></span>
                                </div>
                                <div class="wns-legend-item">
                                    <span class="wns-status-badge wns-status-warning">
                                        <span class="dashicons dashicons-clock"></span>
                                        <?php esc_html_e( 'Processing', 'woo-nalda-sync' ); ?>
                                    </span>
                                    <span class="wns-legend-desc"><?php esc_html_e( 'Upload in progress', 'woo-nalda-sync' ); ?></span>
                                </div>
                                <div class="wns-legend-item">
                                    <span class="wns-status-badge wns-status-error">
                                        <span class="dashicons dashicons-dismiss"></span>
                                        <?php esc_html_e( 'Failed', 'woo-nalda-sync' ); ?>
                                    </span>
                                    <span class="wns-legend-desc"><?php esc_html_e( 'Upload failed', 'woo-nalda-sync' ); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
