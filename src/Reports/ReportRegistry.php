<?php
namespace WCAR\Reports;

final class ReportRegistry {
    private array $reports;

    public function __construct() {
        $this->reports = array(
            'dashboard' => array( 'group' => 'dashboard', 'title' => __( 'Store Overview', 'woocommerce-advanced-reports' ), 'method' => 'dashboard' ),

            'product-sales' => array( 'group' => 'products', 'title' => __( 'Product Sales', 'woocommerce-advanced-reports' ), 'method' => 'product_sales' ),
            'product-variations' => array( 'group' => 'products', 'title' => __( 'Variation Sales', 'woocommerce-advanced-reports' ), 'method' => 'product_variations' ),
            'best-products' => array( 'group' => 'products', 'title' => __( 'Best-selling Products', 'woocommerce-advanced-reports' ), 'method' => 'best_products' ),
            'worst-products' => array( 'group' => 'products', 'title' => __( 'Worst-selling Products', 'woocommerce-advanced-reports' ), 'method' => 'worst_products' ),
            'products-no-sales' => array( 'group' => 'products', 'title' => __( 'Products With No Sales', 'woocommerce-advanced-reports' ), 'method' => 'products_no_sales' ),
            'inventory' => array( 'group' => 'products', 'title' => __( 'Inventory', 'woocommerce-advanced-reports' ), 'method' => 'inventory' ),
            'low-stock' => array( 'group' => 'products', 'title' => __( 'Low Stock', 'woocommerce-advanced-reports' ), 'method' => 'low_stock' ),
            'out-of-stock' => array( 'group' => 'products', 'title' => __( 'Out of Stock', 'woocommerce-advanced-reports' ), 'method' => 'out_of_stock' ),
            'dead-stock' => array( 'group' => 'products', 'title' => __( 'Dead / Slow-moving Stock', 'woocommerce-advanced-reports' ), 'method' => 'dead_stock' ),
            'category-sales' => array( 'group' => 'products', 'title' => __( 'Category Sales', 'woocommerce-advanced-reports' ), 'method' => 'category_sales' ),
            'tag-sales' => array( 'group' => 'products', 'title' => __( 'Tag Sales', 'woocommerce-advanced-reports' ), 'method' => 'tag_sales' ),
            'brand-sales' => array( 'group' => 'products', 'title' => __( 'Brand Sales', 'woocommerce-advanced-reports' ), 'method' => 'brand_sales' ),
            'product-refunds' => array( 'group' => 'products', 'title' => __( 'Product Refunds', 'woocommerce-advanced-reports' ), 'method' => 'product_refunds' ),

            'sales-summary' => array( 'group' => 'orders', 'title' => __( 'Sales Summary', 'woocommerce-advanced-reports' ), 'method' => 'sales_summary' ),
            'orders-by-date' => array( 'group' => 'orders', 'title' => __( 'Orders by Date', 'woocommerce-advanced-reports' ), 'method' => 'orders_by_date' ),
            'orders-by-status' => array( 'group' => 'orders', 'title' => __( 'Orders by Status', 'woocommerce-advanced-reports' ), 'method' => 'orders_by_status' ),
            'order-details' => array( 'group' => 'orders', 'title' => __( 'Order Details', 'woocommerce-advanced-reports' ), 'method' => 'order_details' ),
            'payment-methods' => array( 'group' => 'orders', 'title' => __( 'Payment Methods', 'woocommerce-advanced-reports' ), 'method' => 'payment_methods' ),
            'shipping' => array( 'group' => 'orders', 'title' => __( 'Shipping', 'woocommerce-advanced-reports' ), 'method' => 'shipping' ),
            'coupons' => array( 'group' => 'orders', 'title' => __( 'Coupons / Discounts', 'woocommerce-advanced-reports' ), 'method' => 'coupons' ),
            'taxes' => array( 'group' => 'orders', 'title' => __( 'Tax Report', 'woocommerce-advanced-reports' ), 'method' => 'taxes' ),
            'refunds' => array( 'group' => 'orders', 'title' => __( 'Refunds', 'woocommerce-advanced-reports' ), 'method' => 'refunds' ),
            'failed-cancelled' => array( 'group' => 'orders', 'title' => __( 'Failed & Cancelled Orders', 'woocommerce-advanced-reports' ), 'method' => 'failed_cancelled' ),
            'geography' => array( 'group' => 'orders', 'title' => __( 'Geographic Sales', 'woocommerce-advanced-reports' ), 'method' => 'geography' ),

            'customer-list' => array( 'group' => 'customers', 'title' => __( 'Customer List', 'woocommerce-advanced-reports' ), 'method' => 'customer_list' ),
            'top-customers' => array( 'group' => 'customers', 'title' => __( 'Top Customers', 'woocommerce-advanced-reports' ), 'method' => 'top_customers' ),
            'new-returning' => array( 'group' => 'customers', 'title' => __( 'New vs Returning Customers', 'woocommerce-advanced-reports' ), 'method' => 'new_returning' ),
            'customer-ltv' => array( 'group' => 'customers', 'title' => __( 'Customer Lifetime Value', 'woocommerce-advanced-reports' ), 'method' => 'customer_ltv' ),
            'purchase-frequency' => array( 'group' => 'customers', 'title' => __( 'Purchase Frequency', 'woocommerce-advanced-reports' ), 'method' => 'purchase_frequency' ),
            'inactive-customers' => array( 'group' => 'customers', 'title' => __( 'Inactive Customers', 'woocommerce-advanced-reports' ), 'method' => 'inactive_customers' ),
            'rfm' => array( 'group' => 'customers', 'title' => __( 'RFM Segmentation', 'woocommerce-advanced-reports' ), 'method' => 'rfm' ),
            'cohorts' => array( 'group' => 'customers', 'title' => __( 'Customer Cohorts', 'woocommerce-advanced-reports' ), 'method' => 'cohorts' ),
        );
        $filtered = apply_filters( 'wcar_register_reports', $this->reports );
        if ( is_array( $filtered ) ) {
            $reports = array();
            foreach ( $filtered as $id => $definition ) {
                $report_id = sanitize_key( (string) $id );
                $group = is_array( $definition ) && isset( $definition['group'] ) && is_scalar( $definition['group'] ) ? (string) $definition['group'] : '';
                $title = is_array( $definition ) && isset( $definition['title'] ) && is_scalar( $definition['title'] ) ? (string) $definition['title'] : '';
                $method = is_array( $definition ) && isset( $definition['method'] ) && is_scalar( $definition['method'] ) ? (string) $definition['method'] : '';
                $callback = is_array( $definition ) ? ( $definition['callback'] ?? null ) : null;
                if ( ! $report_id || ! $title || ! in_array( $group, array( 'dashboard', 'products', 'orders', 'customers' ), true ) || ( ! $method && ! is_callable( $callback ) ) ) { continue; }
                $definition['group'] = $group;
                $definition['title'] = $title;
                if ( $method ) { $definition['method'] = $method; }
                $reports[ $report_id ] = $definition;
            }
            $this->reports = $reports;
        }
    }

    public function all(): array { return $this->reports; }
    public function get( string $id ): ?array { return $this->reports[ $id ] ?? null; }
    public function by_group( string $group ): array { return array_filter( $this->reports, static fn( $r ) => $group === ( $r['group'] ?? '' ) ); }
}
