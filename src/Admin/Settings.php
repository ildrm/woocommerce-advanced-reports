<?php
namespace WCAR\Admin;

use WCAR\Installer;
use WCAR\Reports\ReportEngine;
use WCAR\Security\Capabilities;

final class Settings {
    public function register(): void {
        add_action( 'admin_init', array( $this, 'settings' ) );
        add_filter( 'option_page_capability_wcar_settings_group', static fn( $capability ) => Capabilities::SETTINGS );
    }

    public function settings(): void {
        register_setting( 'wcar_settings_group', 'wcar_settings', array( 'sanitize_callback' => array( $this, 'sanitize' ), 'default' => Installer::default_settings() ) );
    }

    public function sanitize( $input ): array {
        $input = is_array( $input ) ? $input : array();
        $defaults = Installer::default_settings();
        $out = array();
        $calendar = self::scalar( $input, 'calendar', $defaults['calendar'] );
        $out['calendar'] = in_array( $calendar, array( 'gregorian', 'jalali' ), true ) ? $calendar : 'gregorian';
        $out['date_format'] = sanitize_text_field( self::scalar( $input, 'date_format', $defaults['date_format'] ) ) ?: $defaults['date_format'];
        $out['jalali_date_format'] = sanitize_text_field( self::scalar( $input, 'jalali_date_format', $defaults['jalali_date_format'] ) ) ?: $defaults['jalali_date_format'];
        $first_day = self::scalar( $input, 'first_day_of_week', '1' );
        $out['first_day_of_week'] = in_array( $first_day, array( '0', '1', '6' ), true ) ? $first_day : '1';
        $out['default_range'] = (string) min( 3650, max( 1, absint( self::scalar( $input, 'default_range', '30' ) ) ) );
        $status_values = array_filter( (array) ( $input['default_statuses'] ?? array() ), 'is_scalar' );
        $statuses = array_map( static fn( $s ) => sanitize_key( str_replace( 'wc-', '', (string) $s ) ), $status_values );
        $valid_statuses = array_map( static fn( $s ) => str_replace( 'wc-', '', $s ), array_keys( wc_get_order_statuses() ) );
        $out['default_statuses'] = array_values( array_intersect( $statuses, $valid_statuses ) );
        if ( ! $out['default_statuses'] ) { $out['default_statuses'] = array_values( array_intersect( $defaults['default_statuses'], $valid_statuses ) ); }
        if ( ! $out['default_statuses'] && $valid_statuses ) { $out['default_statuses'] = array( reset( $valid_statuses ) ); }
        $out['cache_ttl'] = (string) min( DAY_IN_SECONDS, max( 0, absint( self::scalar( $input, 'cache_ttl', '300' ) ) ) );
        $out['batch_size'] = (string) min( 500, max( 25, absint( self::scalar( $input, 'batch_size', '200' ) ) ) );
        $privacy = self::scalar( $input, 'privacy', 'full' );
        $out['privacy'] = in_array( $privacy, array( 'full', 'masked', 'hidden' ), true ) ? $privacy : 'full';
        $export_format = self::scalar( $input, 'export_format', 'xlsx' );
        $out['export_format'] = in_array( $export_format, array( 'csv', 'xlsx' ), true ) ? $export_format : 'xlsx';
        $out['csv_bom'] = ! empty( $input['csv_bom'] ) ? 'yes' : 'no';
        $out['print_logo'] = esc_url_raw( self::scalar( $input, 'print_logo', '' ) );
        $out['number_decimals'] = (string) min( 6, max( 0, absint( self::scalar( $input, 'number_decimals', '2' ) ) ) );
        $out['decimal_separator'] = sanitize_text_field( self::scalar( $input, 'decimal_separator', '.' ) ) ?: '.';
        $out['thousand_separator'] = sanitize_text_field( self::scalar( $input, 'thousand_separator', ',' ) );
        if ( $out['decimal_separator'] === $out['thousand_separator'] ) { $out['thousand_separator'] = ''; }
        $out['role_caps'] = $this->sanitize_role_caps( (array) ( $input['role_caps'] ?? array() ) );
        $out['inactive_days'] = (string) min( 36500, max( 1, absint( self::scalar( $input, 'inactive_days', '90' ) ) ) );
        $out['dead_stock_days'] = (string) min( 36500, max( 1, absint( self::scalar( $input, 'dead_stock_days', '90' ) ) ) );
        $out['dead_stock_max_sold'] = (string) min( 1000000000, max( 0, absint( self::scalar( $input, 'dead_stock_max_sold', '0' ) ) ) );
        $out['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] ) ? 'yes' : '';
        $sanitized = wp_parse_args( $out, $defaults );
        if ( $sanitized !== wp_parse_args( (array) get_option( 'wcar_settings', array() ), $defaults ) ) {
            ( new ReportEngine() )->flush_cache();
        }
        return $sanitized;
    }
    private function sanitize_role_caps( array $matrix ): array {
        $allowed_caps = \WCAR\Security\Capabilities::all();
        $roles = wp_roles()->roles;
        $clean = array();
        foreach ( $roles as $role_name => $details ) {
            if ( 'administrator' === $role_name ) { continue; }
            $selected_values = array_filter( (array) ( $matrix[ $role_name ] ?? array() ), 'is_scalar' );
            $selected = array_values( array_intersect( $allowed_caps, array_map( static fn( $cap ) => sanitize_key( (string) $cap ), $selected_values ) ) );
            if ( $selected && ! in_array( \WCAR\Security\Capabilities::VIEW, $selected, true ) ) { $selected[] = \WCAR\Security\Capabilities::VIEW; }
            $clean[ $role_name ] = $selected;
            $role = get_role( $role_name );
            if ( $role ) {
                foreach ( $allowed_caps as $cap ) {
                    if ( in_array( $cap, $selected, true ) ) { $role->add_cap( $cap ); } else { $role->remove_cap( $cap ); }
                }
            }
        }
        $admin = get_role( 'administrator' );
        if ( $admin ) { foreach ( $allowed_caps as $cap ) { $admin->add_cap( $cap ); } }
        return $clean;
    }

    private static function scalar( array $input, string $key, string $default ): string {
        return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : $default;
    }

}
