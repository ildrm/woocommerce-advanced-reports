<?php
namespace WCAR\Export;

use WCAR\Calendar\Calendar;
use WCAR\Query\ReportFilter;
use WCAR\Reports\ReportEngine;
use WCAR\Security\Capabilities;

final class ExportManager {
    private ReportEngine $engine;
    public function __construct( ReportEngine $engine ) { $this->engine = $engine; }

    public function register(): void {
        add_action( 'admin_post_wcar_export', array( $this, 'handle_export' ) );
        add_action( 'admin_post_wcar_queue_export', array( $this, 'handle_queue' ) );
        add_action( 'wcar_generate_export_job', array( $this, 'process_queue' ) );
        add_action( 'wcar_cleanup_exports', array( $this, 'cleanup' ) );
        add_action( 'init', array( $this, 'ensure_cleanup' ) );
        add_action( 'admin_post_wcar_download_export', array( $this, 'download' ) );
        add_action( 'admin_post_wcar_delete_export', array( $this, 'delete' ) );
    }


    public function handle_queue(): void {
        if ( ! current_user_can( Capabilities::EXPORT ) ) { wp_die( esc_html__( 'Permission denied.', 'woocommerce-advanced-reports' ) ); }
        check_admin_referer( 'wcar_queue_export' );
        $report_id = sanitize_key( $_POST['report_id'] ?? '' );
        $format = in_array( $_POST['format'] ?? '', array( 'csv', 'xlsx' ), true ) ? $_POST['format'] : 'xlsx';
        $filters = json_decode( wp_unslash( $_POST['filters'] ?? '{}' ), true );
        if ( ! $this->engine->registry()->get( $report_id ) || ! is_array( $filters ) ) { wp_die( esc_html__( 'Invalid export request.', 'woocommerce-advanced-reports' ) ); }
        global $wpdb; $table = $wpdb->prefix . 'wcar_export_history';
        $wpdb->insert( $table, array( 'user_id'=>get_current_user_id(), 'report_id'=>$report_id, 'format'=>$format, 'filename'=>'', 'filepath'=>'', 'filters'=>wp_json_encode($filters), 'status'=>'queued', 'created_at'=>current_time('mysql'), 'expires_at'=>wp_date('Y-m-d H:i:s',time()+30*DAY_IN_SECONDS,wp_timezone()) ), array('%d','%s','%s','%s','%s','%s','%s','%s','%s') );
        $id=(int)$wpdb->insert_id;
        if ( function_exists( 'as_enqueue_async_action' ) ) { as_enqueue_async_action( 'wcar_generate_export_job', array( 'export_id'=>$id ), 'wcar', true ); }
        else { wp_schedule_single_event( time()+5, 'wcar_generate_export_job', array( $id ) ); }
        wp_safe_redirect( admin_url( 'admin.php?page=wcar-export-history' ) ); exit;
    }

    public function process_queue( $export_id ): void {
        $id=is_array($export_id)?absint($export_id['export_id']??0):absint($export_id); if(!$id)return;
        $row=$this->get_record($id); if(!$row||'queued'!==$row['status'])return;
        try {
            $raw=json_decode($row['filters'],true)?:array(); $filter=ReportFilter::from_request($raw,new Calendar());
            $this->generate_file($row['report_id'],$filter,$row['format'],(int)$row['user_id'],$raw);
            global $wpdb; $wpdb->delete($wpdb->prefix.'wcar_export_history',array('id'=>$id),array('%d'));
        } catch ( \Throwable $e ) { global $wpdb; $wpdb->update($wpdb->prefix.'wcar_export_history',array('status'=>'failed','error_message'=>$e->getMessage()),array('id'=>$id),array('%s','%s'),array('%d')); }
    }

    public function ensure_cleanup(): void {
        if ( ! wp_next_scheduled( 'wcar_cleanup_exports' ) ) { wp_schedule_event( time()+HOUR_IN_SECONDS, 'daily', 'wcar_cleanup_exports' ); }
    }

    public function cleanup(): void {
        global $wpdb; $table=$wpdb->prefix.'wcar_export_history'; $rows=$wpdb->get_results($wpdb->prepare("SELECT id,filepath FROM {$table} WHERE expires_at IS NOT NULL AND expires_at < %s",current_time('mysql')),ARRAY_A);
        foreach($rows as $row){ if(!empty($row['filepath'])&&is_file($row['filepath'])){@unlink($row['filepath']);} $wpdb->delete($table,array('id'=>(int)$row['id']),array('%d')); }
    }

    public function handle_export(): void {
        if ( ! current_user_can( Capabilities::EXPORT ) ) { wp_die( esc_html__( 'You do not have permission to export reports.', 'woocommerce-advanced-reports' ) ); }
        check_admin_referer( 'wcar_export' );
        $report_id = sanitize_key( $_GET['report_id'] ?? '' );
        $format = in_array( $_GET['format'] ?? '', array( 'csv', 'xlsx' ), true ) ? $_GET['format'] : 'xlsx';
        if ( ! $this->engine->registry()->get( $report_id ) ) { wp_die( esc_html__( 'Unknown report.', 'woocommerce-advanced-reports' ) ); }
        $filter = ReportFilter::from_request( $_GET, new Calendar() );
        try {
            $record = $this->generate_file( $report_id, $filter, $format, get_current_user_id(), $this->filter_request_data( $_GET ) );
            $this->serve( $record['filepath'], $record['filename'], $format );
        } catch ( \Throwable $e ) {
            wp_die( esc_html( $e->getMessage() ) );
        }
    }

    public function generate_file( string $report_id, ReportFilter $filter, string $format, int $user_id = 0, array $raw_filters = array() ): array {
        $definition = $this->engine->registry()->get( $report_id );
        if ( ! $definition ) { throw new \RuntimeException( 'Unknown report.' ); }
        $data = $this->engine->run( $report_id, $filter, false, true );
        if ( 'dashboard' === $report_id ) { $summary_report = $this->engine->run( 'sales-summary', $filter, false, true ); $data['columns'] = $summary_report['columns'] ?? array(); $data['rows'] = $summary_report['rows'] ?? array(); }
        $exporter = 'csv' === $format ? new CsvExporter() : new XlsxExporter();
        $dir = $this->private_dir();
        $token = wp_generate_password( 24, false, false );
        $filename = sanitize_file_name( $report_id . '-' . wp_date( 'Ymd-His' ) . '-' . $token . '.' . $exporter->extension() );
        $path = trailingslashit( $dir ) . $filename;
        $calendar = new Calendar();
        $meta = array(
            __( 'Date From', 'woocommerce-advanced-reports' ) => $calendar->format( $filter->from ),
            __( 'Date To', 'woocommerce-advanced-reports' ) => $calendar->format( $filter->to ),
            __( 'Generated', 'woocommerce-advanced-reports' ) => wp_date( 'Y-m-d H:i:s', null, wp_timezone() ),
            __( 'Site', 'woocommerce-advanced-reports' ) => get_bloginfo( 'name' ),
            __( 'Applied Filters', 'woocommerce-advanced-reports' ) => $raw_filters,
        );
        if ( ! empty( $data['summary'] ) ) { $meta[ __( 'Summary', 'woocommerce-advanced-reports' ) ] = $data['summary']; }
        $exporter->write_file( $path, (string) $definition['title'], $data['columns'] ?? array(), $data['rows'] ?? array(), $meta );

        global $wpdb;
        $table = $wpdb->prefix . 'wcar_export_history';
        $wpdb->insert( $table, array(
            'user_id' => $user_id, 'report_id' => $report_id, 'format' => $format, 'filename' => $filename, 'filepath' => $path,
            'filters' => wp_json_encode( $raw_filters ?: $filter->to_array() ), 'status' => 'ready', 'created_at' => current_time( 'mysql' ),
            'expires_at' => wp_date( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS, wp_timezone() ),
        ), array( '%d','%s','%s','%s','%s','%s','%s','%s','%s' ) );
        return array( 'id' => (int) $wpdb->insert_id, 'filepath' => $path, 'filename' => $filename, 'format' => $format );
    }

    public function history(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'wcar_export_history';
        $where = current_user_can( 'manage_options' ) ? '1=1' : $wpdb->prepare( 'user_id = %d', get_current_user_id() );
        return $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT 200", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public function download(): void {
        if ( ! current_user_can( Capabilities::EXPORT ) ) { wp_die( esc_html__( 'Permission denied.', 'woocommerce-advanced-reports' ) ); }
        $id = absint( $_GET['id'] ?? 0 ); check_admin_referer( 'wcar_download_export_' . $id );
        $row = $this->get_record( $id );
        if ( ! $row || ( ! current_user_can( 'manage_options' ) && (int) $row['user_id'] !== get_current_user_id() ) ) { wp_die( esc_html__( 'Export not found.', 'woocommerce-advanced-reports' ) ); }
        $this->serve( $row['filepath'], $row['filename'], $row['format'] );
    }

    public function delete(): void {
        if ( ! current_user_can( Capabilities::EXPORT ) ) { wp_die( esc_html__( 'Permission denied.', 'woocommerce-advanced-reports' ) ); }
        $id = absint( $_GET['id'] ?? 0 ); check_admin_referer( 'wcar_delete_export_' . $id );
        $row = $this->get_record( $id );
        if ( $row && ( current_user_can( 'manage_options' ) || (int) $row['user_id'] === get_current_user_id() ) ) {
            if ( is_file( $row['filepath'] ) ) { @unlink( $row['filepath'] ); }
            global $wpdb; $wpdb->delete( $wpdb->prefix . 'wcar_export_history', array( 'id' => $id ), array( '%d' ) );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=wcar-export-history' ) ); exit;
    }

    private function get_record( int $id ): ?array {
        global $wpdb; $table = $wpdb->prefix . 'wcar_export_history';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $id ), ARRAY_A );
        return $row ?: null;
    }

    private function private_dir(): string {
        $dir = WP_CONTENT_DIR . '/wcar-private';
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) { throw new \RuntimeException( 'Unable to create private export directory.' ); }
        if ( ! file_exists( $dir . '/index.php' ) ) { file_put_contents( $dir . '/index.php', "<?php\nhttp_response_code(403);\nexit;\n" ); }
        if ( ! file_exists( $dir . '/.htaccess' ) ) { file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); }
        return $dir;
    }

    private function filter_request_data( array $input ): array {
        $allowed = array( 'date_from','date_to','status','product','category','customer','country','payment_method','shipping_method','coupon','min_amount','max_amount','customer_type','currency','group_by','compare','per_page','inactive_days','dead_stock_days','dead_stock_max_sold' );
        $out = array();
        foreach ( $allowed as $key ) {
            if ( ! isset( $input[ $key ] ) ) { continue; }
            $value = wp_unslash( $input[ $key ] );
            $out[ $key ] = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : sanitize_text_field( $value );
        }
        return $out;
    }

    private function serve( string $path, string $filename, string $format ): void {
        if ( ! is_file( $path ) ) { wp_die( esc_html__( 'Export file is missing.', 'woocommerce-advanced-reports' ) ); }
        $mime = 'csv' === $format ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        nocache_headers(); header( 'Content-Type: ' . $mime ); header( 'Content-Disposition: attachment; filename="' . basename( $filename ) . '"' ); header( 'Content-Length: ' . filesize( $path ) );
        readfile( $path ); exit;
    }
}
