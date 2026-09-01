<?php
namespace WCAR\Schedule;

use WCAR\Calendar\Calendar;
use WCAR\Export\ExportManager;
use WCAR\Query\ReportFilter;
use WCAR\Reports\ReportEngine;
use WCAR\Security\Capabilities;

final class ScheduledReports {
    private ReportEngine $engine;
    public function __construct( ReportEngine $engine ) { $this->engine = $engine; }

    public function register(): void {
        add_action( 'admin_post_wcar_create_schedule', array( $this, 'create' ) );
        add_action( 'admin_post_wcar_delete_schedule', array( $this, 'delete' ) );
        add_action( 'wcar_run_scheduled_report', array( $this, 'run' ) );
        add_action( 'wcar_fallback_schedule_runner', array( $this, 'run_due' ) );
        add_action( 'init', array( $this, 'ensure_runner' ) );
    }

    public function ensure_runner(): void {
        if ( ! wp_next_scheduled( 'wcar_fallback_schedule_runner' ) ) { wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'wcar_fallback_schedule_runner' ); }
        if ( ! function_exists( 'as_schedule_single_action' ) || get_transient( 'wcar_schedule_reconcile_lock' ) ) { return; }
        set_transient( 'wcar_schedule_reconcile_lock', 1, 5 * MINUTE_IN_SECONDS );
        global $wpdb;
        $table = $wpdb->prefix . 'wcar_scheduled_reports';
        $cursor = (int) get_transient( 'wcar_schedule_reconcile_cursor' );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,cadence,run_time,next_run FROM {$table} WHERE active=1 AND id>%d ORDER BY id ASC LIMIT 500", $cursor ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( ! $rows ) { set_transient( 'wcar_schedule_reconcile_cursor', 0, DAY_IN_SECONDS ); return; }
        foreach ( $rows as $row ) {
            $id = (int) $row['id'];
            $args = array( 'schedule_id' => $id );
            if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'wcar_run_scheduled_report', $args, 'wcar' ) ) { continue; }
            try {
                if ( '' === trim( (string) $row['next_run'] ) ) { throw new \RuntimeException( 'Missing next occurrence.' ); }
                $stored = new \DateTimeImmutable( (string) $row['next_run'], wp_timezone() );
                $next = max( time() + 5, $stored->getTimestamp() );
            } catch ( \Exception $e ) {
                $next = $this->next_timestamp( (string) $row['cadence'], (string) $row['run_time'] );
                $repaired = $wpdb->update( $table, array( 'next_run'=>wp_date( 'Y-m-d H:i:s', $next, wp_timezone() ), 'last_error'=>__( 'An invalid next occurrence was repaired automatically.', 'woocommerce-advanced-reports' ), 'updated_at'=>current_time( 'mysql' ) ), array( 'id'=>$id ), array( '%s','%s','%s' ), array( '%d' ) );
                if ( false === $repaired ) { continue; }
            }
            $action_id = $this->schedule_action( $id, $next );
            if ( $action_id ) { $wpdb->update( $table, array( 'action_id' => $action_id ), array( 'id' => $id ), array( '%d' ), array( '%d' ) ); }
        }
        set_transient( 'wcar_schedule_reconcile_cursor', count( $rows ) >= 500 ? (int) end( $rows )['id'] : 0, DAY_IN_SECONDS );
    }

    public function create(): void {
        if ( ! current_user_can( Capabilities::EXPORT ) ) { wp_die( esc_html__( 'Permission denied.', 'woocommerce-advanced-reports' ) ); }
        check_admin_referer( 'wcar_create_schedule', '_wcar_schedule_nonce' );
        $report_id = isset( $_POST['report_id'] ) && is_scalar( $_POST['report_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['report_id'] ) ) : '';
        $definition = $this->engine->registry()->get( $report_id );
        if ( ! $definition || ! Capabilities::current_user_can_report( $definition ) ) { wp_die( esc_html__( 'Unknown report.', 'woocommerce-advanced-reports' ) ); }
        $name = isset( $_POST['name'] ) && is_scalar( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '';
        $name = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 190 ) : substr( $name, 0, 190 );
        $format = in_array( $_POST['format'] ?? '', array( 'csv', 'xlsx' ), true ) ? $_POST['format'] : 'xlsx';
        $cadence = in_array( $_POST['cadence'] ?? '', array( 'daily', 'weekly', 'monthly' ), true ) ? $_POST['cadence'] : 'weekly';
        $run_time_raw = isset( $_POST['run_time'] ) && is_scalar( $_POST['run_time'] ) ? wp_unslash( (string) $_POST['run_time'] ) : '';
        $run_time = preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $run_time_raw ) ? $run_time_raw : '08:00';
        $recipients_raw = isset( $_POST['recipients'] ) && is_scalar( $_POST['recipients'] ) ? wp_unslash( (string) $_POST['recipients'] ) : '';
        $recipients = array_slice( array_values( array_unique( array_filter( array_map( 'sanitize_email', preg_split( '/[,;\s]+/', $recipients_raw ) ), 'is_email' ) ) ), 0, 50 );
        $filters_json = isset( $_POST['filters'] ) && is_scalar( $_POST['filters'] ) ? wp_unslash( (string) $_POST['filters'] ) : '{}';
        $filters = json_decode( $filters_json, true );
        if ( ! is_array( $filters ) ) { $filters = array(); }
        $calendar = new Calendar();
        $filters = ReportFilter::from_storage( $filters, $calendar )->to_storage_array( $calendar );
        $filters['period_mode'] = in_array( $_POST['period_mode'] ?? '', array( 'rolling', 'fixed' ), true ) ? $_POST['period_mode'] : 'rolling';
        if ( ! $name || ! $recipients ) { wp_die( esc_html__( 'A schedule name and at least one recipient are required.', 'woocommerce-advanced-reports' ) ); }

        global $wpdb; $table = $wpdb->prefix . 'wcar_scheduled_reports'; $now = current_time( 'mysql' );
        $next = $this->next_timestamp( $cadence, $run_time );
        $inserted = $wpdb->insert( $table, array(
            'user_id' => get_current_user_id(), 'name' => $name, 'report_id' => $report_id, 'filters' => wp_json_encode( $filters ), 'format' => $format,
            'recipients' => implode( ',', $recipients ), 'cadence' => $cadence, 'run_time' => $run_time, 'active' => 1,
            'next_run' => wp_date( 'Y-m-d H:i:s', $next, wp_timezone() ), 'created_at' => $now, 'updated_at' => $now,
        ), array( '%d','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s' ) );
        $id = (int) $wpdb->insert_id;
        if ( false === $inserted || ! $id ) { wp_die( esc_html__( 'The schedule could not be stored.', 'woocommerce-advanced-reports' ) ); }
        $action_id = $this->schedule_action( $id, $next );
        if ( $action_id ) { $wpdb->update( $table, array( 'action_id' => $action_id ), array( 'id' => $id ), array( '%d' ), array( '%d' ) ); }
        wp_safe_redirect( admin_url( 'admin.php?page=wcar-scheduled-reports' ) ); exit;
    }

    public function delete(): void {
        if ( ! current_user_can( Capabilities::EXPORT ) ) { wp_die( esc_html__( 'Permission denied.', 'woocommerce-advanced-reports' ) ); }
        $id = isset( $_POST['id'] ) && is_scalar( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; check_admin_referer( 'wcar_delete_schedule_' . $id, '_wcar_delete_nonce_' . $id );
        global $wpdb; $table = $wpdb->prefix . 'wcar_scheduled_reports';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $id ), ARRAY_A );
        if ( $row && ( current_user_can( 'manage_options' ) || (int) $row['user_id'] === get_current_user_id() ) ) {
            if ( function_exists( 'as_unschedule_all_actions' ) ) { as_unschedule_all_actions( 'wcar_run_scheduled_report', array( 'schedule_id' => $id ), 'wcar' ); }
            $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=wcar-scheduled-reports' ) ); exit;
    }

    public function run( $schedule_id ): void {
        $id = is_array( $schedule_id ) ? absint( $schedule_id['schedule_id'] ?? 0 ) : absint( $schedule_id );
        if ( ! $id ) { return; }
        global $wpdb; $table = $wpdb->prefix . 'wcar_scheduled_reports';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d AND active=1", $id ), ARRAY_A );
        if ( ! $row ) { return; }
        $definition = $this->engine->registry()->get( $row['report_id'] );
        if ( ! $definition || ! user_can( (int) $row['user_id'], Capabilities::EXPORT ) || ! Capabilities::user_can_report( (int) $row['user_id'], $definition ) ) {
            $wpdb->update( $table, array( 'active'=>0, 'last_error'=>__( 'The schedule owner no longer has permission to run this report.', 'woocommerce-advanced-reports' ), 'updated_at'=>current_time( 'mysql' ) ), array( 'id'=>$id ), array( '%d','%s','%s' ), array( '%d' ) );
            return;
        }
        $now = current_time( 'mysql' );
        $stale = wp_date( 'Y-m-d H:i:s', time() - 6 * HOUR_IN_SECONDS, wp_timezone() );
        $claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET locked_at=%s WHERE id=%d AND active=1 AND next_run<=%s AND (locked_at IS NULL OR locked_at='' OR locked_at<%s)", $now, $id, $now, $stale ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( 1 !== $claimed ) { return; }
        try {
            $filter = $this->filter_for_run( $row );
            $manager = new ExportManager( $this->engine );
            $record = $manager->generate_file( $row['report_id'], $filter, $row['format'], (int) $row['user_id'], $filter->to_request_array( new Calendar() ) );
            $subject = sprintf( __( '[%1$s] Scheduled report: %2$s', 'woocommerce-advanced-reports' ), get_bloginfo( 'name' ), $definition['title'] ?? $row['report_id'] );
            $body = sprintf( __( "Your scheduled WooCommerce report is attached.\n\nSchedule: %s\nGenerated: %s", 'woocommerce-advanced-reports' ), $row['name'], wp_date( 'Y-m-d H:i:s', null, wp_timezone() ) );
            $next = $this->next_timestamp( $row['cadence'], $row['run_time'] );
            $advanced = $wpdb->update( $table, array( 'last_run' => current_time( 'mysql' ), 'next_run' => wp_date( 'Y-m-d H:i:s', $next, wp_timezone() ), 'last_error'=>'', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ), array( '%s','%s','%s','%s' ), array( '%d' ) );
            if ( false === $advanced ) { throw new \RuntimeException( __( 'The next schedule occurrence could not be stored.', 'woocommerce-advanced-reports' ) ); }
            if ( ! wp_mail( array_map( 'trim', explode( ',', $row['recipients'] ) ), $subject, $body, array(), array( $record['filepath'] ) ) ) { throw new \RuntimeException( __( 'WordPress could not send the scheduled report email.', 'woocommerce-advanced-reports' ) ); }
            $action_id = $this->schedule_action( $id, $next, true );
            $wpdb->update( $table, array( 'action_id'=>$action_id, 'locked_at'=>'', 'last_error'=>'', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ), array( '%d','%s','%s','%s' ), array( '%d' ) );
        } catch ( \Throwable $e ) {
            $wpdb->update( $table, array( 'locked_at'=>'', 'last_error'=>substr( $e->getMessage(), 0, 65535 ), 'updated_at'=>current_time( 'mysql' ) ), array( 'id'=>$id ), array( '%s','%s','%s' ), array( '%d' ) );
            error_log( 'WCAR scheduled report #' . $id . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }

    public function run_due(): void {
        global $wpdb; $table = $wpdb->prefix . 'wcar_scheduled_reports';
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$table} WHERE active=1 AND next_run <= %s ORDER BY next_run ASC LIMIT 50", current_time( 'mysql' ) ), ARRAY_A );
        foreach ( $rows as $row ) { $this->run( (int) $row['id'] ); }
    }

    public function all(): array {
        global $wpdb; $table = $wpdb->prefix . 'wcar_scheduled_reports';
        if ( current_user_can( 'manage_options' ) ) { return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 200", ARRAY_A ); }
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id=%d ORDER BY id DESC LIMIT 200", get_current_user_id() ), ARRAY_A );
    }

    private function schedule_action( int $id, int $next, bool $allow_during_run = false ): int {
        if ( ! function_exists( 'as_schedule_single_action' ) ) { return 0; }
        $args = array( 'schedule_id' => $id );
        if ( ! $allow_during_run && function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'wcar_run_scheduled_report', $args, 'wcar' ) ) { return 0; }
        return (int) as_schedule_single_action( $next, 'wcar_run_scheduled_report', $args, 'wcar', ! $allow_during_run );
    }

    private function next_timestamp( string $cadence, string $run_time ): int {
        if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $run_time ) ) { $run_time = '08:00'; }
        [ $h, $m ] = array_map( 'intval', explode( ':', $run_time ) );
        $now = new \DateTimeImmutable( 'now', wp_timezone() );
        $candidate = $now->setTime( $h, $m );
        if ( 'monthly' === $cadence ) { $candidate = $now->modify( 'first day of this month' )->setTime( $h, $m ); if ( $candidate <= $now ) { $candidate = $candidate->modify( 'first day of next month' ); } }
        elseif ( 'weekly' === $cadence ) { $days = ( 1 - (int) $now->format( 'w' ) + 7 ) % 7; $candidate = $now->modify( '+' . $days . ' days' )->setTime( $h, $m ); if ( $candidate <= $now ) { $candidate = $candidate->modify( '+7 days' ); } }
        elseif ( $candidate <= $now ) { $candidate = $candidate->modify( '+1 day' ); }
        return $candidate->getTimestamp();
    }

    private function filter_for_run( array $row ): ReportFilter {
        $raw = json_decode( $row['filters'], true ) ?: array();
        $period_mode = $raw['period_mode'] ?? 'rolling'; unset( $raw['period_mode'] );
        if ( 'fixed' === $period_mode ) { return ReportFilter::from_storage( $raw, new Calendar() ); }
        unset( $raw['date_from'], $raw['date_to'] );
        $f = ReportFilter::from_storage( $raw, new Calendar() );
        $today = new \DateTimeImmutable( 'today', wp_timezone() );
        if ( 'daily' === $row['cadence'] ) { $f->from = $today->modify( '-1 day' ); $f->to = $f->from->setTime( 23, 59, 59 ); }
        elseif ( 'weekly' === $row['cadence'] ) { $f->to = $today->modify( '-1 second' ); $f->from = $today->modify( '-7 days' ); }
        else {
            $calendar = new Calendar();
            if ( 'jalali' === $calendar->type() ) {
                [ $jy, $jm ] = Calendar::gregorian_to_jalali( (int) $today->format( 'Y' ), (int) $today->format( 'n' ), (int) $today->format( 'j' ) );
                $previous_year = 1 === $jm ? $jy - 1 : $jy;
                $previous_month = 1 === $jm ? 12 : $jm - 1;
                [ $from_year, $from_month, $from_day ] = Calendar::jalali_to_gregorian( $previous_year, $previous_month, 1 );
                [ $to_year, $to_month, $to_day ] = Calendar::jalali_to_gregorian( $jy, $jm, 1 );
                $f->from = new \DateTimeImmutable( sprintf( '%04d-%02d-%02d 00:00:00', $from_year, $from_month, $from_day ), wp_timezone() );
                $f->to = ( new \DateTimeImmutable( sprintf( '%04d-%02d-%02d 00:00:00', $to_year, $to_month, $to_day ), wp_timezone() ) )->modify( '-1 second' );
            } else {
                $f->from = $today->modify( 'first day of last month' );
                $f->to = $today->modify( 'first day of this month' )->modify( '-1 second' );
            }
        }
        return $f;
    }
}
