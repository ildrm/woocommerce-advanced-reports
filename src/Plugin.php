<?php
namespace WCAR;

use WCAR\Admin\Assets;
use WCAR\Admin\Menu;
use WCAR\Admin\Pages;
use WCAR\Admin\Settings;
use WCAR\Export\ExportManager;
use WCAR\Reports\ReportEngine;
use WCAR\Saved\SavedReports;
use WCAR\Schedule\ScheduledReports;

final class Plugin {
    private static ?self $instance = null;
    private bool $booted = false;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot(): void {
        if ( $this->booted ) {
            return;
        }
        $this->booted = true;

        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
            return;
        }

        Installer::maybe_upgrade();

        $engine    = new ReportEngine();
        $saved     = new SavedReports();
        $scheduled = new ScheduledReports( $engine );
        $exports   = new ExportManager( $engine );
        $pages     = new Pages( $engine, $saved, $scheduled, $exports );

        ( new Menu( $pages ) )->register();
        ( new Assets() )->register();
        ( new Settings() )->register();
        $saved->register();
        $scheduled->register();
        $exports->register();
        add_action( 'admin_post_wcar_clear_cache', static function () use ( $engine ): void { if ( ! current_user_can( \WCAR\Security\Capabilities::SETTINGS ) ) { wp_die( esc_html__( 'Permission denied.', 'woocommerce-advanced-reports' ) ); } check_admin_referer( 'wcar_clear_cache' ); $engine->flush_cache(); wp_safe_redirect( admin_url( 'admin.php?page=wcar-report-settings&wcar_cache_cleared=1' ) ); exit; } );

        add_action( 'woocommerce_order_status_changed', array( $engine, 'flush_cache' ) );
        add_action( 'woocommerce_new_order', array( $engine, 'flush_cache' ) );
        add_action( 'woocommerce_refund_created', array( $engine, 'flush_cache' ) );
        add_action( 'save_post_product', array( $engine, 'flush_cache' ) );
        add_action( 'woocommerce_product_set_stock', array( $engine, 'flush_cache' ) );
        add_action( 'woocommerce_variation_set_stock', array( $engine, 'flush_cache' ) );
    }

    public function woocommerce_missing_notice(): void {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }
        echo '<div class="notice notice-error"><p>' . esc_html__( 'WooCommerce Advanced Reports requires WooCommerce to be installed and active.', 'woocommerce-advanced-reports' ) . '</p></div>';
    }
}
