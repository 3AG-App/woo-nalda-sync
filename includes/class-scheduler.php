<?php
/**
 * Scheduler Class
 * 
 * Manages cron jobs for sync operations and watchdog monitoring.
 *
 * @package Woo_Nalda_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Scheduler class
 */
class WNS_Scheduler {

    /**
     * Available schedule intervals (includes custom + WordPress built-ins)
     *
     * @var array
     */
    private $intervals = array();

    /**
     * Constructor
     */
    public function __construct() {
        // Add custom cron intervals
        add_filter( 'cron_schedules', array( $this, 'add_cron_intervals' ) );

        // Watchdog check
        add_action( 'wns_watchdog_check', array( $this, 'watchdog_check' ) );

        // Sync event handlers
        add_action( 'wns_product_export_event', array( $this, 'run_product_export' ) );
        add_action( 'wns_order_import_event', array( $this, 'run_order_import' ) );
        add_action( 'wns_order_status_export_event', array( $this, 'run_order_status_export' ) );

        // Initialize intervals
        $this->init_intervals();
    }

    /**
     * Initialize intervals - combine custom intervals with WordPress built-ins
     */
    private function init_intervals() {
        $custom_intervals = self::get_custom_cron_intervals();

        // Add WordPress built-in intervals that we want to expose in the UI
        $builtin_intervals = array(
            'hourly' => array(
                'interval' => HOUR_IN_SECONDS,
                'display'  => __( 'Hourly', 'woo-nalda-sync' ),
            ),
            'daily'  => array(
                'interval' => DAY_IN_SECONDS,
                'display'  => __( 'Daily', 'woo-nalda-sync' ),
            ),
            'weekly' => array(
                'interval' => WEEK_IN_SECONDS,
                'display'  => __( 'Weekly', 'woo-nalda-sync' ),
            ),
        );

        // Merge: custom first, then built-ins (order matters for UI)
        $this->intervals = array_merge(
            array( 'wns_5min' => $custom_intervals['wns_5min'] ),
            array( 'wns_15min' => $custom_intervals['wns_15min'] ),
            array( 'wns_30min' => $custom_intervals['wns_30min'] ),
            array( 'hourly' => $builtin_intervals['hourly'] ),
            array( 'wns_2hours' => $custom_intervals['wns_2hours'] ),
            array( 'wns_4hours' => $custom_intervals['wns_4hours'] ),
            array( 'wns_6hours' => $custom_intervals['wns_6hours'] ),
            array( 'wns_12hours' => $custom_intervals['wns_12hours'] ),
            array( 'daily' => $builtin_intervals['daily'] ),
            array( 'wns_2days' => $custom_intervals['wns_2days'] ),
            array( 'weekly' => $builtin_intervals['weekly'] )
        );
    }

    /**
     * Get custom cron intervals definition
     *
     * @return array
     */
    public static function get_custom_cron_intervals() {
        return array(
            'wns_5min'    => array(
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 5 Minutes', 'woo-nalda-sync' ),
            ),
            'wns_15min'   => array(
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 15 Minutes', 'woo-nalda-sync' ),
            ),
            'wns_30min'   => array(
                'interval' => 30 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 30 Minutes', 'woo-nalda-sync' ),
            ),
            'wns_2hours'  => array(
                'interval' => 2 * HOUR_IN_SECONDS,
                'display'  => __( 'Every 2 Hours', 'woo-nalda-sync' ),
            ),
            'wns_4hours'  => array(
                'interval' => 4 * HOUR_IN_SECONDS,
                'display'  => __( 'Every 4 Hours', 'woo-nalda-sync' ),
            ),
            'wns_6hours'  => array(
                'interval' => 6 * HOUR_IN_SECONDS,
                'display'  => __( 'Every 6 Hours', 'woo-nalda-sync' ),
            ),
            'wns_12hours' => array(
                'interval' => 12 * HOUR_IN_SECONDS,
                'display'  => __( 'Every 12 Hours', 'woo-nalda-sync' ),
            ),
            'wns_2days'   => array(
                'interval' => 2 * DAY_IN_SECONDS,
                'display'  => __( 'Every 2 Days', 'woo-nalda-sync' ),
            ),
        );
    }

    /**
     * Add custom cron intervals to WordPress
     *
     * @param array $schedules Existing schedules.
     * @return array
     */
    public function add_cron_intervals( $schedules ) {
        $custom_intervals = self::get_custom_cron_intervals();

        foreach ( $custom_intervals as $key => $data ) {
            if ( ! isset( $schedules[ $key ] ) ) {
                $schedules[ $key ] = $data;
            }
        }

        return $schedules;
    }

    /**
     * Get available intervals
     *
     * @return array
     */
    public function get_intervals() {
        return $this->intervals;
    }

    /**
     * Schedule sync event
     *
     * @param string $event_name Event name.
     * @param string $interval   Interval key.
     * @param bool   $force      Force reschedule.
     * @return bool
     */
    public function schedule( $event_name, $interval = null, $force = false ) {
        if ( ! $interval ) {
            $interval = 'hourly';
        }

        $next_scheduled   = wp_next_scheduled( $event_name );
        $interval_seconds = $this->get_interval_seconds( $interval );

        // If not forcing, check if we should keep the current schedule
        if ( ! $force && $next_scheduled ) {
            $time_until_next = $next_scheduled - time();

            // If next run is in the future and within the interval, keep it
            if ( $time_until_next > 0 && $time_until_next <= $interval_seconds ) {
                // Schedule is still valid, ensure watchdog is scheduled
                $this->ensure_watchdog();
                return true;
            }
        }

        // Clear existing schedule
        wp_clear_scheduled_hook( $event_name );

        // Schedule new event
        wp_schedule_event( time() + $interval_seconds, $interval, $event_name );

        // Ensure watchdog is scheduled
        $this->ensure_watchdog();

        return true;
    }

    /**
     * Unschedule sync event
     *
     * @param string $event_name Event name.
     * @return bool
     */
    public function unschedule( $event_name ) {
        wp_clear_scheduled_hook( $event_name );
        return true;
    }

    /**
     * Reschedule all enabled syncs
     */
    public function reschedule_all() {
        $this->reschedule_product_export();
        $this->reschedule_order_import();
        $this->reschedule_order_status_export();
    }

    /**
     * Reschedule product export
     */
    public function reschedule_product_export() {
        wp_clear_scheduled_hook( 'wns_product_export_event' );

        $enabled  = get_option( 'wns_product_export_enabled', false );
        $interval = get_option( 'wns_product_export_interval', 'daily' );

        if ( $enabled && WNS()->license->is_valid() ) {
            $this->schedule( 'wns_product_export_event', $interval );
        }
    }

    /**
     * Reschedule order import
     */
    public function reschedule_order_import() {
        wp_clear_scheduled_hook( 'wns_order_import_event' );

        $enabled  = get_option( 'wns_order_import_enabled', false );
        $interval = get_option( 'wns_order_import_interval', 'hourly' );

        if ( $enabled && WNS()->license->is_valid() ) {
            $this->schedule( 'wns_order_import_event', $interval );
        }
    }

    /**
     * Reschedule order status export
     */
    public function reschedule_order_status_export() {
        wp_clear_scheduled_hook( 'wns_order_status_export_event' );

        $enabled  = get_option( 'wns_order_status_export_enabled', false );
        $interval = get_option( 'wns_order_status_export_interval', 'hourly' );

        if ( $enabled && WNS()->license->is_valid() ) {
            $this->schedule( 'wns_order_status_export_event', $interval );
        }
    }

    /**
     * Run product export (cron callback)
     */
    public function run_product_export() {
        if ( ! WNS()->license->is_valid() ) {
            return;
        }

        // Set running status
        $this->set_running( 'product_export', true );

        try {
            WNS()->product_export->run( 'scheduled' );
        } finally {
            $this->set_running( 'product_export', false );
        }
    }

    /**
     * Run order import (cron callback)
     */
    public function run_order_import() {
        if ( ! WNS()->license->is_valid() ) {
            return;
        }

        $this->set_running( 'order_import', true );

        try {
            WNS()->order_import->run( 'scheduled' );
        } finally {
            $this->set_running( 'order_import', false );
        }
    }

    /**
     * Run order status export (cron callback)
     */
    public function run_order_status_export() {
        if ( ! WNS()->license->is_valid() ) {
            return;
        }

        $this->set_running( 'order_status_export', true );

        try {
            WNS()->order_status_export->run( 'scheduled' );
        } finally {
            $this->set_running( 'order_status_export', false );
        }
    }

    /**
     * Ensure watchdog is scheduled
     */
    private function ensure_watchdog() {
        if ( ! wp_next_scheduled( 'wns_watchdog_check' ) ) {
            wp_schedule_event( time(), 'hourly', 'wns_watchdog_check' );
        }
    }

    /**
     * Watchdog check
     * 
     * This runs every hour to ensure the sync crons are properly scheduled.
     * If sync is enabled but no cron is scheduled, it will reschedule it.
     */
    public function watchdog_check() {
        // Check if license is valid
        if ( ! WNS()->license->is_valid() ) {
            // Log and disable all
            WNS()->logs->add(
                array(
                    'type'    => 'watchdog',
                    'trigger' => 'scheduled',
                    'status'  => 'warning',
                    'message' => __( 'Watchdog: License invalid. All syncs disabled.', 'woo-nalda-sync' ),
                )
            );

            update_option( 'wns_product_export_enabled', false );
            update_option( 'wns_order_import_enabled', false );
            update_option( 'wns_order_status_export_enabled', false );

            $this->unschedule( 'wns_product_export_event' );
            $this->unschedule( 'wns_order_import_event' );
            $this->unschedule( 'wns_order_status_export_event' );

            return;
        }

        $rescheduled = array();

        // Check product export
        $product_export_enabled = get_option( 'wns_product_export_enabled', false );
        if ( $product_export_enabled ) {
            $next_run = wp_next_scheduled( 'wns_product_export_event' );
            $interval = get_option( 'wns_product_export_interval', 'daily' );

            if ( ! $next_run ) {
                $this->reschedule_product_export();
                $rescheduled[] = 'product_export';
            } elseif ( $this->is_overdue( $next_run, $interval ) ) {
                $this->reschedule_product_export();
                $rescheduled[] = 'product_export';
            }
        }

        // Check order import
        $order_import_enabled = get_option( 'wns_order_import_enabled', false );
        if ( $order_import_enabled ) {
            $next_run = wp_next_scheduled( 'wns_order_import_event' );
            $interval = get_option( 'wns_order_import_interval', 'hourly' );

            if ( ! $next_run ) {
                $this->reschedule_order_import();
                $rescheduled[] = 'order_import';
            } elseif ( $this->is_overdue( $next_run, $interval ) ) {
                $this->reschedule_order_import();
                $rescheduled[] = 'order_import';
            }
        }

        // Check order status export
        $order_status_enabled = get_option( 'wns_order_status_export_enabled', false );
        if ( $order_status_enabled ) {
            $next_run = wp_next_scheduled( 'wns_order_status_export_event' );
            $interval = get_option( 'wns_order_status_export_interval', 'hourly' );

            if ( ! $next_run ) {
                $this->reschedule_order_status_export();
                $rescheduled[] = 'order_status_export';
            } elseif ( $this->is_overdue( $next_run, $interval ) ) {
                $this->reschedule_order_status_export();
                $rescheduled[] = 'order_status_export';
            }
        }

        // Log if anything was rescheduled
        if ( ! empty( $rescheduled ) ) {
            WNS()->logs->add(
                array(
                    'type'    => 'watchdog',
                    'trigger' => 'scheduled',
                    'status'  => 'warning',
                    'message' => sprintf(
                        /* translators: %s: comma-separated list of sync types */
                        __( 'Watchdog: Rescheduled stuck crons: %s', 'woo-nalda-sync' ),
                        implode( ', ', $rescheduled )
                    ),
                )
            );
        }

        // Update last watchdog check time
        update_option( 'wns_watchdog_last_check', time() );
    }

    /**
     * Check if a cron is overdue
     *
     * @param int    $next_run Next scheduled run timestamp.
     * @param string $interval Interval key.
     * @return bool
     */
    private function is_overdue( $next_run, $interval ) {
        $interval_seconds   = $this->get_interval_seconds( $interval );
        $overdue_threshold  = $interval_seconds * 2;
        $time_diff          = $next_run - time();

        return $time_diff < -$overdue_threshold;
    }

    /**
     * Get interval in seconds
     *
     * @param string $interval_key Interval key.
     * @return int
     */
    public function get_interval_seconds( $interval_key ) {
        $schedules = wp_get_schedules();

        if ( isset( $schedules[ $interval_key ] ) ) {
            return $schedules[ $interval_key ]['interval'];
        }

        return HOUR_IN_SECONDS; // Default to hourly
    }

    /**
     * Get next scheduled run for an event
     *
     * @param string $event_name Event name.
     * @return int|null
     */
    public function get_next_run( $event_name ) {
        $timestamp = wp_next_scheduled( $event_name );
        return $timestamp ? $timestamp : null;
    }

    /**
     * Get time until next run
     *
     * @param string $event_name Event name.
     * @return string|null
     */
    public function get_time_until_next_run( $event_name ) {
        $next = $this->get_next_run( $event_name );

        if ( ! $next ) {
            return null;
        }

        $diff = $next - time();

        if ( $diff < 0 ) {
            return __( 'Overdue', 'woo-nalda-sync' );
        }

        return human_time_diff( time(), $next );
    }

    /**
     * Get sync status info
     *
     * @param string $type Sync type (product_export, order_import, order_status_export).
     * @return array
     */
    public function get_status( $type ) {
        $event_map = array(
            'product_export'      => 'wns_product_export_event',
            'order_import'        => 'wns_order_import_event',
            'order_status_export' => 'wns_order_status_export_event',
        );

        $option_map = array(
            'product_export'      => array(
                'enabled'  => 'wns_product_export_enabled',
                'interval' => 'wns_product_export_interval',
            ),
            'order_import'        => array(
                'enabled'  => 'wns_order_import_enabled',
                'interval' => 'wns_order_import_interval',
            ),
            'order_status_export' => array(
                'enabled'  => 'wns_order_status_export_enabled',
                'interval' => 'wns_order_status_export_interval',
            ),
        );

        if ( ! isset( $event_map[ $type ] ) ) {
            return array();
        }

        $event_name = $event_map[ $type ];
        $enabled    = get_option( $option_map[ $type ]['enabled'], false );
        $interval   = get_option( $option_map[ $type ]['interval'], 'hourly' );
        $next_run   = $this->get_next_run( $event_name );

        $schedules        = wp_get_schedules();
        $interval_display = isset( $schedules[ $interval ] ) ? $schedules[ $interval ]['display'] : $interval;

        // Calculate human-readable next run
        $next_run_human = null;
        if ( $next_run ) {
            $time_diff = $next_run - time();
            if ( $time_diff < 0 ) {
                $next_run_human = sprintf(
                    /* translators: %s: time difference */
                    __( 'Overdue by %s', 'woo-nalda-sync' ),
                    human_time_diff( $next_run, time() )
                );
            } else {
                $next_run_human = human_time_diff( time(), $next_run );
            }
        }

        return array(
            'enabled'            => $enabled,
            'interval'           => $interval,
            'interval_display'   => $interval_display,
            'next_run'           => $next_run,
            'next_run_human'     => $next_run_human,
            'next_run_formatted' => $next_run ? wp_date( 'Y-m-d H:i:s', $next_run ) : null,
            'next_run_overdue'   => $next_run && ( $next_run < time() ),
            'is_running'         => $this->is_running( $type ),
        );
    }

    /**
     * Check if sync is currently running
     *
     * @param string $type Sync type.
     * @return bool
     */
    public function is_running( $type ) {
        return get_transient( "wns_{$type}_running" ) ? true : false;
    }

    /**
     * Set running status
     *
     * @param string $type    Sync type.
     * @param bool   $running Running status.
     */
    public function set_running( $type, $running = true ) {
        if ( $running ) {
            set_transient( "wns_{$type}_running", true, 30 * MINUTE_IN_SECONDS );
        } else {
            delete_transient( "wns_{$type}_running" );
        }
    }
}
