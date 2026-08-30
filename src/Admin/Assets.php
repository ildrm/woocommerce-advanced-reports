<?php
namespace WCAR\Admin;

final class Assets {
    public function register(): void {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
    }

    public function enqueue( string $hook ): void {
        if ( false === strpos( $hook, 'wcar-' ) && false === strpos( $hook, 'toplevel_page_wcar-reports' ) ) {
            return;
        }
        wp_enqueue_style( 'wcar-admin', WCAR_URL . 'assets/css/admin.css', array(), WCAR_VERSION );
        wp_enqueue_script( 'wcar-admin', WCAR_URL . 'assets/js/admin.js', array(), WCAR_VERSION, true );
        wp_localize_script( 'wcar-admin', 'WCARAdmin', array(
            'printTitle' => __( 'Print report', 'woocommerce-advanced-reports' ),
            'confirmDelete' => __( 'Delete this item?', 'woocommerce-advanced-reports' ),
        ) );
    }
}
