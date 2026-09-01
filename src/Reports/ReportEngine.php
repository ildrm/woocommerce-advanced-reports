<?php
namespace WCAR\Reports;

use WCAR\Query\ReportFilter;
use WCAR\Repository\OrderRepository;
use WCAR\Service\AnalyticsService;

final class ReportEngine {
    private ReportRegistry $registry;
    private AnalyticsService $service;

    public function __construct() {
        $this->registry = new ReportRegistry();
        $this->service = new AnalyticsService();
    }

    public function registry(): ReportRegistry { return $this->registry; }

    public function run( string $report_id, ReportFilter $filter, bool $paginate = true, bool $use_cache = true ): array {
        $definition = $this->registry->get( $report_id );
        if ( ! $definition ) {
            return array( 'columns' => array(), 'rows' => array(), 'summary' => array(), 'error' => __( 'Unknown report.', 'woocommerce-advanced-reports' ) );
        }
        $method = isset( $definition['method'] ) && is_scalar( $definition['method'] ) ? (string) $definition['method'] : '';
        $callback = $definition['callback'] ?? null;
        if ( ! is_callable( $callback ) && ( ! $method || ! method_exists( $this->service, $method ) ) ) {
            return array( 'columns' => array(), 'rows' => array(), 'summary' => array(), 'error' => __( 'Report handler is unavailable.', 'woocommerce-advanced-reports' ) );
        }

        $settings = wp_parse_args( (array) get_option( 'wcar_settings', array() ), \WCAR\Installer::default_settings() );
        $settings_key = md5( wp_json_encode( $settings ) );
        $cache_context = implode( '|', array( (string) get_option( 'wcar_report_cache_version', '1' ), determine_locale(), wp_timezone_string(), get_woocommerce_currency(), (string) get_current_user_id() ) );
        $cache_key = 'wcar_' . substr( md5( WCAR_VERSION . '|' . $cache_context . '|' . $settings_key . '|' . $report_id . '|' . $filter->cache_key() ), 0, 30 );
        $data = $use_cache ? get_transient( $cache_key ) : false;
        if ( false === $data ) {
            $data = is_callable( $callback ) ? call_user_func( $callback, $filter, $report_id ) : $this->service->{$method}( $filter );
            if ( ! is_array( $data ) ) {
                $data = array( 'columns'=>array(), 'rows'=>array(), 'summary'=>array(), 'error'=>__( 'The report handler returned invalid data.', 'woocommerce-advanced-reports' ) );
            }
            $ttl = max( 0, absint( $settings['cache_ttl'] ?? 300 ) );
            if ( $use_cache && $ttl > 0 ) {
                set_transient( $cache_key, $data, $ttl );
                $keys = (array) get_option( 'wcar_cache_keys', array() );
                $keys[ $cache_key ] = time();
                if ( count( $keys ) > 1000 ) { $keys = array_slice( $keys, -500, null, true ); }
                update_option( 'wcar_cache_keys', $keys, false );
            }
        }

        $filtered = apply_filters( 'wcar_report_result', $data, $report_id, $filter );
        $data = is_array( $filtered ) ? $filtered : array( 'columns'=>array(), 'rows'=>array(), 'summary'=>array(), 'error'=>__( 'A report result filter returned invalid data.', 'woocommerce-advanced-reports' ) );
        $columns = apply_filters( 'wcar_report_columns', $data['columns'] ?? array(), $report_id, $filter );
        $data['columns'] = is_array( $columns ) ? array_map( 'strval', array_filter( $columns, 'is_scalar' ) ) : array();
        $data['rows'] = isset( $data['rows'] ) && is_array( $data['rows'] ) ? array_values( array_filter( $data['rows'], 'is_array' ) ) : array();
        $data['summary'] = isset( $data['summary'] ) && is_array( $data['summary'] ) ? $data['summary'] : array();
        $data['currency_breakdown'] = isset( $data['currency_breakdown'] ) && is_array( $data['currency_breakdown'] ) ? array_values( array_filter( $data['currency_breakdown'], 'is_array' ) ) : array();
        $data['charts'] = isset( $data['charts'] ) && is_array( $data['charts'] ) ? $data['charts'] : array();
        foreach ( array( 'note', 'error' ) as $message_key ) {
            if ( isset( $data[ $message_key ] ) && ! is_scalar( $data[ $message_key ] ) ) { unset( $data[ $message_key ] ); }
        }
        $data['total_rows'] = count( $data['rows'] ?? array() );
        $data['page'] = $filter->page;
        $data['per_page'] = $filter->per_page;
        $data['max_pages'] = max( 1, (int) ceil( $data['total_rows'] / max( 1, $filter->per_page ) ) );

        if ( $paginate && ! empty( $data['rows'] ) ) {
            $offset = ( $filter->page - 1 ) * $filter->per_page;
            $data['rows'] = array_slice( $data['rows'], $offset, $filter->per_page );
        }

        if ( $filter->compare && in_array( $report_id, array( 'dashboard', 'sales-summary' ), true ) ) {
            $previous = $this->run( 'sales-summary', $filter->previous_period(), false, true );
            $data['comparison'] = $previous['summary'] ?? array();
        }
        return $data;
    }

    public function flush_cache(): void {
        $keys = (array) get_option( 'wcar_cache_keys', array() );
        foreach ( array_keys( $keys ) as $key ) { delete_transient( $key ); }
        delete_option( 'wcar_cache_keys' );
        update_option( 'wcar_report_cache_version', (string) microtime( true ), false );
        OrderRepository::bump_cache_version();
    }
}
