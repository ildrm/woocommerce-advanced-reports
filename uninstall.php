<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$settings = get_option( 'wcar_settings', array() );
if ( empty( $settings['delete_data_on_uninstall'] ) ) {
    return;
}

global $wpdb;
foreach ( array( 'wcar_saved_reports', 'wcar_export_history', 'wcar_scheduled_reports' ) as $suffix ) {
    $table = $wpdb->prefix . $suffix;
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}
delete_option( 'wcar_settings' );
delete_option( 'wcar_db_version' );
$caps = array( 'wcar_reports_view', 'wcar_reports_products', 'wcar_reports_orders', 'wcar_reports_customers', 'wcar_reports_export', 'wcar_reports_print', 'wcar_reports_settings' );
foreach ( array_keys( wp_roles()->roles ) as $role_name ) {
    $role = get_role( $role_name );
    if ( $role ) { foreach ( $caps as $cap ) { $role->remove_cap( $cap ); } }
}
