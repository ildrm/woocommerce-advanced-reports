<?php
namespace WCAR\Admin;

use WCAR\Security\Capabilities;

final class Menu {
    private Pages $pages;
    public function __construct( Pages $pages ) { $this->pages = $pages; }
    public function register(): void { add_action( 'admin_menu', array( $this, 'menu' ) ); }

    public function menu(): void {
        add_menu_page( __( 'Reports', 'woocommerce-advanced-reports' ), __( 'Reports', 'woocommerce-advanced-reports' ), Capabilities::VIEW, 'wcar-reports', array( $this->pages, 'dashboard' ), 'dashicons-chart-area', 56 );
        add_submenu_page( 'wcar-reports', __( 'Overview', 'woocommerce-advanced-reports' ), __( 'Overview', 'woocommerce-advanced-reports' ), Capabilities::VIEW, 'wcar-reports', array( $this->pages, 'dashboard' ) );
        add_submenu_page( 'wcar-reports', __( 'Product Reports', 'woocommerce-advanced-reports' ), __( 'Product Reports', 'woocommerce-advanced-reports' ), Capabilities::PRODUCTS, 'wcar-product-reports', array( $this->pages, 'products' ) );
        add_submenu_page( 'wcar-reports', __( 'Order Reports', 'woocommerce-advanced-reports' ), __( 'Order Reports', 'woocommerce-advanced-reports' ), Capabilities::ORDERS, 'wcar-order-reports', array( $this->pages, 'orders' ) );
        add_submenu_page( 'wcar-reports', __( 'Customer Reports', 'woocommerce-advanced-reports' ), __( 'Customer Reports', 'woocommerce-advanced-reports' ), Capabilities::CUSTOMERS, 'wcar-customer-reports', array( $this->pages, 'customers' ) );
        add_submenu_page( 'wcar-reports', __( 'Saved Reports', 'woocommerce-advanced-reports' ), __( 'Saved Reports', 'woocommerce-advanced-reports' ), Capabilities::VIEW, 'wcar-saved-reports', array( $this->pages, 'saved' ) );
        add_submenu_page( 'wcar-reports', __( 'Scheduled Reports', 'woocommerce-advanced-reports' ), __( 'Scheduled Reports', 'woocommerce-advanced-reports' ), Capabilities::EXPORT, 'wcar-scheduled-reports', array( $this->pages, 'scheduled' ) );
        add_submenu_page( 'wcar-reports', __( 'Export History', 'woocommerce-advanced-reports' ), __( 'Export History', 'woocommerce-advanced-reports' ), Capabilities::EXPORT, 'wcar-export-history', array( $this->pages, 'exports' ) );
        add_submenu_page( 'wcar-reports', __( 'Settings', 'woocommerce-advanced-reports' ), __( 'Settings', 'woocommerce-advanced-reports' ), Capabilities::SETTINGS, 'wcar-report-settings', array( $this->pages, 'settings' ) );
    }
}
