<?php
namespace WCAR;

use WCAR\Security\Capabilities;

final class Installer {
    public const DB_VERSION = '1.0.1';

    public static function activate( bool $network_wide = false ): void {
        if ( $network_wide && is_multisite() ) {
            foreach ( get_sites( array( 'fields'=>'ids', 'number'=>0 ) ) as $site_id ) {
                switch_to_blog( (int) $site_id );
                self::activate_site();
                restore_current_blog();
            }
            return;
        }
        self::activate_site();
    }

    private static function activate_site(): void {
        $new_install = false === get_option( 'wcar_settings', false );
        self::create_tables();
        Capabilities::install( $new_install );
        update_option( 'wcar_db_version', self::DB_VERSION );
        update_option( 'wcar_order_cache_version', (string) microtime( true ), false );
        update_option( 'wcar_report_cache_version', (string) microtime( true ), false );
        if ( ! get_option( 'wcar_settings' ) ) {
            add_option( 'wcar_settings', self::default_settings() );
        }
        flush_rewrite_rules();
    }

    public static function deactivate( bool $network_wide = false ): void {
        if ( $network_wide && is_multisite() ) {
            foreach ( get_sites( array( 'fields'=>'ids', 'number'=>0 ) ) as $site_id ) {
                switch_to_blog( (int) $site_id );
                self::deactivate_site();
                restore_current_blog();
            }
            return;
        }
        self::deactivate_site();
    }

    private static function deactivate_site(): void {
        foreach ( array( 'wcar_fallback_schedule_runner', 'wcar_cleanup_exports', 'wcar_generate_export_job', 'wcar_run_scheduled_report' ) as $hook ) {
            wp_unschedule_hook( $hook );
        }
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( 'wcar_generate_export_job' );
            as_unschedule_all_actions( 'wcar_run_scheduled_report' );
        }
        delete_transient( 'wcar_export_reconcile_lock' );
        delete_transient( 'wcar_schedule_reconcile_lock' );
        delete_transient( 'wcar_schedule_reconcile_cursor' );
    }

    public static function maybe_upgrade(): void {
        if ( self::DB_VERSION !== get_option( 'wcar_db_version' ) ) {
            $new_install = false === get_option( 'wcar_settings', false );
            self::create_tables();
            Capabilities::install( $new_install );
            update_option( 'wcar_db_version', self::DB_VERSION );
            update_option( 'wcar_order_cache_version', (string) microtime( true ), false );
            update_option( 'wcar_report_cache_version', (string) microtime( true ), false );
            if ( $new_install ) { add_option( 'wcar_settings', self::default_settings() ); }
        }
    }

    public static function default_settings(): array {
        return array(
            'calendar'            => 'gregorian',
            'date_format'         => 'Y-m-d',
            'jalali_date_format'  => 'Y/m/d',
            'first_day_of_week'   => '1',
            'default_range'       => '30',
            'default_statuses'    => array( 'processing', 'completed' ),
            'cache_ttl'           => '300',
            'batch_size'          => '200',
            'privacy'             => 'full',
            'export_format'       => 'xlsx',
            'csv_bom'             => 'yes',
            'print_logo'          => '',
            'number_decimals'      => '2',
            'decimal_separator'    => '.',
            'thousand_separator'   => ',',
            'role_caps'            => array(),
            'inactive_days'       => '90',
            'dead_stock_days'     => '90',
            'dead_stock_max_sold' => '0',
            'delete_data_on_uninstall' => '',
        );
    }

    private static function create_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $saved = $wpdb->prefix . 'wcar_saved_reports';
        $exports = $wpdb->prefix . 'wcar_export_history';
        $scheduled = $wpdb->prefix . 'wcar_scheduled_reports';

        dbDelta( "CREATE TABLE {$saved} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            report_id VARCHAR(100) NOT NULL,
            filters LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY report_id (report_id)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$exports} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            report_id VARCHAR(100) NOT NULL,
            format VARCHAR(10) NOT NULL,
            filename VARCHAR(255) NOT NULL,
            filepath TEXT NOT NULL,
            filters LONGTEXT NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'ready',
            error_message TEXT NULL,
            created_at DATETIME NOT NULL,
            started_at DATETIME NULL,
            expires_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY report_id (report_id),
            KEY status (status),
            KEY started_at (started_at),
            KEY expires_at (expires_at)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$scheduled} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            report_id VARCHAR(100) NOT NULL,
            filters LONGTEXT NOT NULL,
            format VARCHAR(10) NOT NULL DEFAULT 'xlsx',
            recipients TEXT NOT NULL,
            cadence VARCHAR(20) NOT NULL,
            run_time VARCHAR(8) NOT NULL DEFAULT '08:00',
            active TINYINT(1) NOT NULL DEFAULT 1,
            action_id BIGINT UNSIGNED NULL,
            last_run DATETIME NULL,
            next_run DATETIME NULL,
            locked_at DATETIME NULL,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY active (active),
            KEY report_id (report_id),
            KEY next_run (next_run)
        ) {$charset};" );
    }
}
