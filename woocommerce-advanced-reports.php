<?php
/**
 * Plugin Name: WooCommerce Advanced Reports
 * Plugin URI: https://github.com/ildrm/woocommerce-advanced-reports
 * Description: Comprehensive WooCommerce reporting with Product, Order and Customer analytics, Jalali/Gregorian dates, CSV/XLSX export, printing, saved reports and scheduled reports.
 * Version: 1.0.1
 * Author: Shahin Ilderemi
 * Author URI:  https://ildrm.com
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * License: MIT
 * WC requires at least: 8.2
 * WC tested up to: 11.0
 * Text Domain: woocommerce-advanced-reports
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'WCAR_VERSION', '1.0.1' );
define( 'WCAR_FILE', __FILE__ );
define( 'WCAR_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCAR_URL', plugin_dir_url( __FILE__ ) );

add_action( 'before_woocommerce_init', static function (): void {
    if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WCAR_FILE, true );
    }
} );

spl_autoload_register(
    static function ( string $class ): void {
        $prefix = 'WCAR\\';
        if ( 0 !== strpos( $class, $prefix ) ) {
            return;
        }
        $relative = substr( $class, strlen( $prefix ) );
        $file     = WCAR_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
        if ( is_readable( $file ) ) {
            require_once $file;
        }
    }
);

register_activation_hook( __FILE__, array( 'WCAR\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WCAR\\Installer', 'deactivate' ) );

add_action(
    'before_woocommerce_init',
    static function (): void {
        if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        }
    }
);

add_action(
    'plugins_loaded',
    static function (): void {
        load_plugin_textdomain( 'woocommerce-advanced-reports', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
        WCAR\Plugin::instance()->boot();
    },
    20
);
