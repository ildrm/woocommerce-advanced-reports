<?php
namespace WCAR\Saved;

use WCAR\Calendar\Calendar;
use WCAR\Query\ReportFilter;
use WCAR\Reports\ReportRegistry;
use WCAR\Security\Capabilities;

final class SavedReports {
    public function register(): void {
        add_action( 'admin_post_wcar_save_report', array( $this, 'save' ) );
        add_action( 'admin_post_wcar_delete_saved_report', array( $this, 'delete' ) );
    }

    public function save(): void {
        if ( ! current_user_can( Capabilities::VIEW ) ) { wp_die( esc_html__( 'Permission denied.', 'woocommerce-advanced-reports' ) ); }
        check_admin_referer( 'wcar_save_report', '_wcar_save_nonce' );
        $name = isset( $_POST['name'] ) && is_scalar( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '';
        $name = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 190 ) : substr( $name, 0, 190 );
        $report_id = isset( $_POST['report_id'] ) && is_scalar( $_POST['report_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['report_id'] ) ) : '';
        $filters_json = isset( $_POST['filters'] ) && is_scalar( $_POST['filters'] ) ? wp_unslash( (string) $_POST['filters'] ) : '{}';
        $filters = json_decode( $filters_json, true );
        $definition = ( new ReportRegistry() )->get( $report_id );
        if ( ! $name || ! is_array( $filters ) || ! $definition || ! Capabilities::current_user_can_report( $definition ) ) { wp_die( esc_html__( 'Invalid saved report.', 'woocommerce-advanced-reports' ) ); }
        $calendar = new Calendar();
        $filters = ReportFilter::from_storage( $filters, $calendar )->to_storage_array( $calendar );
        global $wpdb; $table = $wpdb->prefix . 'wcar_saved_reports'; $now = current_time( 'mysql' );
        $inserted = $wpdb->insert( $table, array( 'user_id' => get_current_user_id(), 'name' => $name, 'report_id' => $report_id, 'filters' => wp_json_encode( $filters ), 'created_at' => $now, 'updated_at' => $now ), array( '%d','%s','%s','%s','%s','%s' ) );
        if ( false === $inserted ) { wp_die( esc_html__( 'The saved report could not be stored.', 'woocommerce-advanced-reports' ) ); }
        wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=wcar-saved-reports' ) ); exit;
    }

    public function delete(): void {
        if ( ! current_user_can( Capabilities::VIEW ) ) { wp_die( esc_html__( 'Permission denied.', 'woocommerce-advanced-reports' ) ); }
        $id = isset( $_POST['id'] ) && is_scalar( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; check_admin_referer( 'wcar_delete_saved_' . $id, '_wcar_delete_nonce_' . $id );
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

    public function find( int $id ): ?array {
        if ( $id <= 0 ) { return null; }
        global $wpdb; $table = $wpdb->prefix . 'wcar_saved_reports';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $id ), ARRAY_A );
        if ( ! $row || ( ! current_user_can( 'manage_options' ) && (int) $row['user_id'] !== get_current_user_id() ) ) { return null; }
        return $row;
    }
}
