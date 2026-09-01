<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$wcar_cleanup_site = static function (): void {
    $settings = get_option( 'wcar_settings', array() );
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

    if ( empty( $settings['delete_data_on_uninstall'] ) ) { return; }

    global $wpdb;
    $cache_keys = (array) get_option( 'wcar_cache_keys', array() );
    foreach ( array_keys( $cache_keys ) as $cache_key ) { delete_transient( $cache_key ); }

    $private_dir = WP_CONTENT_DIR . '/wcar-private' . ( is_multisite() ? '/site-' . get_current_blog_id() : '' );
    if ( is_dir( $private_dir ) && ! is_link( $private_dir ) ) {
        foreach ( new DirectoryIterator( $private_dir ) as $file ) {
            if ( $file->isFile() || $file->isLink() ) { unlink( $file->getPathname() ); }
        }
        rmdir( $private_dir );
    }

    foreach ( array( 'wcar_saved_reports', 'wcar_export_history', 'wcar_scheduled_reports' ) as $suffix ) {
        $table = $wpdb->prefix . $suffix;
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }
    foreach ( array( 'wcar_settings', 'wcar_db_version', 'wcar_cache_keys', 'wcar_order_cache_version', 'wcar_report_cache_version' ) as $option ) {
        delete_option( $option );
    }

    $caps = array( 'wcar_reports_view', 'wcar_reports_products', 'wcar_reports_orders', 'wcar_reports_customers', 'wcar_reports_export', 'wcar_reports_print', 'wcar_reports_settings' );
    foreach ( array_keys( wp_roles()->roles ) as $role_name ) {
        $role = get_role( $role_name );
        if ( $role ) { foreach ( $caps as $cap ) { $role->remove_cap( $cap ); } }
    }
};

if ( is_multisite() ) {
    foreach ( get_sites( array( 'fields'=>'ids', 'number'=>0 ) ) as $site_id ) {
        switch_to_blog( (int) $site_id );
        $wcar_cleanup_site();
        restore_current_blog();
    }
} else {
    $wcar_cleanup_site();
}

// On multisite the base directory only contains protection files and per-site
// directories. Remove the base when every site directory was removed, while
// preserving it if any site's exports were intentionally retained.
$wcar_private_base = WP_CONTENT_DIR . '/wcar-private';
if ( is_dir( $wcar_private_base ) && ! is_link( $wcar_private_base ) ) {
    $wcar_base_is_empty = true;
    foreach ( new DirectoryIterator( $wcar_private_base ) as $wcar_entry ) {
        if ( $wcar_entry->isDot() || in_array( $wcar_entry->getFilename(), array( 'index.php', '.htaccess', 'web.config' ), true ) ) { continue; }
        $wcar_base_is_empty = false;
        break;
    }
    if ( $wcar_base_is_empty ) {
        foreach ( array( 'index.php', '.htaccess', 'web.config' ) as $wcar_protection_file ) {
            $wcar_protection_path = $wcar_private_base . '/' . $wcar_protection_file;
            if ( is_file( $wcar_protection_path ) || is_link( $wcar_protection_path ) ) { unlink( $wcar_protection_path ); }
        }
        rmdir( $wcar_private_base );
    }
}
