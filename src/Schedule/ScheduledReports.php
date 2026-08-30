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
        if ( function_exists( 'as_schedule_recurring_action' ) ) { return; }
        if ( ! wp_next_scheduled( 'wcar_fallback_schedule_runner' ) ) { wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'wcar_fallback_schedule_runner' ); }
    }

    public function create(): void {
        if ( ! current_user_can( Capabilities::EXPORT ) ) { wp_die( esc_html__( 'Permission denied.', 'woocommerce-advanced-reports' ) ); }
        check_admin_referer( 'wcar_create_schedule' );
        $report_id = sanitize_key( $_POST['report_id'] ?? '' );
        if ( ! $this->engine->registry()->get( $report_id ) ) { wp_die( esc_html__( 'Unknown report.', 'woocommerce-advanced-reports' ) ); }
        $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        $format = in_array( $_POST['format'] ?? '', array( 'csv', 'xlsx' ), true ) ? $_POST['format'] : 'xlsx';
        $cadence = in_array( $_POST['cadence'] ?? '', array( 'daily', 'weekly', 'monthly' ), true ) ? $_POST['cadence'] : 'weekly';
        $run_time = preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $_POST['run_time'] ?? '' ) ? $_POST['run_time'] : '08:00';
        $recipients = array_values( array_filter( array_map( 'sanitize_email', preg_split( '/[,;\s]+/', wp_unslash( $_POST['recipients'] ?? '' ) ) ) ) );
        $filters = json_decode( wp_unslash( $_POST['filters'] ?? '{}' ), true );
        if ( ! is_array( $filters ) ) { $filters = array(); }
        $filters['period_mode'] = in_array( $_POST['period_mode'] ?? '', array( 'rolling', 'fixed' ), true ) ? $_POST['period_mode'] : 'rolling';
        if ( ! $name || ! $recipients ) { wp_die( esc_html__( 'A schedule name and at least one recipient are required.', 'woocommerce-advanced-reports' ) ); }

        global $wpdb; $table = $wpdb->prefix . 'wcar_scheduled_reports'; $now = current_time( 'mysql' );
        $next = $this->next_timestamp( $cadence, $run_time );
        $wpdb->insert( $table, array(
            'user_id' => get_current_user_id(), 'name' => $name, 'report_id' => $report_id, 'filters' => wp_json_encode( $filters ), 'format' => $format,
            'recipients' => implode( ',', $recipients ), 'cadence' => $cadence, 'run_time' => $run_time, 'active' => 1, 'last_run' => null,
            'next_run' => wp_date( 'Y-m-d H:i:s', $next, wp_timezone() ), 'created_at' => $now, 'updated_at' => $now,
        ), array( '%d','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s' ) );
        $id = (int) $wpdb->insert_id;
        $action_id = $this->schedule_action( $id, $cadence, $next );
        if ( $action_id ) { $wpdb->update( $table, array( 'action_id' => $action_id ), array( 'id' => $id ), array( '%d' ), array( '%d' ) ); }
        wp_safe_redirect( admin_url( 'admin.php?page=wcar-scheduled-reports' ) ); exit;
    }

    public function delete(): void {
        if ( ! current_user_can( Capabilities::EXPORT ) ) { wp_die( esc_html__( 'Permission denied.', 'woocommerce-advanced-reports' ) ); }
        $id = absint( $_GET['id'] ?? 0 ); check_admin_referer( 'wcar_delete_schedule_' . $id );
        global $wpdb; $table = $wpdb->prefix . 'wcar_scheduled_reports';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $id ), ARRAY_A );
        if ( $row && ( current_user_can( 'manage_options' ) || (int) $row['user_id'] === get_current_user_id() ) ) {
            if ( function_exists( 'as_unschedule_action' ) ) { as_unschedule_action( 'wcar_run_scheduled_report', array( 'schedule_id' => $id ), 'wcar' ); }
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
        try {
            $filter = $this->filter_for_run( $row );
            $manager = new ExportManager( $this->engine );
            $record = $manager->generate_file( $row['report_id'], $filter, $row['format'], (int) $row['user_id'], json_decode( $row['filters'], true ) ?: array() );
            $definition = $this->engine->registry()->get( $row['report_id'] );
            $subject = sprintf( __( '[%1$s] Scheduled report: %2$s', 'woocommerce-advanced-reports' ), get_bloginfo( 'name' ), $definition['title'] ?? $row['report_id'] );
            $body = sprintf( __( "Your scheduled WooCommerce report is attached.\n\nSchedule: %s\nGenerated: %s", 'woocommerce-advanced-reports' ), $row['name'], wp_date( 'Y-m-d H:i:s', null, wp_timezone() ) );
            wp_mail( array_map( 'trim', explode( ',', $row['recipients'] ) ), $subject, $body, array(), array( $record['filepath'] ) );
            $next = $this->next_timestamp( $row['cadence'], $row['run_time'] );
            $wpdb->update( $table, array( 'last_run' => current_time( 'mysql' ), 'next_run' => wp_date( 'Y-m-d H:i:s', $next, wp_timezone() ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ), array( '%s','%s','%s' ), array( '%d' ) );
        } catch ( \Throwable $e ) {
            error_log( 'WCAR scheduled report #' . $id . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }

    public function run_due(): void {
        global $wpdb; $table = $wpdb->prefix . 'wcar_scheduled_reports';
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$table} WHERE active=1 AND next_run <= %s", current_time( 'mysql' ) ), ARRAY_A );
        foreach ( $rows as $row ) { $this->run( (int) $row['id'] ); }
    }

    public function all(): array {
        global $wpdb; $table = $wpdb->prefix . 'wcar_scheduled_reports';
        if ( current_user_can( 'manage_options' ) ) { return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 200", ARRAY_A ); }
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id=%d ORDER BY id DESC LIMIT 200", get_current_user_id() ), ARRAY_A );
    }

    private function schedule_action( int $id, string $cadence, int $next ): int {
        if ( ! function_exists( 'as_schedule_recurring_action' ) ) { return 0; }
        $args = array( 'schedule_id' => $id );
        if ( 'monthly' === $cadence && function_exists( 'as_schedule_cron_action' ) ) {
            $hour = (int) wp_date( 'G', $next, wp_timezone() ); $minute = (int) wp_date( 'i', $next, wp_timezone() );
            return (int) as_schedule_cron_action( $next, "$minute $hour 1 * *", 'wcar_run_scheduled_report', $args, 'wcar', true );
        }
        $interval = 'daily' === $cadence ? DAY_IN_SECONDS : WEEK_IN_SECONDS;
        return (int) as_schedule_recurring_action( $next, $interval, 'wcar_run_scheduled_report', $args, 'wcar', true );
    }

    private function next_timestamp( string $cadence, string $run_time ): int {
        [ $h, $m ] = array_map( 'intval', explode( ':', $run_time ) );
        $now = new \DateTimeImmutable( 'now', wp_timezone() );
        $candidate = $now->setTime( $h, $m );
        if ( 'monthly' === $cadence ) { $candidate = $now->modify( 'first day of next month' )->setTime( $h, $m ); }
        elseif ( 'weekly' === $cadence ) { $candidate = $now->modify( 'next monday' )->setTime( $h, $m ); }
        elseif ( $candidate <= $now ) { $candidate = $candidate->modify( '+1 day' ); }
        return $candidate->getTimestamp();
    }

    private function filter_for_run( array $row ): ReportFilter {
        $raw = json_decode( $row['filters'], true ) ?: array();
        $period_mode = $raw['period_mode'] ?? 'rolling'; unset( $raw['period_mode'] );
        if ( 'fixed' === $period_mode ) { return ReportFilter::from_request( $raw, new Calendar() ); }
        unset( $raw['date_from'], $raw['date_to'] );
        $f = ReportFilter::from_request( $raw, new Calendar() );
        $today = new \DateTimeImmutable( 'today', wp_timezone() );
        if ( 'daily' === $row['cadence'] ) { $f->from = $today->modify( '-1 day' ); $f->to = $f->from->setTime( 23, 59, 59 ); }
        elseif ( 'weekly' === $row['cadence'] ) { $f->to = $today->modify( '-1 second' ); $f->from = $today->modify( '-7 days' ); }
        else { $f->from = $today->modify( 'first day of last month' ); $f->to = $today->modify( 'first day of this month' )->modify( '-1 second' ); }
        return $f;
    }
}
