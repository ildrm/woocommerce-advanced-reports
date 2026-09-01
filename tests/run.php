<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'WCAR_VERSION', 'test' );

$GLOBALS['wcar_test_options'] = array();
$GLOBALS['wcar_test_transients'] = array();
$GLOBALS['wcar_test_terms'] = array();
$GLOBALS['wcar_test_term_children'] = array();
$GLOBALS['wcar_test_current_caps'] = array();
$GLOBALS['wcar_test_user_caps'] = array();
$GLOBALS['wcar_test_unscheduled_actions'] = array();
$GLOBALS['wcar_test_filters'] = array();
$GLOBALS['wcar_test_count'] = 0;

final class WP_Error {}

class WC_Order_Item_Product {
    public function __construct( private int $product_id, private int $variation_id = 0 ) {}
    public function get_product_id(): int { return $this->product_id; }
    public function get_variation_id(): int { return $this->variation_id; }
}

class WC_Product {
    public function __construct( private int $id, private string $type = 'simple', private int $parent_id = 0, private array $children = array() ) {}
    public function get_id(): int { return $this->id; }
    public function get_parent_id(): int { return $this->parent_id; }
    public function get_children(): array { return $this->children; }
    public function is_type( string $type ): bool { return $this->type === $type; }
}

function __( string $text, string $domain = '' ): string { return $text; }
function get_option( string $key, $default = false ) { return $GLOBALS['wcar_test_options'][ $key ] ?? $default; }
function update_option( string $key, $value, $autoload = null ): bool { $GLOBALS['wcar_test_options'][ $key ] = $value; return true; }
function wp_parse_args( $args, array $defaults = array() ): array { return array_merge( $defaults, (array) $args ); }
function wp_timezone(): DateTimeZone { return new DateTimeZone( 'UTC' ); }
function wp_timezone_string(): string { return 'UTC'; }
function wp_date( string $format, ?int $timestamp = null, ?DateTimeZone $timezone = null ): string {
    return ( new DateTimeImmutable( '@' . ( $timestamp ?? time() ) ) )->setTimezone( $timezone ?? wp_timezone() )->format( $format );
}
function determine_locale(): string { return 'en_US'; }
function apply_filters( string $hook, $value, ...$args ) { return isset( $GLOBALS['wcar_test_filters'][ $hook ] ) ? $GLOBALS['wcar_test_filters'][ $hook ]( $value, ...$args ) : $value; }
function sanitize_text_field( $value ): string { return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : ''; }
function sanitize_key( $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ) ?? ''; }
function absint( $value ): int { return abs( (int) $value ); }
function wp_unslash( $value ) {
    if ( is_array( $value ) ) { return array_map( 'wp_unslash', $value ); }
    return is_string( $value ) ? stripslashes( $value ) : $value;
}
function wc_get_order_statuses(): array { return array( 'wc-processing'=>'Processing', 'wc-completed'=>'Completed', 'wc-failed'=>'Failed' ); }
function wc_format_decimal( $value ): string { $value = trim( (string) $value ); return is_numeric( $value ) ? $value : ''; }
function wp_json_encode( $value, int $flags = 0, int $depth = 512 ) { return json_encode( $value, $flags, $depth ); }
function get_transient( string $key ) { return $GLOBALS['wcar_test_transients'][ $key ] ?? false; }
function set_transient( string $key, $value, int $expiration = 0 ): bool { $GLOBALS['wcar_test_transients'][ $key ] = $value; return true; }
function delete_transient( string $key ): bool { unset( $GLOBALS['wcar_test_transients'][ $key ] ); return true; }
function is_multisite(): bool { return false; }
function wp_unschedule_hook( string $hook ): void {}
function as_unschedule_all_actions( string $hook, array $args = array(), string $group = '' ): void { $GLOBALS['wcar_test_unscheduled_actions'][] = array( $hook, func_num_args() ); }
function is_email( string $email ): bool { return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ); }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_get_post_terms( int $product_id, string $taxonomy, array $args = array() ) { return $GLOBALS['wcar_test_terms'][ $product_id ] ?? array(); }
function get_term_children( int $term_id, string $taxonomy ) { return $GLOBALS['wcar_test_term_children'][ $term_id ] ?? array(); }
function wc_get_product_types(): array { return array( 'simple'=>'Simple', 'variable'=>'Variable' ); }
function wc_get_product( int $product_id ) { return $GLOBALS['wcar_test_products'][ $product_id ] ?? false; }
function wc_get_products( array $args ) {
    $GLOBALS['wcar_test_product_query'] = $args;
    $products = array_values( $GLOBALS['wcar_test_products'] ?? array() );
    if ( ! empty( $args['include'] ) ) { $products = array_values( array_filter( $products, static fn( $product ) => in_array( $product->get_id(), $args['include'], true ) ) ); }
    return (object) array( 'products'=>$products, 'max_num_pages'=>1 );
}
function current_user_can( string $capability ): bool { return in_array( $capability, $GLOBALS['wcar_test_current_caps'], true ); }
function user_can( $user, string $capability ): bool { return in_array( $capability, $GLOBALS['wcar_test_user_caps'][ (int) $user ] ?? array(), true ); }
function get_current_user_id(): int { return 7; }
function get_woocommerce_currency(): string { return 'USD'; }
function wp_tempnam( string $filename = '' ): string { return tempnam( sys_get_temp_dir(), 'wcar-' ); }
function wp_check_invalid_utf8( string $text, bool $strip = false ): string { return 1 === preg_match( '//u', $text ) ? $text : ''; }

function check_true( bool $condition, string $message ): void {
    ++$GLOBALS['wcar_test_count'];
    if ( ! $condition ) { throw new RuntimeException( $message ); }
}

function check_same( $expected, $actual, string $message ): void {
    check_true( $expected === $actual, $message . ' Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . '.' );
}

$root = dirname( __DIR__ );
require_once $root . '/src/Security/Capabilities.php';
require_once $root . '/src/Installer.php';
require_once $root . '/src/Calendar/Calendar.php';
require_once $root . '/src/Query/ReportFilter.php';
require_once $root . '/src/Repository/OrderRepository.php';
require_once $root . '/src/Repository/ProductRepository.php';
require_once $root . '/src/Export/ExporterInterface.php';
require_once $root . '/src/Export/SimpleZipWriter.php';
require_once $root . '/src/Export/CsvExporter.php';
require_once $root . '/src/Export/XlsxExporter.php';
require_once $root . '/src/Support/Format.php';
require_once $root . '/src/Service/AnalyticsService.php';
require_once $root . '/src/Reports/ReportRegistry.php';
require_once $root . '/src/Reports/ReportEngine.php';
require_once $root . '/src/Schedule/ScheduledReports.php';

use WCAR\Calendar\Calendar;
use WCAR\Export\CsvExporter;
use WCAR\Export\XlsxExporter;
use WCAR\Installer;
use WCAR\Query\ReportFilter;
use WCAR\Repository\OrderRepository;
use WCAR\Repository\ProductRepository;
use WCAR\Reports\ReportEngine;
use WCAR\Reports\ReportRegistry;
use WCAR\Schedule\ScheduledReports;
use WCAR\Security\Capabilities;
use WCAR\Service\AnalyticsService;
use WCAR\Support\Format;

$settings = Installer::default_settings();
$settings['default_range'] = '7';
$settings['date_format'] = 'd/m/Y';
$GLOBALS['wcar_test_options']['wcar_settings'] = $settings;

$calendar = new Calendar();
check_true( null === $calendar->parse_input( '2025-02-29' ), 'Invalid Gregorian dates must be rejected.' );
check_true( null !== $calendar->parse_input( '2024-02-29' ), 'Valid Gregorian leap dates must be accepted.' );
$gregorian = $calendar->parse_input( '2026-09-01' );
check_same( '2026-09-01', $calendar->format_input( $gregorian ), 'Gregorian form values must remain HTML-date compatible.' );

$settings['calendar'] = 'jalali';
$GLOBALS['wcar_test_options']['wcar_settings'] = $settings;
$jalali = $calendar->parse_input( '1405/06/10' );
check_true( null !== $jalali, 'Valid Jalali dates must be accepted.' );
check_same( '1405/06/10', $calendar->format_input( $jalali ), 'Jalali form values must round-trip.' );
check_true( null === $calendar->parse_input( '1405/13/01' ), 'Invalid Jalali months must be rejected.' );
check_true( null === $calendar->parse_input( '0000/01/01' ), 'Jalali year zero must be rejected.' );

$settings['calendar'] = 'gregorian';
$GLOBALS['wcar_test_options']['wcar_settings'] = $settings;
$filter = ReportFilter::from_input( array(
    'date_from'=>'not-a-date', 'date_to'=>'2026-09-01', 'status'=>array( 'invalid' ),
    'product'=>array( '4', '0' ), 'product_csv'=>'4, 7 9', 'category'=>array( '3' ), 'min_amount'=>'invalid',
) );
check_same( 6, $filter->from->diff( $filter->to )->days, 'Invalid ranges must fall back to the configured inclusive range.' );
check_true( $filter->invalid_date_range, 'Invalid ranges must be reported to the UI.' );
check_same( array( 'processing', 'completed' ), $filter->statuses, 'Invalid statuses must fall back to configured statuses.' );
check_same( array( 4, 7, 9 ), $filter->product_ids, 'CSV and array product filters must merge and de-duplicate.' );
check_true( null === $filter->min_amount, 'Invalid decimal filters must not silently become zero.' );
check_true( isset( $filter->to_request_array( $calendar )['date_from'] ), 'Canonical saved filters must contain resolved dates.' );
$stored_filter = $filter->to_storage_array( $calendar );
$settings['calendar'] = 'jalali';
$GLOBALS['wcar_test_options']['wcar_settings'] = $settings;
$restored_filter = ReportFilter::from_storage( $stored_filter, new Calendar() );
check_same( $filter->from->getTimestamp(), $restored_filter->from->getTimestamp(), 'Stored report dates must survive a calendar-setting change.' );
check_same( $filter->to->getTimestamp(), $restored_filter->to->getTimestamp(), 'Stored report end dates must survive a calendar-setting change.' );
$settings['calendar'] = 'gregorian';
$GLOBALS['wcar_test_options']['wcar_settings'] = $settings;
$corrupt_filter = $stored_filter;
$corrupt_filter['_wcar_from_timestamp'] = PHP_INT_MAX;
$corrupt_filter['_wcar_to_timestamp'] = PHP_INT_MAX;
$restored_corrupt_filter = ReportFilter::from_storage( $corrupt_filter, new Calendar() );
check_same( $filter->from->getTimestamp(), $restored_corrupt_filter->from->getTimestamp(), 'Out-of-range stored timestamps must fall back to validated form dates.' );
$previous = $filter->previous_period();
check_same( $filter->from->modify( '-1 second' )->format( 'Y-m-d H:i:s' ), $previous->to->format( 'Y-m-d H:i:s' ), 'Previous-period comparison must end immediately before the selected range.' );
check_same( $filter->from->diff( $filter->to )->days, $previous->from->diff( $previous->to )->days, 'Previous-period comparison must preserve the number of calendar days.' );

$settings['privacy'] = 'masked';
$GLOBALS['wcar_test_options']['wcar_settings'] = $settings;
check_same( '1***4', Format::mask_phone( '1234' ), 'Short phone numbers must not remain exposed in masked mode.' );
check_same( 'n***', Format::mask_email( 'not-an-email' ), 'Malformed emails must not remain exposed in masked mode.' );

check_same( Capabilities::PRODUCTS, Capabilities::for_report( array( 'group'=>'products' ) ), 'Product reports must require the product capability.' );
$GLOBALS['wcar_test_current_caps'] = array( Capabilities::VIEW, Capabilities::EXPORT );
check_true( ! Capabilities::current_user_can_report( array( 'group'=>'customers' ) ), 'Export permission must not bypass customer-report permission.' );
Installer::deactivate();
check_same(
    array( array( 'wcar_generate_export_job', 1 ), array( 'wcar_run_scheduled_report', 1 ) ),
    $GLOBALS['wcar_test_unscheduled_actions'],
    'Lifecycle cleanup must cancel Action Scheduler jobs by hook without an empty-arguments constraint.'
);

$repository = new OrderRepository();
$item = new WC_Order_Item_Product( 10, 11 );
$GLOBALS['wcar_test_terms'][10] = array( 20 );
$item_filter = new ReportFilter();
$item_filter->product_ids = array( 11 );
$item_filter->category_ids = array( 20 );
check_true( $repository->item_matches( $item, $item_filter ), 'Combined product/category filters must match the same item.' );
$item_filter->category_ids = array( 99 );
check_true( ! $repository->item_matches( $item, $item_filter ), 'Product and category filters must use AND semantics.' );
$item_filter->category_ids = array( 19 );
$GLOBALS['wcar_test_term_children'][19] = array( 20 );
check_true( $repository->item_matches( $item, $item_filter ), 'Parent category filters must include descendant categories.' );

$GLOBALS['wcar_test_products'] = array(
    10 => new WC_Product( 10, 'variable', 0, array( 11 ) ),
    11 => new WC_Product( 11, 'variation', 10 ),
);
$GLOBALS['wcar_test_terms'][10] = array( 20 );
$product_filter = new ReportFilter();
$product_filter->product_ids = array( 10 );
$product_filter->category_ids = array( 20 );
$product_ids = array_map( static fn( $product ) => $product->get_id(), iterator_to_array( ( new ProductRepository() )->iterate( $product_filter ) ) );
check_same( array( 10, 11 ), $product_ids, 'Inventory queries must include matching variable-product children.' );
check_true( in_array( 'variation', $GLOBALS['wcar_test_product_query']['type'], true ), 'Inventory queries must request product variations explicitly.' );

$csv_path = tempnam( sys_get_temp_dir(), 'wcar-csv-' );
( new CsvExporter() )->write_file( $csv_path, '=title', array( 'value'=>'Value' ), array( array( 'value'=>" \t=2+2" ), array( 'value'=>-5 ) ) );
$csv = file_get_contents( $csv_path );
check_true( str_contains( $csv, "'=title" ), 'CSV titles must be protected from formula injection.' );
check_true( str_contains( $csv, "' \t=2+2" ), 'CSV cells with leading whitespace must be protected from formula injection.' );
check_true( str_contains( $csv, "\n-5\n" ) && ! str_contains( $csv, "\n'-5\n" ), 'Numeric negative values must remain numeric in CSV output.' );
unlink( $csv_path );

$xlsx_path = tempnam( sys_get_temp_dir(), 'wcar-xlsx-' );
( new XlsxExporter() )->write_file( $xlsx_path, 'Report', array( 'value'=>'Value' ), array( array( 'value'=>"\x01=2+2" ) ) );
$xlsx = file_get_contents( $xlsx_path );
$sheet_start = strpos( $xlsx, '<worksheet xmlns=' );
$sheet_end = strpos( $xlsx, '</worksheet>', $sheet_start );
$sheet = substr( $xlsx, $sheet_start, $sheet_end - $sheet_start );
check_same( 'PK', substr( $xlsx, 0, 2 ), 'XLSX output must be a ZIP package.' );
check_true( false !== $sheet_start && false !== $sheet_end, 'XLSX output must contain a worksheet.' );
check_true( ! str_contains( $sheet, "\x01" ), 'Illegal XML control characters must be removed from XLSX cells.' );
check_true( str_contains( $sheet, '=2+2' ) && ! str_contains( $sheet, '<f>' ), 'XLSX user text must remain text rather than a formula.' );
if ( class_exists( 'ZipArchive' ) ) {
    $zip = new ZipArchive();
    check_same( true, $zip->open( $xlsx_path ), 'Generated XLSX output must be readable by a standard ZIP implementation.' );
    check_true( false !== $zip->getFromName( '[Content_Types].xml' ), 'Generated XLSX output must include package content types.' );
    check_true( false !== $zip->getFromName( 'xl/worksheets/sheet1.xml' ), 'Generated XLSX output must include its worksheet part.' );
    $zip->close();
}
unlink( $xlsx_path );

$analytics = ( new ReflectionClass( AnalyticsService::class ) )->newInstanceWithoutConstructor();
$segment = new ReflectionMethod( AnalyticsService::class, 'rfm_segment' );
$segment->setAccessible( true );
check_same( 'Recent Customers', $segment->invoke( $analytics, 5, 1, 2 ), 'The Recent Customers RFM segment must be reachable.' );
$currency_metric = new ReflectionMethod( AnalyticsService::class, 'compare_currency_metric' );
$currency_metric->setAccessible( true );
check_true( $currency_metric->invoke( null, array( 'currency'=>'USD', 'net_sales'=>20 ), array( 'currency'=>'USD', 'net_sales'=>10 ), 'net_sales' ) < 0, 'Monetary rankings must sort descending within one currency.' );
check_true( $currency_metric->invoke( null, array( 'currency'=>'USD', 'net_sales'=>1 ), array( 'currency'=>'EUR', 'net_sales'=>1000 ), 'net_sales' ) > 0, 'Monetary rankings must group currencies instead of comparing nominal amounts.' );
$inherited_cogs_product = new class( 20, 'variation', 10 ) extends WC_Product {
    public function get_cogs_value() { return null; }
    public function get_cogs_total_value(): float { return 3.5; }
    public function get_meta( string $key, bool $single = true ) { return ''; }
};
$product_cost = new ReflectionMethod( AnalyticsService::class, 'product_cost_value' );
$product_cost->setAccessible( true );
check_same( 14.0, $product_cost->invoke( $analytics, $inherited_cogs_product, 4 ), 'Inherited WooCommerce variation COGS must contribute to inventory cost value.' );

$GLOBALS['wcar_test_filters']['wcar_register_reports'] = static function ( array $reports ): array {
    $reports['broken'] = array( 'group'=>array( 'orders' ), 'title'=>'Broken', 'method'=>'sales_summary' );
    $reports['Custom-Report!'] = array(
        'group'=>'orders', 'title'=>'Custom',
        'callback'=>static fn() => array( 'columns'=>array( 'value'=>'Value', 'bad'=>array() ), 'rows'=>array( array( 'value'=>1 ), 'invalid' ), 'summary'=>'invalid', 'note'=>array() ),
    );
    return $reports;
};
$registry = new ReportRegistry();
check_true( null === $registry->get( 'broken' ), 'Malformed extension report definitions must be rejected.' );
check_true( null !== $registry->get( 'custom-report' ), 'Valid extension report IDs must be canonicalized.' );
$custom_data = ( new ReportEngine() )->run( 'custom-report', $filter, false, false );
check_same( array( 'value'=>'Value' ), $custom_data['columns'], 'Extension report columns must discard non-scalar labels.' );
check_same( array( array( 'value'=>1 ) ), $custom_data['rows'], 'Extension report results must discard malformed rows.' );
check_same( array(), $custom_data['summary'], 'Extension report summaries must be arrays.' );
check_true( ! isset( $custom_data['note'] ), 'Extension report messages must be scalar.' );
unset( $GLOBALS['wcar_test_filters']['wcar_register_reports'] );

$scheduled = ( new ReflectionClass( ScheduledReports::class ) )->newInstanceWithoutConstructor();
$next_timestamp = new ReflectionMethod( ScheduledReports::class, 'next_timestamp' );
$next_timestamp->setAccessible( true );
foreach ( array( 'daily', 'weekly', 'monthly' ) as $cadence ) {
    $next = $next_timestamp->invoke( $scheduled, $cadence, '08:00' );
    check_true( $next > time(), ucfirst( $cadence ) . ' schedules must resolve to a future timestamp.' );
    check_same( 8, (int) wp_date( 'G', $next, wp_timezone() ), ucfirst( $cadence ) . ' schedules must preserve the selected hour.' );
}
check_same( 1, (int) wp_date( 'w', $next_timestamp->invoke( $scheduled, 'weekly', '08:00' ), wp_timezone() ), 'Weekly schedules must run on Monday.' );
check_same( 1, (int) wp_date( 'j', $next_timestamp->invoke( $scheduled, 'monthly', '08:00' ), wp_timezone() ), 'Monthly schedules must run on the first day.' );
$settings['calendar'] = 'jalali';
$GLOBALS['wcar_test_options']['wcar_settings'] = $settings;
$rolling_month = new ReflectionMethod( ScheduledReports::class, 'filter_for_run' );
$rolling_month->setAccessible( true );
$monthly_filter = $rolling_month->invoke( $scheduled, array( 'filters'=>wp_json_encode( array( 'period_mode'=>'rolling' ) ), 'cadence'=>'monthly' ) );
$jalali_calendar = new Calendar();
check_same( '1', $jalali_calendar->format( $monthly_filter->from, 'j' ), 'Monthly rolling schedules must start on the first day of the active calendar month.' );
check_same( '1', $jalali_calendar->format( $monthly_filter->to->modify( '+1 second' ), 'j' ), 'Monthly rolling schedules must end immediately before the active calendar month.' );

echo 'OK (' . $GLOBALS['wcar_test_count'] . " assertions)\n";
