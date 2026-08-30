<?php
namespace WCAR\Admin;

use WCAR\Installer;

final class Settings {
    public function register(): void {
        add_action( 'admin_init', array( $this, 'settings' ) );
    }

    public function settings(): void {
        register_setting( 'wcar_settings_group', 'wcar_settings', array( 'sanitize_callback' => array( $this, 'sanitize' ), 'default' => Installer::default_settings() ) );
    }

    public function sanitize( array $input ): array {
        $defaults = Installer::default_settings();
        $out = array();
        $out['calendar'] = in_array( $input['calendar'] ?? '', array( 'gregorian', 'jalali' ), true ) ? $input['calendar'] : 'gregorian';
        $out['date_format'] = sanitize_text_field( $input['date_format'] ?? $defaults['date_format'] );
        $out['jalali_date_format'] = sanitize_text_field( $input['jalali_date_format'] ?? $defaults['jalali_date_format'] );
        $out['first_day_of_week'] = in_array( (string) ( $input['first_day_of_week'] ?? '1' ), array( '0', '1', '6' ), true ) ? (string) $input['first_day_of_week'] : '1';
        $out['default_range'] = (string) min( 3650, max( 1, absint( $input['default_range'] ?? 30 ) ) );
        $statuses = array_map( static fn( $s ) => sanitize_key( str_replace( 'wc-', '', $s ) ), (array) ( $input['default_statuses'] ?? array() ) );
        $out['default_statuses'] = array_values( array_intersect( $statuses, array_map( static fn( $s ) => str_replace( 'wc-', '', $s ), array_keys( wc_get_order_statuses() ) ) ) );
        $out['cache_ttl'] = (string) min( DAY_IN_SECONDS, max( 0, absint( $input['cache_ttl'] ?? 300 ) ) );
        $out['batch_size'] = (string) min( 500, max( 25, absint( $input['batch_size'] ?? 200 ) ) );
        $out['privacy'] = in_array( $input['privacy'] ?? '', array( 'full', 'masked', 'hidden' ), true ) ? $input['privacy'] : 'full';
        $out['export_format'] = in_array( $input['export_format'] ?? '', array( 'csv', 'xlsx' ), true ) ? $input['export_format'] : 'xlsx';
        $out['csv_bom'] = ! empty( $input['csv_bom'] ) ? 'yes' : 'no';
        $out['print_logo'] = esc_url_raw( $input['print_logo'] ?? '' );
        $out['number_decimals'] = (string) min( 6, max( 0, absint( $input['number_decimals'] ?? 2 ) ) );
        $out['decimal_separator'] = sanitize_text_field( $input['decimal_separator'] ?? '.' );
        $out['thousand_separator'] = sanitize_text_field( $input['thousand_separator'] ?? ',' );
        $out['role_caps'] = $this->sanitize_role_caps( (array) ( $input['role_caps'] ?? array() ) );
        $out['inactive_days'] = (string) max( 1, absint( $input['inactive_days'] ?? 90 ) );
        $out['dead_stock_days'] = (string) max( 1, absint( $input['dead_stock_days'] ?? 90 ) );
        $out['dead_stock_max_sold'] = (string) max( 0, absint( $input['dead_stock_max_sold'] ?? 0 ) );
        $out['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] ) ? 'yes' : '';
        return wp_parse_args( $out, $defaults );
    }
    private function sanitize_role_caps( array $matrix ): array {
        $allowed_caps = \WCAR\Security\Capabilities::all();
        $roles = wp_roles()->roles;
        $clean = array();
        foreach ( $roles as $role_name => $details ) {
            if ( 'administrator' === $role_name ) { continue; }
            $selected = array_values( array_intersect( $allowed_caps, array_map( 'sanitize_key', (array) ( $matrix[ $role_name ] ?? array() ) ) ) );
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

}
