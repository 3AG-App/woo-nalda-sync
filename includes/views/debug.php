<?php
/**
 * Debug Page View
 *
 * Hidden debug page for fixing data issues.
 * Access via: /wp-admin/admin.php?page=wns-debug
 *
 * @package Woo_Nalda_Sync
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Handle fix action
$fixed_count = 0;
$cleaned_count = 0;
$fix_message = '';

if ( isset( $_POST['wns_fix_nalda_orders'] ) && check_admin_referer( 'wns_debug_fix_orders' ) ) {
    // Find orders with _nalda_order_id but without _nalda_order meta
    $orders_to_fix = wc_get_orders( array(
        'limit'      => -1,
        'type'       => 'shop_order', // Exclude refunds
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key'     => '_nalda_order_id',
                'compare' => 'EXISTS',
            ),
            array(
                'key'     => '_nalda_order_id',
                'value'   => '',
                'compare' => '!=',
            ),
        ),
    ) );

    foreach ( $orders_to_fix as $order ) {
        // Skip if not a proper WC_Order
        if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) {
            continue;
        }
        $nalda_order_flag = $order->get_meta( '_nalda_order' );
        if ( empty( $nalda_order_flag ) || 'yes' !== $nalda_order_flag ) {
            $order->update_meta_data( '_nalda_order', 'yes' );
            $order->save();
            $fixed_count++;
        }
    }

    $fix_message = sprintf(
        /* translators: %d: number of orders fixed */
        __( 'Fixed %d order(s) - added _nalda_order meta.', 'woo-nalda-sync' ),
        $fixed_count
    );
}

// Handle cleanup of empty _nalda_order_id meta
if ( isset( $_POST['wns_cleanup_empty_meta'] ) && check_admin_referer( 'wns_debug_cleanup_empty' ) ) {
    // Find orders with empty _nalda_order_id
    $orders_to_cleanup = wc_get_orders( array(
        'limit'      => -1,
        'type'       => 'shop_order',
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key'     => '_nalda_order_id',
                'compare' => 'EXISTS',
            ),
            array(
                'relation' => 'OR',
                array(
                    'key'     => '_nalda_order_id',
                    'value'   => '',
                    'compare' => '=',
                ),
                array(
                    'key'     => '_nalda_order_id',
                    'value'   => '0',
                    'compare' => '=',
                ),
            ),
        ),
    ) );

    foreach ( $orders_to_cleanup as $order ) {
        if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) {
            continue;
        }
        $order->delete_meta_data( '_nalda_order_id' );
        $order->delete_meta_data( '_nalda_order' );
        $order->save();
        $cleaned_count++;
    }

    $fix_message = sprintf(
        /* translators: %d: number of orders cleaned */
        __( 'Cleaned %d order(s) - removed empty Nalda meta.', 'woo-nalda-sync' ),
        $cleaned_count
    );
}

// Find orders that need fixing (have _nalda_order_id but not _nalda_order = 'yes')
$orders_with_nalda_id = wc_get_orders( array(
    'limit'      => -1,
    'type'       => 'shop_order', // Exclude refunds
    'meta_query' => array(
        'relation' => 'AND',
        array(
            'key'     => '_nalda_order_id',
            'compare' => 'EXISTS',
        ),
        array(
            'key'     => '_nalda_order_id',
            'value'   => '',
            'compare' => '!=',
        ),
    ),
) );

// Find orders with empty _nalda_order_id (orphaned meta)
$orders_with_empty_nalda_id = wc_get_orders( array(
    'limit'      => -1,
    'type'       => 'shop_order',
    'meta_query' => array(
        'relation' => 'AND',
        array(
            'key'     => '_nalda_order_id',
            'compare' => 'EXISTS',
        ),
        array(
            'relation' => 'OR',
            array(
                'key'     => '_nalda_order_id',
                'value'   => '',
                'compare' => '=',
            ),
            array(
                'key'     => '_nalda_order_id',
                'value'   => '0',
                'compare' => '=',
            ),
        ),
    ),
) );

$orders_needing_fix = array();
$orders_ok = array();

foreach ( $orders_with_nalda_id as $order ) {
    // Skip if not a proper WC_Order
    if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) {
        continue;
    }
    $nalda_order_flag = $order->get_meta( '_nalda_order' );
    if ( empty( $nalda_order_flag ) || 'yes' !== $nalda_order_flag ) {
        $orders_needing_fix[] = $order;
    } else {
        $orders_ok[] = $order;
    }
}

// Filter empty nalda id orders
$orders_with_empty_meta = array();
foreach ( $orders_with_empty_nalda_id as $order ) {
    if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) {
        continue;
    }
    $orders_with_empty_meta[] = $order;
}
?>

<div class="wrap wns-wrap">
    <h1>
        <span class="dashicons dashicons-admin-tools" style="font-size: 28px; margin-right: 10px;"></span>
        <?php esc_html_e( 'Nalda Sync Debug', 'woo-nalda-sync' ); ?>
    </h1>
    
    <p class="description" style="margin-bottom: 20px;">
        <?php esc_html_e( 'This is a hidden debug page for fixing data issues. It is not accessible from the menu.', 'woo-nalda-sync' ); ?>
    </p>

    <?php if ( $fix_message ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html( $fix_message ); ?></p>
        </div>
    <?php endif; ?>

    <div class="wns-card" style="max-width: 800px;">
        <div class="wns-card-header">
            <h2>
                <span class="dashicons dashicons-database"></span>
                <?php esc_html_e( 'Fix Missing _nalda_order Meta', 'woo-nalda-sync' ); ?>
            </h2>
        </div>
        <div class="wns-card-body">
            <p>
                <?php esc_html_e( 'Some orders imported by an older version of the plugin may have _nalda_order_id but are missing the _nalda_order="yes" flag. This can cause issues with order identification.', 'woo-nalda-sync' ); ?>
            </p>

            <table class="widefat striped" style="margin: 20px 0;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Status', 'woo-nalda-sync' ); ?></th>
                        <th><?php esc_html_e( 'Count', 'woo-nalda-sync' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <span style="color: #d63638;">●</span>
                            <?php esc_html_e( 'Orders needing fix (have _nalda_order_id but missing _nalda_order)', 'woo-nalda-sync' ); ?>
                        </td>
                        <td><strong><?php echo count( $orders_needing_fix ); ?></strong></td>
                    </tr>
                    <tr>
                        <td>
                            <span style="color: #00a32a;">●</span>
                            <?php esc_html_e( 'Orders OK (have both _nalda_order_id and _nalda_order)', 'woo-nalda-sync' ); ?>
                        </td>
                        <td><strong><?php echo count( $orders_ok ); ?></strong></td>
                    </tr>
                    <tr>
                        <td>
                            <span style="color: #2271b1;">●</span>
                            <?php esc_html_e( 'Total Nalda orders', 'woo-nalda-sync' ); ?>
                        </td>
                        <td><strong><?php echo count( $orders_with_nalda_id ); ?></strong></td>
                    </tr>
                </tbody>
            </table>

            <?php if ( ! empty( $orders_needing_fix ) ) : ?>
                <h4><?php esc_html_e( 'Orders Needing Fix:', 'woo-nalda-sync' ); ?></h4>
                <table class="widefat striped" style="margin-bottom: 20px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'WC Order', 'woo-nalda-sync' ); ?></th>
                            <th><?php esc_html_e( 'Nalda Order ID', 'woo-nalda-sync' ); ?></th>
                            <th><?php esc_html_e( 'Date', 'woo-nalda-sync' ); ?></th>
                            <th><?php esc_html_e( 'Total', 'woo-nalda-sync' ); ?></th>
                            <th><?php esc_html_e( '_nalda_order', 'woo-nalda-sync' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $orders_needing_fix as $order ) : ?>
                            <tr>
                                <td>
                                    <a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">
                                        #<?php echo esc_html( $order->get_id() ); ?>
                                    </a>
                                </td>
                                <td><?php echo esc_html( $order->get_meta( '_nalda_order_id' ) ); ?></td>
                                <td><?php echo esc_html( $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) ); ?></td>
                                <td><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
                                <td>
                                    <span style="color: #d63638; font-weight: bold;">
                                        <?php echo esc_html( $order->get_meta( '_nalda_order' ) ?: '(empty)' ); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <form method="post">
                    <?php wp_nonce_field( 'wns_debug_fix_orders' ); ?>
                    <button type="submit" name="wns_fix_nalda_orders" class="button button-primary">
                        <span class="dashicons dashicons-admin-tools" style="vertical-align: middle; margin-right: 5px;"></span>
                        <?php
                        printf(
                            /* translators: %d: number of orders to fix */
                            esc_html__( 'Fix %d Order(s)', 'woo-nalda-sync' ),
                            count( $orders_needing_fix )
                        );
                        ?>
                    </button>
                    <p class="description" style="margin-top: 10px;">
                        <?php esc_html_e( 'This will add _nalda_order="yes" meta to all orders listed above.', 'woo-nalda-sync' ); ?>
                    </p>
                </form>
            <?php else : ?>
                <div class="notice notice-success inline" style="margin: 20px 0;">
                    <p>
                        <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                        <?php esc_html_e( 'All Nalda orders have the correct meta. No fixes needed!', 'woo-nalda-sync' ); ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Second card: Cleanup empty meta -->
    <div class="wns-card" style="max-width: 800px; margin-top: 20px;">
        <div class="wns-card-header">
            <h2>
                <span class="dashicons dashicons-trash"></span>
                <?php esc_html_e( 'Cleanup Empty _nalda_order_id Meta', 'woo-nalda-sync' ); ?>
            </h2>
        </div>
        <div class="wns-card-body">
            <p>
                <?php esc_html_e( 'Some orders may have empty _nalda_order_id meta keys (orphaned data). These should be removed to prevent confusion.', 'woo-nalda-sync' ); ?>
            </p>

            <table class="widefat striped" style="margin: 20px 0;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Status', 'woo-nalda-sync' ); ?></th>
                        <th><?php esc_html_e( 'Count', 'woo-nalda-sync' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <span style="color: #f0ad4e;">●</span>
                            <?php esc_html_e( 'Orders with empty _nalda_order_id (orphaned meta)', 'woo-nalda-sync' ); ?>
                        </td>
                        <td><strong><?php echo count( $orders_with_empty_meta ); ?></strong></td>
                    </tr>
                </tbody>
            </table>

            <?php if ( ! empty( $orders_with_empty_meta ) ) : ?>
                <h4><?php esc_html_e( 'Orders with Empty Meta:', 'woo-nalda-sync' ); ?></h4>
                <table class="widefat striped" style="margin-bottom: 20px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'WC Order', 'woo-nalda-sync' ); ?></th>
                            <th><?php esc_html_e( 'Date', 'woo-nalda-sync' ); ?></th>
                            <th><?php esc_html_e( 'Total', 'woo-nalda-sync' ); ?></th>
                            <th><?php esc_html_e( '_nalda_order_id value', 'woo-nalda-sync' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $orders_with_empty_meta as $order ) : ?>
                            <tr>
                                <td>
                                    <a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">
                                        #<?php echo esc_html( $order->get_id() ); ?>
                                    </a>
                                </td>
                                <td><?php echo esc_html( $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) : '-' ); ?></td>
                                <td><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
                                <td>
                                    <code style="color: #f0ad4e;"><?php echo esc_html( var_export( $order->get_meta( '_nalda_order_id' ), true ) ); ?></code>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <form method="post">
                    <?php wp_nonce_field( 'wns_debug_cleanup_empty' ); ?>
                    <button type="submit" name="wns_cleanup_empty_meta" class="button button-secondary">
                        <span class="dashicons dashicons-trash" style="vertical-align: middle; margin-right: 5px;"></span>
                        <?php
                        printf(
                            /* translators: %d: number of orders to cleanup */
                            esc_html__( 'Remove Empty Meta from %d Order(s)', 'woo-nalda-sync' ),
                            count( $orders_with_empty_meta )
                        );
                        ?>
                    </button>
                    <p class="description" style="margin-top: 10px;">
                        <?php esc_html_e( 'This will delete _nalda_order_id and _nalda_order meta from orders with empty values.', 'woo-nalda-sync' ); ?>
                    </p>
                </form>
            <?php else : ?>
                <div class="notice notice-success inline" style="margin: 20px 0;">
                    <p>
                        <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                        <?php esc_html_e( 'No orders with empty Nalda meta found. All clean!', 'woo-nalda-sync' ); ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="wns-card" style="max-width: 800px; margin-top: 20px;">
        <div class="wns-card-header">
            <h2>
                <span class="dashicons dashicons-info"></span>
                <?php esc_html_e( 'Debug Information', 'woo-nalda-sync' ); ?>
            </h2>
        </div>
        <div class="wns-card-body">
            <table class="widefat striped">
                <tbody>
                    <tr>
                        <td><strong><?php esc_html_e( 'Plugin Version', 'woo-nalda-sync' ); ?></strong></td>
                        <td><?php echo esc_html( WNS_VERSION ); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'WooCommerce Version', 'woo-nalda-sync' ); ?></strong></td>
                        <td><?php echo esc_html( WC()->version ); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'HPOS Enabled', 'woo-nalda-sync' ); ?></strong></td>
                        <td>
                            <?php
                            $hpos_enabled = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) &&
                                wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled();
                            echo $hpos_enabled ? esc_html__( 'Yes', 'woo-nalda-sync' ) : esc_html__( 'No', 'woo-nalda-sync' );
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'PHP Version', 'woo-nalda-sync' ); ?></strong></td>
                        <td><?php echo esc_html( phpversion() ); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
