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

    public static function for_report( array $definition ): string {
        return match ( $definition['group'] ?? '' ) {
            'products'  => self::PRODUCTS,
            'orders'    => self::ORDERS,
            'customers' => self::CUSTOMERS,
            default     => self::VIEW,
        };
    }

    public static function current_user_can_report( array $definition ): bool {
        return current_user_can( self::VIEW ) && current_user_can( self::for_report( $definition ) );
    }

    public static function user_can_report( int $user_id, array $definition ): bool {
        return $user_id > 0 && user_can( $user_id, self::VIEW ) && user_can( $user_id, self::for_report( $definition ) );
    }

    public static function install( bool $grant_shop_manager_defaults = true ): void {
        $roles = $grant_shop_manager_defaults ? array( 'administrator', 'shop_manager' ) : array( 'administrator' );
        foreach ( $roles as $role_name ) {
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
