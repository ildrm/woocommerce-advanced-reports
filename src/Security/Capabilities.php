<?php
namespace WCAR\Security;

final class Capabilities {
    public const VIEW      = 'wcar_reports_view';
    public const PRODUCTS  = 'wcar_reports_products';
    public const ORDERS    = 'wcar_reports_orders';
    public const CUSTOMERS = 'wcar_reports_customers';
    public const EXPORT    = 'wcar_reports_export';
    public const PRINT     = 'wcar_reports_print';
    public const SETTINGS  = 'wcar_reports_settings';

    public static function all(): array {
        return array( self::VIEW, self::PRODUCTS, self::ORDERS, self::CUSTOMERS, self::EXPORT, self::PRINT, self::SETTINGS );
    }

    public static function install(): void {
        foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
            $role = get_role( $role_name );
            if ( ! $role ) {
                continue;
            }
            foreach ( self::all() as $cap ) {
                if ( 'shop_manager' === $role_name && self::SETTINGS === $cap ) {
                    continue;
                }
                $role->add_cap( $cap );
            }
        }
    }
}
