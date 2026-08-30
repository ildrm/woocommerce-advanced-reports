<?php
namespace WCAR\Reports;

use WCAR\Query\ReportFilter;
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
        $method = (string) $definition['method'];
        if ( ! method_exists( $this->service, $method ) ) {
            return array( 'columns' => array(), 'rows' => array(), 'summary' => array(), 'error' => __( 'Report handler is unavailable.', 'woocommerce-advanced-reports' ) );
        }

        $cache_key = 'wcar_' . substr( md5( WCAR_VERSION . '|' . $report_id . '|' . $filter->cache_key() ), 0, 30 );
        $data = $use_cache ? get_transient( $cache_key ) : false;
        if ( false === $data ) {
            $data = $this->service->{$method}( $filter );
            $settings = wp_parse_args( (array) get_option( 'wcar_settings', array() ), \WCAR\Installer::default_settings() );
            $ttl = max( 0, absint( $settings['cache_ttl'] ?? 300 ) );
            if ( $use_cache && $ttl > 0 ) {
                set_transient( $cache_key, $data, $ttl );
                $keys = (array) get_option( 'wcar_cache_keys', array() );
                $keys[ $cache_key ] = time();
                if ( count( $keys ) > 1000 ) { $keys = array_slice( $keys, -500, null, true ); }
                update_option( 'wcar_cache_keys', $keys, false );
            }
        }

        $data = apply_filters( 'wcar_report_result', $data, $report_id, $filter );
        $data['columns'] = apply_filters( 'wcar_report_columns', $data['columns'] ?? array(), $report_id, $filter );
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
    }
}
