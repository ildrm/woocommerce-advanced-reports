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
        add_action( 'init', array( $this, 'ensure_jobs' ) );
        add_action( 'admin_post_wcar_download_export', array( $this, 'download' ) );
        add_action( 'admin_post_wcar_delete_export', array( $this, 'delete' ) );
    }


    public function handle_queue(): void {
        if ( ! current_user_can( Capabilities::EXPORT ) ) { wp_die( esc_html__( 'Permission denied.', 'woocommerce-advanced-reports' ) ); }
        check_admin_referer( 'wcar_queue_export', '_wcar_queue_nonce' );
        $report_id = isset( $_POST['report_id'] ) && is_scalar( $_POST['report_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['report_id'] ) ) : '';
        $format = in_array( $_POST['format'] ?? '', array( 'csv', 'xlsx' ), true ) ? $_POST['format'] : 'xlsx';
        $filters_json = isset( $_POST['filters'] ) && is_scalar( $_POST['filters'] ) ? wp_unslash( (string) $_POST['filters'] ) : '{}';
        $filters = json_decode( $filters_json, true );
        $definition = $this->engine->registry()->get( $report_id );
        if ( ! $definition || ! is_array( $filters ) || ! Capabilities::current_user_can_report( $definition ) ) { wp_die( esc_html__( 'Invalid export request.', 'woocommerce-advanced-reports' ) ); }
        $calendar = new Calendar();
        $filters = ReportFilter::from_storage( $filters, $calendar )->to_storage_array( $calendar );
        global $wpdb; $table = $wpdb->prefix . 'wcar_export_history';
        $inserted = $wpdb->insert( $table, array( 'user_id'=>get_current_user_id(), 'report_id'=>$report_id, 'format'=>$format, 'filename'=>'', 'filepath'=>'', 'filters'=>wp_json_encode($filters), 'status'=>'queued', 'created_at'=>current_time('mysql'), 'expires_at'=>wp_date('Y-m-d H:i:s',time()+30*DAY_IN_SECONDS,wp_timezone()) ), array('%d','%s','%s','%s','%s','%s','%s','%s','%s') );
        $id=(int)$wpdb->insert_id;
        if ( false === $inserted || ! $id ) { wp_die( esc_html__( 'The export job could not be stored.', 'woocommerce-advanced-reports' ) ); }
        if ( ! $this->queue_job( $id ) ) {
            $wpdb->update( $table, array( 'status'=>'failed', 'error_message'=>__( 'The background job could not be scheduled.', 'woocommerce-advanced-reports' ) ), array( 'id'=>$id ), array( '%s','%s' ), array( '%d' ) );
            wp_die( esc_html__( 'The background export could not be scheduled.', 'woocommerce-advanced-reports' ) );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=wcar-export-history' ) ); exit;
    }

    public function process_queue( $export_id ): void {
        $id=is_array($export_id)?absint($export_id['export_id']??0):absint($export_id); if(!$id)return;
        $row=$this->get_record($id); if(!$row||'queued'!==$row['status'])return;
        $definition = $this->engine->registry()->get( $row['report_id'] );
        if ( ! $definition || ! user_can( (int) $row['user_id'], Capabilities::EXPORT ) || ! Capabilities::user_can_report( (int) $row['user_id'], $definition ) ) {
            global $wpdb;
            $wpdb->update( $wpdb->prefix.'wcar_export_history', array( 'status'=>'failed', 'error_message'=>__( 'The export owner no longer has permission to run this report.', 'woocommerce-advanced-reports' ) ), array( 'id'=>$id, 'status'=>'queued' ), array( '%s','%s' ), array( '%d','%s' ) );
            return;
        }
        global $wpdb;
        $claimed = $wpdb->update( $wpdb->prefix.'wcar_export_history', array( 'status'=>'processing', 'started_at'=>current_time( 'mysql' ), 'error_message'=>'' ), array( 'id'=>$id, 'status'=>'queued' ), array( '%s','%s','%s' ), array( '%d','%s' ) );
        if ( 1 !== $claimed ) { return; }
        try {
            $raw=json_decode($row['filters'],true)?:array(); $calendar=new Calendar(); $filter=ReportFilter::from_storage($raw,$calendar);
            $this->generate_file($row['report_id'],$filter,$row['format'],(int)$row['user_id'],$filter->to_request_array($calendar),$id);
        } catch ( \Throwable $e ) { $wpdb->update($wpdb->prefix.'wcar_export_history',array('status'=>'failed','started_at'=>null,'error_message'=>substr($e->getMessage(),0,65535)),array('id'=>$id),array('%s','%s','%s'),array('%d')); }
    }

    public function ensure_jobs(): void {
        if ( ! wp_next_scheduled( 'wcar_cleanup_exports' ) ) { wp_schedule_event( time()+HOUR_IN_SECONDS, 'daily', 'wcar_cleanup_exports' ); }
        if ( get_transient( 'wcar_export_reconcile_lock' ) ) { return; }
        set_transient( 'wcar_export_reconcile_lock', 1, MINUTE_IN_SECONDS );
        global $wpdb;
        $table = $wpdb->prefix . 'wcar_export_history';
        $stale = wp_date( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS, wp_timezone() );
        $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status='queued', started_at=NULL WHERE status='processing' AND ((started_at IS NOT NULL AND started_at<%s) OR (started_at IS NULL AND created_at<%s))", $stale, $stale ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_col( "SELECT id FROM {$table} WHERE status='queued' ORDER BY id ASC LIMIT 50" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        foreach ( $rows as $id ) { $this->queue_job( (int) $id ); }
    }

    public function cleanup(): void {
        global $wpdb; $table=$wpdb->prefix.'wcar_export_history'; $rows=$wpdb->get_results($wpdb->prepare("SELECT id,filepath FROM {$table} WHERE expires_at IS NOT NULL AND expires_at < %s",current_time('mysql')),ARRAY_A);
        foreach($rows as $row){
            $path = (string) $row['filepath'];
            $private_path = $path ? $this->validated_private_file( $path ) : null;
            $removed = ! $path || ! is_file( $path ) || ! $private_path || unlink( $private_path );
            if ( $removed ) { $wpdb->delete($table,array('id'=>(int)$row['id']),array('%d')); }
        }
    }

    public function handle_export(): void {
        if ( ! current_user_can( Capabilities::EXPORT ) ) { wp_die( esc_html__( 'You do not have permission to export reports.', 'woocommerce-advanced-reports' ) ); }
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( esc_html__( 'Invalid export request method.', 'woocommerce-advanced-reports' ) ); }
        $nonce_field = isset( $_POST['_wcar_export_nonce_csv'] ) ? '_wcar_export_nonce_csv' : ( isset( $_POST['_wcar_export_nonce_xlsx'] ) ? '_wcar_export_nonce_xlsx' : '' );
        if ( ! $nonce_field ) { wp_die( esc_html__( 'Invalid export request.', 'woocommerce-advanced-reports' ) ); }
        check_admin_referer( 'wcar_export', $nonce_field );
        $request = $_POST;
        $report_id = isset( $request['report_id'] ) && is_scalar( $request['report_id'] ) ? sanitize_key( wp_unslash( (string) $request['report_id'] ) ) : '';
        $format = in_array( $request['format'] ?? '', array( 'csv', 'xlsx' ), true ) ? $request['format'] : 'xlsx';
        $definition = $this->engine->registry()->get( $report_id );
        if ( ! $definition || ! Capabilities::current_user_can_report( $definition ) ) { wp_die( esc_html__( 'Unknown report.', 'woocommerce-advanced-reports' ) ); }
        $filters_json = isset( $request['filters'] ) && is_scalar( $request['filters'] ) ? wp_unslash( (string) $request['filters'] ) : '';
        $filters = $filters_json ? json_decode( $filters_json, true ) : null;
        $filter = is_array( $filters ) ? ReportFilter::from_storage( $filters, new Calendar() ) : ReportFilter::from_request( $request, new Calendar() );
        try {
            $record = $this->generate_file( $report_id, $filter, $format, get_current_user_id(), $filter->to_request_array( new Calendar() ) );
            $this->serve( $record['filepath'], $record['filename'], $format );
        } catch ( \Throwable $e ) {
            wp_die( esc_html( $e->getMessage() ) );
        }
    }

    public function generate_file( string $report_id, ReportFilter $filter, string $format, int $user_id = 0, array $raw_filters = array(), int $history_id = 0 ): array {
        $definition = $this->engine->registry()->get( $report_id );
        if ( ! $definition ) { throw new \RuntimeException( 'Unknown report.' ); }
        if ( ! in_array( $format, array( 'csv', 'xlsx' ), true ) ) { throw new \RuntimeException( 'Unsupported export format.' ); }
        $data = $this->engine->run( $report_id, $filter, false, true );
        if ( ! empty( $data['error'] ) ) { throw new \RuntimeException( (string) $data['error'] ); }
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
        try {
            $exporter->write_file( $path, (string) $definition['title'], $data['columns'] ?? array(), $data['rows'] ?? array(), $meta );
            global $wpdb;
            $table = $wpdb->prefix . 'wcar_export_history';
            $record = array(
                'user_id' => $user_id, 'report_id' => $report_id, 'format' => $format, 'filename' => $filename, 'filepath' => $path,
                'filters' => wp_json_encode( $raw_filters ?: $filter->to_request_array( $calendar ) ), 'status' => 'ready', 'started_at' => null, 'error_message' => '',
                'expires_at' => wp_date( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS, wp_timezone() ),
            );
            if ( $history_id ) {
                $stored = $wpdb->update( $table, $record, array( 'id'=>$history_id, 'user_id'=>$user_id, 'status'=>'processing' ), array( '%d','%s','%s','%s','%s','%s','%s','%s','%s','%s' ), array( '%d','%d','%s' ) );
                if ( 1 !== $stored ) { throw new \RuntimeException( 'The queued export record could not be completed.' ); }
            } else {
                $record['created_at'] = current_time( 'mysql' );
                $stored = $wpdb->insert( $table, $record, array( '%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s' ) );
                $history_id = (int) $wpdb->insert_id;
                if ( false === $stored || ! $history_id ) { throw new \RuntimeException( 'The export history record could not be stored.' ); }
            }
            return array( 'id' => $history_id, 'filepath' => $path, 'filename' => $filename, 'format' => $format );
        } catch ( \Throwable $e ) {
            if ( is_file( $path ) ) { unlink( $path ); }
            throw $e;
        }
    }

    public function history(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'wcar_export_history';
        $where = current_user_can( 'manage_options' ) ? '1=1' : $wpdb->prepare( 'user_id = %d', get_current_user_id() );
        return $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT 200", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public function download(): void {
        if ( ! current_user_can( Capabilities::EXPORT ) ) { wp_die( esc_html__( 'Permission denied.', 'woocommerce-advanced-reports' ) ); }
        $id = isset( $_GET['id'] ) && is_scalar( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; check_admin_referer( 'wcar_download_export_' . $id );
        $row = $this->get_record( $id );
        $expired = $row && ! empty( $row['expires_at'] ) && $row['expires_at'] < current_time( 'mysql' );
        $definition = $row ? $this->engine->registry()->get( (string) $row['report_id'] ) : null;
        if ( ! $row || ! $definition || ! Capabilities::current_user_can_report( $definition ) || 'ready' !== $row['status'] || $expired || ( ! current_user_can( 'manage_options' ) && (int) $row['user_id'] !== get_current_user_id() ) ) { wp_die( esc_html__( 'Export not found.', 'woocommerce-advanced-reports' ) ); }
        $this->serve( $row['filepath'], $row['filename'], $row['format'] );
    }

    public function delete(): void {
        if ( ! current_user_can( Capabilities::EXPORT ) ) { wp_die( esc_html__( 'Permission denied.', 'woocommerce-advanced-reports' ) ); }
        $id = isset( $_POST['id'] ) && is_scalar( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; check_admin_referer( 'wcar_delete_export_' . $id, '_wcar_delete_nonce_' . $id );
        $row = $this->get_record( $id );
        if ( $row && ( current_user_can( 'manage_options' ) || (int) $row['user_id'] === get_current_user_id() ) ) {
            $path = (string) $row['filepath'];
            $private_path = $path ? $this->validated_private_file( $path ) : null;
            if ( $private_path && ! unlink( $private_path ) ) { wp_die( esc_html__( 'The export file could not be deleted.', 'woocommerce-advanced-reports' ) ); }
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
        $base = WP_CONTENT_DIR . '/wcar-private';
        $dir = is_multisite() ? $base . '/site-' . get_current_blog_id() : $base;
        if ( is_link( $base ) || is_link( $dir ) ) { throw new \RuntimeException( 'The private export directory cannot be a symbolic link.' ); }
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) { throw new \RuntimeException( 'Unable to create private export directory.' ); }
        $protection = array(
            'index.php' => "<?php\nhttp_response_code(403);\nexit;\n",
            '.htaccess' => "Require all denied\nDeny from all\n",
            'web.config' => '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
        );
        foreach ( array_unique( array( $base, $dir ) ) as $protected_dir ) {
            foreach ( $protection as $filename => $contents ) {
                $target = $protected_dir . '/' . $filename;
                if ( is_link( $target ) || strlen( $contents ) !== file_put_contents( $target, $contents, LOCK_EX ) ) { throw new \RuntimeException( 'Unable to protect the private export directory.' ); }
            }
        }
        return $dir;
    }

    private function queue_job( int $id ): bool {
        $args = array( 'export_id'=>$id );
        if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'wcar_generate_export_job', $args, 'wcar' ) ) { return true; }
        if ( function_exists( 'as_enqueue_async_action' ) && (int) as_enqueue_async_action( 'wcar_generate_export_job', $args, 'wcar', true ) > 0 ) { return true; }
        if ( wp_next_scheduled( 'wcar_generate_export_job', array( $id ) ) ) { return true; }
        return true === wp_schedule_single_event( time()+5, 'wcar_generate_export_job', array( $id ), true );
    }

    private function serve( string $path, string $filename, string $format ): void {
        $real = $this->validated_private_file( $path );
        if ( ! $real ) { wp_die( esc_html__( 'Export file is missing.', 'woocommerce-advanced-reports' ) ); }
        $mime = 'csv' === $format ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        nocache_headers(); header( 'Content-Type: ' . $mime ); header( 'X-Content-Type-Options: nosniff' ); header( 'Content-Disposition: attachment; filename="' . basename( $filename ) . '"' ); header( 'Content-Length: ' . filesize( $real ) );
        readfile( $real ); exit;
    }

    private function validated_private_file( string $path ): ?string {
        $directory = WP_CONTENT_DIR . '/wcar-private' . ( is_multisite() ? '/site-' . get_current_blog_id() : '' );
        if ( is_link( WP_CONTENT_DIR . '/wcar-private' ) || is_link( $directory ) ) { return null; }
        $private = realpath( $directory );
        $real = realpath( $path );
        if ( ! $private || ! $real || ! is_file( $real ) ) { return null; }
        $prefix = rtrim( wp_normalize_path( $private ), '/' ) . '/';
        $candidate = wp_normalize_path( $real );
        if ( '\\' === DIRECTORY_SEPARATOR ) { $prefix = strtolower( $prefix ); $candidate = strtolower( $candidate ); }
        return str_starts_with( $candidate, $prefix ) ? $real : null;
    }
}
