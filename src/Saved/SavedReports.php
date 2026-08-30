<?php
namespace WCAR\Saved;

use WCAR\Security\Capabilities;

final class SavedReports {
    public function register(): void {
        add_action( 'admin_post_wcar_save_report', array( $this, 'save' ) );
        add_action( 'admin_post_wcar_delete_saved_report', array( $this, 'delete' ) );
    }

    public function save(): void {
        if ( ! current_user_can( Capabilities::VIEW ) ) { wp_die( esc_html__( 'Permission denied.', 'woocommerce-advanced-reports' ) ); }
        check_admin_referer( 'wcar_save_report' );
        $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        $report_id = sanitize_key( $_POST['report_id'] ?? '' );
        $filters = json_decode( wp_unslash( $_POST['filters'] ?? '{}' ), true );
        if ( ! $name || ! is_array( $filters ) ) { wp_die( esc_html__( 'Invalid saved report.', 'woocommerce-advanced-reports' ) ); }
        global $wpdb; $table = $wpdb->prefix . 'wcar_saved_reports'; $now = current_time( 'mysql' );
        $wpdb->insert( $table, array( 'user_id' => get_current_user_id(), 'name' => $name, 'report_id' => $report_id, 'filters' => wp_json_encode( $filters ), 'created_at' => $now, 'updated_at' => $now ), array( '%d','%s','%s','%s','%s','%s' ) );
        wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=wcar-saved-reports' ) ); exit;
    }

    public function delete(): void {
        $id = absint( $_GET['id'] ?? 0 ); check_admin_referer( 'wcar_delete_saved_' . $id );
        global $wpdb; $table = $wpdb->prefix . 'wcar_saved_reports';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $id ), ARRAY_A );
        if ( $row && ( current_user_can( 'manage_options' ) || (int) $row['user_id'] === get_current_user_id() ) ) { $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) ); }
        wp_safe_redirect( admin_url( 'admin.php?page=wcar-saved-reports' ) ); exit;
    }

    public function all(): array {
        global $wpdb; $table = $wpdb->prefix . 'wcar_saved_reports';
        if ( current_user_can( 'manage_options' ) ) { return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 200", ARRAY_A ); }
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id=%d ORDER BY id DESC LIMIT 200", get_current_user_id() ), ARRAY_A );
    }
}
