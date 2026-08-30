<?php
namespace WCAR\Service;

use WCAR\Calendar\Calendar;
use WCAR\Query\ReportFilter;
use WCAR\Repository\OrderRepository;
use WCAR\Repository\ProductRepository;
use WCAR\Support\Format;

final class AnalyticsService {
    private OrderRepository $orders;
    private ProductRepository $products;
    private Calendar $calendar;

    public function __construct() {
        $this->orders = new OrderRepository();
        $this->products = new ProductRepository();
        $this->calendar = new Calendar();
    }

    public function dashboard( ReportFilter $f ): array {
        $summary = $this->sales_summary( $f );
        $by_date = $this->orders_by_date( $f );
        $status = $this->orders_by_status( $f );
        $products = $this->best_products( $f );
        $customers = $this->new_returning( $f );
        return array(
            'columns' => array(),
            'rows' => array(),
            'summary' => $summary['summary'],
            'currency_breakdown' => $summary['rows'],
            'charts' => array(
                'trend' => array_slice( $by_date['rows'], -60 ),
                'status' => array_slice( $status['rows'], 0, 12 ),
                'products' => array_slice( $products['rows'], 0, 10 ),
                'customers' => $customers['rows'],
            ),
            'note' => __( 'Financial totals are kept separate by currency. Select a currency filter for single-currency KPI totals.', 'woocommerce-advanced-reports' ),
        );
    }

    public function sales_summary( ReportFilter $f ): array {
        $groups = array();
        foreach ( $this->orders->iterate( $f ) as $order ) {
            $currency = $order->get_currency() ?: get_woocommerce_currency();
            if ( ! isset( $groups[ $currency ] ) ) {
                $groups[ $currency ] = $this->zero_sales_group( $currency );
            }
            $m = $this->order_metrics( $order );
            foreach ( array( 'gross_sales', 'discounts', 'shipping', 'tax', 'refunds', 'net_sales', 'order_total' ) as $key ) {
                $groups[ $currency ][ $key ] += $m[ $key ];
            }
            $groups[ $currency ]['orders']++;
            $groups[ $currency ]['items'] += $m['items'];
            if ( 0 === (int) $order->get_customer_id() ) {
                $groups[ $currency ]['guest_orders']++;
            }
        }
        foreach ( $groups as &$row ) {
            $row['aov'] = $row['orders'] ? $row['net_sales'] / $row['orders'] : 0;
        }
        unset( $row );
        $summary = $this->summary_from_currency_groups( $groups );
        return array(
            'columns' => array(
                'currency' => __( 'Currency', 'woocommerce-advanced-reports' ),
                'orders' => __( 'Orders', 'woocommerce-advanced-reports' ),
                'items' => __( 'Items Sold', 'woocommerce-advanced-reports' ),
                'gross_sales' => __( 'Gross Sales', 'woocommerce-advanced-reports' ),
                'discounts' => __( 'Discounts', 'woocommerce-advanced-reports' ),
                'shipping' => __( 'Shipping', 'woocommerce-advanced-reports' ),
                'tax' => __( 'Tax', 'woocommerce-advanced-reports' ),
                'refunds' => __( 'Refunds', 'woocommerce-advanced-reports' ),
                'net_sales' => __( 'Net Collected', 'woocommerce-advanced-reports' ),
                'aov' => __( 'Average Order Value', 'woocommerce-advanced-reports' ),
            ),
            'rows' => array_values( $groups ),
            'summary' => $summary,
        );
    }

    public function orders_by_date( ReportFilter $f ): array {
        $groups = array();
        foreach ( $this->orders->iterate( $f ) as $order ) {
            $date = $order->get_date_created();
            if ( ! $date ) { continue; }
            $bucket = $this->date_bucket( $date->getTimestamp(), $f->group_by );
            $currency = $order->get_currency();
            $key = $bucket . '|' . $currency;
            if ( ! isset( $groups[ $key ] ) ) {
                $groups[ $key ] = array( 'period' => $bucket, 'currency' => $currency, 'orders' => 0, 'items' => 0, 'gross_sales' => 0.0, 'discounts' => 0.0, 'refunds' => 0.0, 'net_sales' => 0.0 );
            }
            $m = $this->order_metrics( $order );
            $groups[ $key ]['orders']++;
            $groups[ $key ]['items'] += $m['items'];
            foreach ( array( 'gross_sales', 'discounts', 'refunds', 'net_sales' ) as $field ) {
                $groups[ $key ][ $field ] += $m[ $field ];
            }
        }
        ksort( $groups );
        return array(
            'columns' => array( 'period' => __( 'Period', 'woocommerce-advanced-reports' ), 'currency' => __( 'Currency', 'woocommerce-advanced-reports' ), 'orders' => __( 'Orders', 'woocommerce-advanced-reports' ), 'items' => __( 'Items', 'woocommerce-advanced-reports' ), 'gross_sales' => __( 'Gross Sales', 'woocommerce-advanced-reports' ), 'discounts' => __( 'Discounts', 'woocommerce-advanced-reports' ), 'refunds' => __( 'Refunds', 'woocommerce-advanced-reports' ), 'net_sales' => __( 'Net Collected', 'woocommerce-advanced-reports' ) ),
            'rows' => array_values( $groups ),
            'summary' => array(),
        );
    }

    public function orders_by_status( ReportFilter $f ): array {
        $groups = array();
        $all_statuses = array_map( static fn( $s ) => str_replace( 'wc-', '', $s ), array_keys( wc_get_order_statuses() ) );
        foreach ( $this->orders->iterate( $f, $all_statuses ) as $order ) {
            $status = $order->get_status();
            $currency = $order->get_currency();
            $key = $status . '|' . $currency;
            if ( ! isset( $groups[ $key ] ) ) {
                $groups[ $key ] = array( 'status' => wc_get_order_status_name( $status ), 'currency' => $currency, 'orders' => 0, 'net_sales' => 0.0 );
            }
            $groups[ $key ]['orders']++;
            $groups[ $key ]['net_sales'] += max( 0, (float) $order->get_total() - (float) $order->get_total_refunded() );
        }
        usort( $groups, static fn( $a, $b ) => $b['orders'] <=> $a['orders'] );
        return array( 'columns' => array( 'status' => __( 'Status', 'woocommerce-advanced-reports' ), 'currency' => __( 'Currency', 'woocommerce-advanced-reports' ), 'orders' => __( 'Orders', 'woocommerce-advanced-reports' ), 'net_sales' => __( 'Net Collected', 'woocommerce-advanced-reports' ) ), 'rows' => array_values( $groups ), 'summary' => array() );
    }

    public function order_details( ReportFilter $f ): array {
        $rows = array();
        foreach ( $this->orders->iterate( $f ) as $order ) {
            $m = $this->order_metrics( $order );
            $rows[] = array(
                'order_id' => $order->get_id(),
                'date' => $this->calendar->format( $order->get_date_created() ),
                'customer' => trim( $order->get_formatted_billing_full_name() ),
                'email' => Format::mask_email( (string) $order->get_billing_email() ),
                'phone' => Format::mask_phone( (string) $order->get_billing_phone() ),
                'status' => wc_get_order_status_name( $order->get_status() ),
                'items' => $m['items'],
                'subtotal' => $m['line_subtotal'],
                'discount' => $m['discounts'],
                'shipping' => $m['shipping'],
                'tax' => $m['tax'],
                'refund' => $m['refunds'],
                'total' => (float) $order->get_total(),
                'currency' => $order->get_currency(),
                'payment' => $order->get_payment_method_title(),
                'shipping_method' => $order->get_shipping_method(),
                'coupon' => implode( ', ', $order->get_coupon_codes() ),
                'country' => $order->get_billing_country(),
                'city' => $order->get_billing_city(),
            );
        }
        return array( 'columns' => array(
            'order_id' => __( 'Order', 'woocommerce-advanced-reports' ), 'date' => __( 'Date', 'woocommerce-advanced-reports' ), 'customer' => __( 'Customer', 'woocommerce-advanced-reports' ),
            'email' => __( 'Email', 'woocommerce-advanced-reports' ), 'phone' => __( 'Phone', 'woocommerce-advanced-reports' ), 'status' => __( 'Status', 'woocommerce-advanced-reports' ), 'items' => __( 'Items', 'woocommerce-advanced-reports' ),
            'subtotal' => __( 'Subtotal', 'woocommerce-advanced-reports' ), 'discount' => __( 'Discount', 'woocommerce-advanced-reports' ), 'shipping' => __( 'Shipping', 'woocommerce-advanced-reports' ), 'tax' => __( 'Tax', 'woocommerce-advanced-reports' ),
            'refund' => __( 'Refund', 'woocommerce-advanced-reports' ), 'total' => __( 'Order Total', 'woocommerce-advanced-reports' ), 'currency' => __( 'Currency', 'woocommerce-advanced-reports' ),
            'payment' => __( 'Payment', 'woocommerce-advanced-reports' ), 'shipping_method' => __( 'Shipping Method', 'woocommerce-advanced-reports' ), 'coupon' => __( 'Coupons', 'woocommerce-advanced-reports' ),
            'country' => __( 'Country', 'woocommerce-advanced-reports' ), 'city' => __( 'City', 'woocommerce-advanced-reports' ),
        ), 'rows' => $rows, 'summary' => array() );
    }

    public function payment_methods( ReportFilter $f ): array {
        $groups = array();
        foreach ( $this->orders->iterate( $f ) as $order ) {
            $currency = $order->get_currency();
            $method = $order->get_payment_method_title() ?: __( 'Unknown', 'woocommerce-advanced-reports' );
            $key = $method . '|' . $currency;
            if ( ! isset( $groups[ $key ] ) ) { $groups[ $key ] = array( 'payment_method' => $method, 'currency' => $currency, 'orders' => 0, 'gross_sales' => 0.0, 'refunds' => 0.0, 'net_sales' => 0.0, 'aov' => 0.0 ); }
            $m = $this->order_metrics( $order );
            $groups[ $key ]['orders']++;
            $groups[ $key ]['gross_sales'] += $m['gross_sales'];
            $groups[ $key ]['refunds'] += $m['refunds'];
            $groups[ $key ]['net_sales'] += $m['net_sales'];
        }
        foreach ( $groups as &$row ) { $row['aov'] = $row['orders'] ? $row['net_sales'] / $row['orders'] : 0; } unset( $row );
        usort( $groups, static fn( $a, $b ) => $b['net_sales'] <=> $a['net_sales'] );
        return array( 'columns' => array( 'payment_method' => __( 'Payment Method', 'woocommerce-advanced-reports' ), 'currency' => __( 'Currency', 'woocommerce-advanced-reports' ), 'orders' => __( 'Orders', 'woocommerce-advanced-reports' ), 'gross_sales' => __( 'Gross Sales', 'woocommerce-advanced-reports' ), 'refunds' => __( 'Refunds', 'woocommerce-advanced-reports' ), 'net_sales' => __( 'Net Collected', 'woocommerce-advanced-reports' ), 'aov' => __( 'AOV', 'woocommerce-advanced-reports' ) ), 'rows' => array_values( $groups ), 'summary' => array() );
    }

    public function shipping( ReportFilter $f ): array {
        $groups = array();
        foreach ( $this->orders->iterate( $f ) as $order ) {
            $currency = $order->get_currency();
            $shipping_items = $order->get_items( 'shipping' );
            if ( ! $shipping_items ) { $shipping_items = array( null ); }
            foreach ( $shipping_items as $item ) {
                $method = $item ? $item->get_method_title() : __( 'No shipping method', 'woocommerce-advanced-reports' );
                $zone = __( 'Unknown', 'woocommerce-advanced-reports' );
                if ( $item && $item->get_instance_id() && method_exists( '\WC_Shipping_Zones', 'get_zone_by' ) ) { $zone_obj = \WC_Shipping_Zones::get_zone_by( 'instance_id', $item->get_instance_id() ); if ( $zone_obj ) { $zone = $zone_obj->get_zone_name(); } }
                $key = $method . '|' . $zone . '|' . $currency;
                if ( ! isset( $groups[ $key ] ) ) { $groups[ $key ] = array( 'shipping_method' => $method, 'shipping_zone' => $zone, 'currency' => $currency, 'orders' => 0, 'shipping_revenue' => 0.0, 'order_value' => 0.0 ); }
                $groups[ $key ]['orders']++;
                $groups[ $key ]['shipping_revenue'] += $item ? (float) $item->get_total() + (float) $item->get_total_tax() : 0.0;
                $groups[ $key ]['order_value'] += (float) $order->get_total();
            }
        }
        foreach ( $groups as &$row ) { $row['avg_shipping'] = $row['orders'] ? $row['shipping_revenue'] / $row['orders'] : 0; } unset( $row );
        return array( 'columns' => array( 'shipping_method' => __( 'Shipping Method', 'woocommerce-advanced-reports' ), 'shipping_zone' => __( 'Shipping Zone', 'woocommerce-advanced-reports' ), 'currency' => __( 'Currency', 'woocommerce-advanced-reports' ), 'orders' => __( 'Shipments', 'woocommerce-advanced-reports' ), 'shipping_revenue' => __( 'Shipping Revenue', 'woocommerce-advanced-reports' ), 'avg_shipping' => __( 'Average Shipping', 'woocommerce-advanced-reports' ), 'order_value' => __( 'Order Value', 'woocommerce-advanced-reports' ) ), 'rows' => array_values( $groups ), 'summary' => array() );
    }

    public function coupons( ReportFilter $f ): array {
        $groups = array();
        foreach ( $this->orders->iterate( $f ) as $order ) {
            $codes = $order->get_coupon_codes();
            foreach ( $codes as $code ) {
                $currency = $order->get_currency();
                $key = strtolower( $code ) . '|' . $currency;
                if ( ! isset( $groups[ $key ] ) ) { $groups[ $key ] = array( 'coupon' => $code, 'currency' => $currency, 'orders' => 0, 'discount' => 0.0, 'revenue' => 0.0, 'aov' => 0.0, 'customers' => array() ); }
                $groups[ $key ]['orders']++;
                $groups[ $key ]['discount'] += (float) $order->get_discount_total() + (float) $order->get_discount_tax();
                $groups[ $key ]['revenue'] += max( 0, (float) $order->get_total() - (float) $order->get_total_refunded() );
                $groups[ $key ]['customers'][ $this->customer_identity( $order ) ] = true;
            }
        }
        foreach ( $groups as &$row ) { $row['aov'] = $row['orders'] ? $row['revenue'] / $row['orders'] : 0; $row['customers'] = count( $row['customers'] ); } unset( $row );
        return array( 'columns' => array( 'coupon' => __( 'Coupon', 'woocommerce-advanced-reports' ), 'currency' => __( 'Currency', 'woocommerce-advanced-reports' ), 'orders' => __( 'Orders', 'woocommerce-advanced-reports' ), 'customers' => __( 'Customers', 'woocommerce-advanced-reports' ), 'discount' => __( 'Discount', 'woocommerce-advanced-reports' ), 'revenue' => __( 'Net Collected', 'woocommerce-advanced-reports' ), 'aov' => __( 'AOV', 'woocommerce-advanced-reports' ) ), 'rows' => array_values( $groups ), 'summary' => array() );
    }

    public function taxes( ReportFilter $f ): array {
        $groups = array();
        foreach ( $this->orders->iterate( $f ) as $order ) {
            foreach ( $order->get_items( 'tax' ) as $tax ) {
                $currency = $order->get_currency();
                $label = $tax->get_label() ?: (string) $tax->get_rate_id();
                $country = $order->get_billing_country() ?: '--'; $state = $order->get_billing_state() ?: '--';
                $key = $label . '|' . $country . '|' . $state . '|' . $currency;
                if ( ! isset( $groups[ $key ] ) ) { $groups[ $key ] = array( 'tax_rate' => $label, 'country' => $country, 'state' => $state, 'currency' => $currency, 'orders' => 0, 'product_tax' => 0.0, 'shipping_tax' => 0.0, 'total_tax' => 0.0 ); }
                $groups[ $key ]['orders']++;
                $groups[ $key ]['product_tax'] += (float) $tax->get_tax_total();
                $groups[ $key ]['shipping_tax'] += (float) $tax->get_shipping_tax_total();
                $groups[ $key ]['total_tax'] += (float) $tax->get_tax_total() + (float) $tax->get_shipping_tax_total();
            }
        }
        return array( 'columns' => array( 'tax_rate' => __( 'Tax Rate', 'woocommerce-advanced-reports' ), 'country' => __( 'Country', 'woocommerce-advanced-reports' ), 'state' => __( 'State / Province', 'woocommerce-advanced-reports' ), 'currency' => __( 'Currency', 'woocommerce-advanced-reports' ), 'orders' => __( 'Orders', 'woocommerce-advanced-reports' ), 'product_tax' => __( 'Product Tax', 'woocommerce-advanced-reports' ), 'shipping_tax' => __( 'Shipping Tax', 'woocommerce-advanced-reports' ), 'total_tax' => __( 'Total Tax', 'woocommerce-advanced-reports' ) ), 'rows' => array_values( $groups ), 'summary' => array() );
    }

    public function refunds( ReportFilter $f ): array {
        $rows = array();
        foreach ( $this->orders->iterate_refunds( $f ) as [ $refund, $order ] ) {
            $items = array();
            foreach ( $refund->get_items( 'line_item' ) as $item ) { $items[] = $item->get_name() . ' × ' . abs( (int) $item->get_quantity() ); }
            $rows[] = array(
                'refund_id' => $refund->get_id(), 'order_id' => $order->get_id(), 'date' => $this->calendar->format( $refund->get_date_created() ),
                'customer' => $order->get_formatted_billing_full_name(), 'amount' => abs( (float) $refund->get_amount() ), 'currency' => $order->get_currency(),
                'original_total' => (float) $order->get_total(), 'items' => implode( '; ', $items ), 'reason' => $refund->get_reason(), 'payment' => $order->get_payment_method_title(),
            );
        }
        usort( $rows, static fn( $a, $b ) => $b['refund_id'] <=> $a['refund_id'] );
        return array( 'columns' => array( 'refund_id' => __( 'Refund', 'woocommerce-advanced-reports' ), 'order_id' => __( 'Order', 'woocommerce-advanced-reports' ), 'date' => __( 'Date', 'woocommerce-advanced-reports' ), 'customer' => __( 'Customer', 'woocommerce-advanced-reports' ), 'amount' => __( 'Amount', 'woocommerce-advanced-reports' ), 'currency' => __( 'Currency', 'woocommerce-advanced-reports' ), 'original_total' => __( 'Original Order', 'woocommerce-advanced-reports' ), 'items' => __( 'Refunded Items', 'woocommerce-advanced-reports' ), 'reason' => __( 'Reason', 'woocommerce-advanced-reports' ), 'payment' => __( 'Payment Method', 'woocommerce-advanced-reports' ) ), 'rows' => $rows, 'summary' => array() );
    }

    public function failed_cancelled( ReportFilter $f ): array {
        $clone = clone $f;
        $clone->statuses = array( 'failed', 'cancelled' );
        return $this->order_details( $clone );
    }

    public function geography( ReportFilter $f ): array {
        $groups = array();
        foreach ( $this->orders->iterate( $f ) as $order ) {
            $country = $order->get_billing_country() ?: '--';
            $state = $order->get_billing_state() ?: '--';
            $city = $order->get_billing_city() ?: '--';
            $currency = $order->get_currency();
            $key = implode( '|', array( $country, $state, $city, $currency ) );
            if ( ! isset( $groups[ $key ] ) ) { $groups[ $key ] = array( 'country' => $country, 'state' => $state, 'city' => $city, 'currency' => $currency, 'orders' => 0, 'customers' => array(), 'items' => 0, 'revenue' => 0.0 ); }
            $groups[ $key ]['orders']++;
            $groups[ $key ]['customers'][ $this->customer_identity( $order ) ] = true;
            $groups[ $key ]['items'] += $this->order_metrics( $order )['items'];
            $groups[ $key ]['revenue'] += max( 0, (float) $order->get_total() - (float) $order->get_total_refunded() );
        }
        foreach ( $groups as &$row ) { $row['customers'] = count( $row['customers'] ); $row['aov'] = $row['orders'] ? $row['revenue'] / $row['orders'] : 0; } unset( $row );
        usort( $groups, static fn( $a, $b ) => $b['revenue'] <=> $a['revenue'] );
        return array( 'columns' => array( 'country' => __( 'Country', 'woocommerce-advanced-reports' ), 'state' => __( 'State / Province', 'woocommerce-advanced-reports' ), 'city' => __( 'City', 'woocommerce-advanced-reports' ), 'currency' => __( 'Currency', 'woocommerce-advanced-reports' ), 'orders' => __( 'Orders', 'woocommerce-advanced-reports' ), 'customers' => __( 'Customers', 'woocommerce-advanced-reports' ), 'items' => __( 'Items', 'woocommerce-advanced-reports' ), 'revenue' => __( 'Net Collected', 'woocommerce-advanced-reports' ), 'aov' => __( 'AOV', 'woocommerce-advanced-reports' ) ), 'rows' => array_values( $groups ), 'summary' => array() );
    }

    public function product_sales( ReportFilter $f ): array {
        $rows = $this->aggregate_products( $f, false );
        usort( $rows, static fn( $a, $b ) => $b['net_sales'] <=> $a['net_sales'] );
        return array( 'columns' => $this->product_sales_columns(), 'rows' => $rows, 'summary' => array() );
    }

    public function product_variations( ReportFilter $f ): array {
        $rows = array_values( array_filter( $this->aggregate_products( $f, true ), static fn( $r ) => ! empty( $r['variation_id'] ) ) );
        usort( $rows, static fn( $a, $b ) => $b['net_sales'] <=> $a['net_sales'] );
        return array( 'columns' => $this->product_sales_columns(), 'rows' => $rows, 'summary' => array() );
    }

    public function best_products( ReportFilter $f ): array { return $this->product_sales( $f ); }

    public function worst_products( ReportFilter $f ): array {
        $result = $this->product_sales( $f );
        $result['rows'] = array_values( array_filter( $result['rows'], static fn( $r ) => $r['quantity_sold'] > 0 ) );
        usort( $result['rows'], static fn( $a, $b ) => $a['net_sales'] <=> $b['net_sales'] );
        return $result;
    }

    public function products_no_sales( ReportFilter $f ): array {
        $sold = array();
        foreach ( $this->aggregate_products( $f, false ) as $row ) { $sold[ (int) $row['product_id'] ] = true; }
        $rows = array();
        foreach ( $this->products->iterate( $f ) as $product ) {
            $parent_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
            if ( isset( $sold[ $parent_id ] ) || isset( $sold[ $product->get_id() ] ) ) { continue; }
            $rows[] = array( 'product_id' => $product->get_id(), 'sku' => $product->get_sku(), 'product' => $product->get_name(), 'type' => $product->get_type(), 'category' => $this->products->categories_for_product( $parent_id ), 'price' => (float) $product->get_price(), 'stock' => $product->managing_stock() ? (int) $product->get_stock_quantity() : '', 'stock_status' => wc_get_product_stock_status_options()[ $product->get_stock_status() ] ?? $product->get_stock_status() );
        }
        return array( 'columns' => array( 'product_id' => __( 'Product ID', 'woocommerce-advanced-reports' ), 'sku' => __( 'SKU', 'woocommerce-advanced-reports' ), 'product' => __( 'Product', 'woocommerce-advanced-reports' ), 'type' => __( 'Type', 'woocommerce-advanced-reports' ), 'category' => __( 'Category', 'woocommerce-advanced-reports' ), 'price' => __( 'Current Price', 'woocommerce-advanced-reports' ), 'stock' => __( 'Stock', 'woocommerce-advanced-reports' ), 'stock_status' => __( 'Stock Status', 'woocommerce-advanced-reports' ) ), 'rows' => $rows, 'summary' => array() );
    }

    public function inventory( ReportFilter $f ): array {
        $rows = array();
        $activity = $this->product_activity( $f );
        $period_days = max( 1, (int) ceil( ( $f->to->getTimestamp() - $f->from->getTimestamp() + 1 ) / DAY_IN_SECONDS ) );
        foreach ( $this->products->iterate( $f ) as $product ) {
            $parent_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
            $qty = $product->managing_stock() ? $product->get_stock_quantity() : null;
            $retail = null !== $qty ? (float) $product->get_price() * (int) $qty : null;
            $a = $activity[ $product->get_id() ] ?? array( 'units' => 0, 'last_ts' => 0 );
            $avg_daily = $period_days > 0 ? (float) $a['units'] / $period_days : 0.0;
            $coverage = null !== $qty && $avg_daily > 0 ? round( (int) $qty / $avg_daily, 1 ) : '';
            $rows[] = array(
                'product_id' => $product->get_id(), 'product' => $product->get_name(), 'sku' => $product->get_sku(), 'type' => $product->get_type(),
                'category' => $this->products->categories_for_product( $parent_id ), 'stock' => null === $qty ? __( 'Not managed', 'woocommerce-advanced-reports' ) : (int) $qty,
                'stock_status' => wc_get_product_stock_status_options()[ $product->get_stock_status() ] ?? $product->get_stock_status(), 'low_stock' => $product->get_low_stock_amount(),
                'backorders' => $product->get_backorders(), 'regular_price' => (float) $product->get_regular_price(), 'sale_price' => '' === $product->get_sale_price() ? '' : (float) $product->get_sale_price(),
                'retail_value' => $retail, 'cost_value' => $this->product_cost_value( $product, $qty ), 'units_sold' => (int) $a['units'],
                'last_sale' => $a['last_ts'] ? $this->calendar->format( (int) $a['last_ts'] ) : '', 'stock_coverage_days' => $coverage,
            );
        }
        return array( 'columns' => $this->inventory_columns(), 'rows' => $rows, 'summary' => array() );
    }

    public function low_stock( ReportFilter $f ): array {
        $result = $this->inventory( $f );
        $result['rows'] = array_values( array_filter( $result['rows'], static function ( $r ) {
            if ( ! is_numeric( $r['stock'] ) ) { return false; }
            $threshold = '' !== $r['low_stock'] && null !== $r['low_stock'] ? (int) $r['low_stock'] : (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );
            return (int) $r['stock'] > 0 && (int) $r['stock'] <= $threshold;
        } ) );
        return $result;
    }

    public function out_of_stock( ReportFilter $f ): array {
        $result = $this->inventory( $f );
        $result['rows'] = array_values( array_filter( $result['rows'], static fn( $r ) => false !== stripos( (string) $r['stock_status'], 'out' ) || ( is_numeric( $r['stock'] ) && (int) $r['stock'] <= 0 ) ) );
        return $result;
    }

    public function dead_stock( ReportFilter $f ): array {
        $sales_filter = clone $f;
        $sales_filter->from = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->modify( '-' . $f->dead_stock_days . ' days' )->setTime( 0, 0, 0 );
        $sales_filter->to = new \DateTimeImmutable( 'now', wp_timezone() );
        $sold = array();
        foreach ( $this->aggregate_products( $sales_filter, false ) as $row ) { $sold[ (int) $row['product_id'] ] = (int) $row['quantity_sold']; }
        $result = $this->inventory( $f );
        $result['columns']['units_sold_window'] = sprintf( __( 'Units Sold (%d days)', 'woocommerce-advanced-reports' ), $f->dead_stock_days );
        $result['rows'] = array_values( array_filter( array_map( static function ( $r ) use ( $sold ) {
            $r['units_sold_window'] = $sold[ (int) $r['product_id'] ] ?? 0;
            return $r;
        }, $result['rows'] ), static fn( $r ) => is_numeric( $r['stock'] ) && (int) $r['stock'] > 0 && (int) $r['units_sold_window'] <= $f->dead_stock_max_sold ) );
        return $result;
    }

    public function category_sales( ReportFilter $f ): array { return $this->taxonomy_sales( $f, 'product_cat', __( 'Category', 'woocommerce-advanced-reports' ) ); }
    public function tag_sales( ReportFilter $f ): array { return $this->taxonomy_sales( $f, 'product_tag', __( 'Tag', 'woocommerce-advanced-reports' ) ); }
    public function brand_sales( ReportFilter $f ): array {
        foreach ( array( 'product_brand', 'pwb-brand', 'yith_product_brand' ) as $taxonomy ) { if ( taxonomy_exists( $taxonomy ) ) { return $this->taxonomy_sales( $f, $taxonomy, __( 'Brand', 'woocommerce-advanced-reports' ) ); } }
        return array( 'columns'=>array( 'group'=>__( 'Brand', 'woocommerce-advanced-reports' ) ), 'rows'=>array(), 'summary'=>array(), 'note'=>__( 'No supported product brand taxonomy is registered on this store.', 'woocommerce-advanced-reports' ) );
    }

    private function taxonomy_sales( ReportFilter $f, string $taxonomy, string $label ): array {
        $groups = array();
        foreach ( $this->orders->iterate( $f ) as $order ) {
            $currency = $order->get_currency();
            foreach ( $order->get_items( 'line_item' ) as $item ) {
                $terms = taxonomy_exists( $taxonomy ) ? wp_get_post_terms( $item->get_product_id(), $taxonomy ) : array();
                if ( ! $terms || is_wp_error( $terms ) ) { $terms = array( (object) array( 'term_id' => 0, 'name' => __( 'Unassigned', 'woocommerce-advanced-reports' ) ) ); }
                foreach ( $terms as $term ) {
                    $key = $term->term_id . '|' . $currency;
                    if ( ! isset( $groups[ $key ] ) ) { $groups[ $key ] = array( 'group' => $term->name, 'currency' => $currency, 'orders' => array(), 'units' => 0, 'gross_sales' => 0.0, 'refunds' => 0.0, 'net_sales' => 0.0 ); }
                    $groups[ $key ]['orders'][ $order->get_id() ] = true;
                    $groups[ $key ]['units'] += (int) $item->get_quantity();
                    $groups[ $key ]['gross_sales'] += (float) $item->get_subtotal();
                    $refund_amount = abs( (float) $order->get_total_refunded_for_item( $item->get_id() ) );
                    $groups[ $key ]['refunds'] += $refund_amount;
                    $groups[ $key ]['net_sales'] += max( 0, (float) $item->get_total() - $refund_amount );
                }
            }
        }
        foreach ( $groups as &$row ) { $row['orders'] = count( $row['orders'] ); } unset( $row );
        usort( $groups, static fn( $a, $b ) => $b['net_sales'] <=> $a['net_sales'] );
        return array( 'columns' => array( 'group' => $label, 'currency' => __( 'Currency', 'woocommerce-advanced-reports' ), 'orders' => __( 'Orders', 'woocommerce-advanced-reports' ), 'units' => __( 'Units', 'woocommerce-advanced-reports' ), 'gross_sales' => __( 'Gross Sales', 'woocommerce-advanced-reports' ), 'refunds' => __( 'Refunds', 'woocommerce-advanced-reports' ), 'net_sales' => __( 'Net Sales', 'woocommerce-advanced-reports' ) ), 'rows' => array_values( $groups ), 'summary' => array() );
    }

    public function product_refunds( ReportFilter $f ): array {
        $groups = array();
        foreach ( $this->orders->iterate_refunds( $f ) as [ $refund, $order ] ) {
            foreach ( $refund->get_items( 'line_item' ) as $item ) {
                $product = $item->get_product();
                $pid = $item->get_product_id(); $vid = $item->get_variation_id(); $currency = $order->get_currency();
                $key = ( $vid ?: $pid ) . '|' . $currency;
                if ( ! isset( $groups[ $key ] ) ) { $groups[ $key ] = array( 'product_id' => $pid, 'variation_id' => $vid ?: '', 'sku' => $product ? $product->get_sku() : '', 'product' => $item->get_name(), 'currency' => $currency, 'refund_count' => 0, 'quantity_refunded' => 0, 'refund_amount' => 0.0 ); }
                $groups[ $key ]['refund_count']++;
                $groups[ $key ]['quantity_refunded'] += abs( (int) $item->get_quantity() );
                $groups[ $key ]['refund_amount'] += abs( (float) $item->get_total() + (float) $item->get_total_tax() );
            }
        }
        $sales = $this->aggregate_products( $f, false ); $sold = array();
        foreach ( $sales as $row ) { $sold[(int)($row['variation_id'] ?: $row['product_id']) . '|' . $row['currency']] = (int)$row['quantity_sold']; }
        foreach ( $groups as $key => &$row ) { $base = $sold[$key] ?? 0; $row['refund_rate'] = ( $base + $row['quantity_refunded'] ) > 0 ? round( 100 * $row['quantity_refunded'] / ( $base + $row['quantity_refunded'] ), 1 ) : 0; } unset($row);
        usort( $groups, static fn( $a, $b ) => $b['refund_amount'] <=> $a['refund_amount'] );
        return array( 'columns' => array( 'product_id' => __( 'Product ID', 'woocommerce-advanced-reports' ), 'variation_id' => __( 'Variation ID', 'woocommerce-advanced-reports' ), 'sku' => __( 'SKU', 'woocommerce-advanced-reports' ), 'product' => __( 'Product', 'woocommerce-advanced-reports' ), 'currency' => __( 'Currency', 'woocommerce-advanced-reports' ), 'refund_count' => __( 'Refund Lines', 'woocommerce-advanced-reports' ), 'quantity_refunded' => __( 'Quantity Refunded', 'woocommerce-advanced-reports' ), 'refund_amount' => __( 'Refund Amount', 'woocommerce-advanced-reports' ), 'refund_rate' => __( 'Refund Rate %', 'woocommerce-advanced-reports' ) ), 'rows' => array_values( $groups ), 'summary' => array() );
    }

    public function customer_list( ReportFilter $f ): array {
        $groups = $this->aggregate_customers( $f, false );
        return array( 'columns' => $this->customer_columns(), 'rows' => array_values( $groups ), 'summary' => array() );
    }

    public function top_customers( ReportFilter $f ): array {
        $result = $this->customer_list( $f );
        usort( $result['rows'], static fn( $a, $b ) => $b['net_spend'] <=> $a['net_spend'] );
        return $result;
    }

    public function new_returning( ReportFilter $f ): array {
        $groups = array();
        foreach ( $this->aggregate_customers( $f, false ) as $customer ) {
            $type = (int) $customer['first_order_ts'] >= $f->from->getTimestamp() ? 'new' : 'returning';
            $registration = 'guest' === $customer['customer_type'] ? 'guest' : 'registered';
            $currency = $customer['currency'];
            $key = $type . '|' . $registration . '|' . $currency;
            if ( ! isset( $groups[ $key ] ) ) { $groups[ $key ] = array( 'customer_segment' => ucfirst( $type ), 'registration' => ucfirst( $registration ), 'currency' => $currency, 'customers' => 0, 'orders' => 0, 'revenue' => 0.0 ); }
            $groups[ $key ]['customers']++;
            $groups[ $key ]['orders'] += (int) $customer['orders'];
            $groups[ $key ]['revenue'] += (float) $customer['net_spend'];
        }
        return array( 'columns' => array( 'customer_segment' => __( 'Customer Segment', 'woocommerce-advanced-reports' ), 'registration' => __( 'Account Type', 'woocommerce-advanced-reports' ), 'currency' => __( 'Currency', 'woocommerce-advanced-reports' ), 'customers' => __( 'Customers', 'woocommerce-advanced-reports' ), 'orders' => __( 'Orders', 'woocommerce-advanced-reports' ), 'revenue' => __( 'Net Spend', 'woocommerce-advanced-reports' ) ), 'rows' => array_values( $groups ), 'summary' => array() );
    }

    public function customer_ltv( ReportFilter $f ): array {
        $groups = $this->aggregate_customers( $f, true );
        $rows = array_values( $groups );
        usort( $rows, static fn( $a, $b ) => $b['net_spend'] <=> $a['net_spend'] );
        return array( 'columns' => $this->customer_columns(), 'rows' => $rows, 'summary' => array(), 'note' => __( 'Lifetime values include qualifying order history up to the selected end date.', 'woocommerce-advanced-reports' ) );
    }

    public function purchase_frequency( ReportFilter $f ): array {
        $groups = $this->aggregate_customers( $f, true );
        $rows = array();
        foreach ( $groups as $c ) {
            $days = max( 0, (int) round( ( $c['last_order_ts'] - $c['first_order_ts'] ) / DAY_IN_SECONDS ) );
            $c['avg_days_between_orders'] = $c['orders'] > 1 ? round( $days / ( $c['orders'] - 1 ), 1 ) : '';
            $rows[] = $c;
        }
        $columns = $this->customer_columns();
        $columns['avg_days_between_orders'] = __( 'Avg Days Between Orders', 'woocommerce-advanced-reports' );
        return array( 'columns' => $columns, 'rows' => $rows, 'summary' => array() );
    }

    public function inactive_customers( ReportFilter $f ): array {
        $clone = clone $f;
        $clone->to = new \DateTimeImmutable( 'now', wp_timezone() );
        $groups = $this->aggregate_customers( $clone, true );
        $cutoff = $clone->to->modify( '-' . $f->inactive_days . ' days' )->getTimestamp();
        $rows = array_values( array_filter( $groups, static fn( $c ) => $c['last_order_ts'] < $cutoff ) );
        foreach ( $rows as &$row ) { $row['inactive_days'] = (int) floor( ( time() - $row['last_order_ts'] ) / DAY_IN_SECONDS ); } unset( $row );
        usort( $rows, static fn( $a, $b ) => $b['inactive_days'] <=> $a['inactive_days'] );
        $columns = $this->customer_columns(); $columns['inactive_days'] = __( 'Inactive Days', 'woocommerce-advanced-reports' );
        return array( 'columns' => $columns, 'rows' => $rows, 'summary' => array() );
    }

    public function rfm( ReportFilter $f ): array {
        $groups = array_values( $this->aggregate_customers( $f, true ) );
        if ( ! $groups ) { return array( 'columns' => array(), 'rows' => array(), 'summary' => array() ); }
        $recencies = $frequencies = $monetaries = array();
        foreach ( $groups as $c ) {
            $recencies[] = max( 0, (int) floor( ( $f->to->getTimestamp() - $c['last_order_ts'] ) / DAY_IN_SECONDS ) );
            $frequencies[] = (int) $c['orders'];
            $monetaries[] = (float) $c['net_spend'];
        }
        sort( $recencies ); sort( $frequencies ); sort( $monetaries );
        $rows = array();
        foreach ( $groups as $c ) {
            $recency = max( 0, (int) floor( ( $f->to->getTimestamp() - $c['last_order_ts'] ) / DAY_IN_SECONDS ) );
            $r = 6 - $this->quintile( $recency, $recencies );
            $freq = $this->quintile( (int) $c['orders'], $frequencies );
            $mon = $this->quintile( (float) $c['net_spend'], $monetaries );
            $score = "{$r}{$freq}{$mon}";
            $rows[] = array_merge( $c, array( 'recency_days' => $recency, 'r_score' => $r, 'f_score' => $freq, 'm_score' => $mon, 'rfm_score' => $score, 'segment' => $this->rfm_segment( $r, $freq, $mon ) ) );
        }
        usort( $rows, static fn( $a, $b ) => (int) $b['rfm_score'] <=> (int) $a['rfm_score'] );
        return array( 'columns' => array_merge( $this->customer_columns(), array( 'recency_days' => __( 'Recency Days', 'woocommerce-advanced-reports' ), 'r_score' => 'R', 'f_score' => 'F', 'm_score' => 'M', 'rfm_score' => __( 'RFM Score', 'woocommerce-advanced-reports' ), 'segment' => __( 'Segment', 'woocommerce-advanced-reports' ) ) ), 'rows' => $rows, 'summary' => array() );
    }

    public function cohorts( ReportFilter $f ): array {
        $customers = $this->aggregate_customers( $f, true, true );
        $groups = array();
        foreach ( $customers as $c ) {
            $cohort = wp_date( 'Y-m', $c['first_order_ts'], wp_timezone() );
            $currency = $c['currency'];
            $key = $cohort . '|' . $currency;
            if ( ! isset( $groups[ $key ] ) ) { $groups[ $key ] = array( 'cohort' => $cohort, 'currency' => $currency, 'customers' => 0, 'orders' => 0, 'lifetime_revenue' => 0.0, 'repeat_customers' => 0 ); }
            $groups[ $key ]['customers']++;
            $groups[ $key ]['orders'] += $c['orders'];
            $groups[ $key ]['lifetime_revenue'] += $c['net_spend'];
            if ( $c['orders'] > 1 ) { $groups[ $key ]['repeat_customers']++; }
        }
        foreach ( $groups as &$row ) { $row['repeat_rate'] = $row['customers'] ? round( 100 * $row['repeat_customers'] / $row['customers'], 1 ) : 0; } unset( $row );
        ksort( $groups );
        return array( 'columns' => array( 'cohort' => __( 'First Purchase Cohort', 'woocommerce-advanced-reports' ), 'currency' => __( 'Currency', 'woocommerce-advanced-reports' ), 'customers' => __( 'Customers', 'woocommerce-advanced-reports' ), 'orders' => __( 'Lifetime Orders', 'woocommerce-advanced-reports' ), 'lifetime_revenue' => __( 'Lifetime Net Spend', 'woocommerce-advanced-reports' ), 'repeat_customers' => __( 'Repeat Customers', 'woocommerce-advanced-reports' ), 'repeat_rate' => __( 'Repeat Rate %', 'woocommerce-advanced-reports' ) ), 'rows' => array_values( $groups ), 'summary' => array() );
    }

    private function aggregate_products( ReportFilter $f, bool $variations_only ): array {
        $groups = array();
        foreach ( $this->orders->iterate( $f ) as $order ) {
            $currency = $order->get_currency();
            foreach ( $order->get_items( 'line_item' ) as $item ) {
                $pid = $item->get_product_id(); $vid = $item->get_variation_id();
                if ( $variations_only && ! $vid ) { continue; }
                $product = $item->get_product();
                $key = ( $vid ?: $pid ) . '|' . $currency;
                if ( ! isset( $groups[ $key ] ) ) {
                    $parent = wc_get_product( $pid );
                    $groups[ $key ] = array(
                        'product_id' => $pid, 'variation_id' => $vid ?: '', 'sku' => $product ? $product->get_sku() : '', 'product' => $item->get_name(),
                        'type' => $product ? $product->get_type() : '', 'category' => $this->products->categories_for_product( $pid ), 'currency' => $currency,
                        'quantity_sold' => 0, 'orders' => array(), 'gross_sales' => 0.0, 'discounts' => 0.0, 'refunds' => 0.0, 'net_sales' => 0.0,
                        'avg_selling_price' => 0.0, 'regular_price' => $product ? (float) $product->get_regular_price() : 0.0, 'sale_price' => $product && '' !== $product->get_sale_price() ? (float) $product->get_sale_price() : '',
                        'stock' => $product && $product->managing_stock() ? $product->get_stock_quantity() : '', 'stock_status' => $product ? ( wc_get_product_stock_status_options()[ $product->get_stock_status() ] ?? $product->get_stock_status() ) : '',
                    );
                }
                $qty = (int) $item->get_quantity();
                $refunded_qty = abs( (int) $order->get_qty_refunded_for_item( $item->get_id() ) );
                $refund_amount = abs( (float) $order->get_total_refunded_for_item( $item->get_id() ) );
                $groups[ $key ]['quantity_sold'] += max( 0, $qty - $refunded_qty );
                $groups[ $key ]['orders'][ $order->get_id() ] = true;
                $groups[ $key ]['gross_sales'] += (float) $item->get_subtotal();
                $groups[ $key ]['discounts'] += max( 0, (float) $item->get_subtotal() - (float) $item->get_total() );
                $groups[ $key ]['refunds'] += $refund_amount;
                $groups[ $key ]['net_sales'] += max( 0, (float) $item->get_total() - $refund_amount );
            }
        }
        foreach ( $groups as &$row ) {
            $row['orders'] = count( $row['orders'] );
            $row['avg_selling_price'] = $row['quantity_sold'] ? $row['net_sales'] / $row['quantity_sold'] : 0;
        }
        unset( $row );
        return array_values( $groups );
    }

    private function aggregate_customers( ReportFilter $f, bool $all_history, bool $include_cohorts_outside_range = false ): array {
        $groups = array();
        $iterator = $all_history ? $this->orders->iterate_all_until( $f->to, $f->statuses ?: array( 'processing', 'completed' ) ) : $this->orders->iterate( $f );
        foreach ( $iterator as $order ) {
            if ( $all_history && ! $include_cohorts_outside_range && $f->currency && $order->get_currency() !== $f->currency ) { continue; }
            $identity = $this->customer_identity( $order );
            $currency = $order->get_currency();
            $key = $identity . '|' . $currency;
            $ts = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
            if ( ! isset( $groups[ $key ] ) ) {
                $groups[ $key ] = array(
                    'customer_id' => (int) $order->get_customer_id() ?: '', 'name' => trim( $order->get_formatted_billing_full_name() ), 'email' => Format::mask_email( (string) $order->get_billing_email() ),
                    'phone' => Format::mask_phone( (string) $order->get_billing_phone() ), 'customer_type' => $order->get_customer_id() ? 'registered' : 'guest', 'currency' => $currency,
                    '_identity' => $identity, 'registration_date' => $this->customer_registration_date( (int) $order->get_customer_id() ),
                    'first_order' => $this->calendar->format( $ts ), 'last_order' => $this->calendar->format( $ts ), 'first_order_ts' => $ts, 'last_order_ts' => $ts,
                    'orders' => 0, 'items' => 0, 'gross_spend' => 0.0, 'refunds' => 0.0, 'net_spend' => 0.0, 'aov' => 0.0, 'country' => $order->get_billing_country(), 'city' => $order->get_billing_city(),
                );
            }
            $m = $this->order_metrics( $order );
            $groups[ $key ]['orders']++;
            $groups[ $key ]['items'] += $m['items'];
            $groups[ $key ]['gross_spend'] += (float) $order->get_total();
            $groups[ $key ]['refunds'] += $m['refunds'];
            $groups[ $key ]['net_spend'] += $m['net_sales'];
            if ( $ts && $ts < $groups[ $key ]['first_order_ts'] ) { $groups[ $key ]['first_order_ts'] = $ts; $groups[ $key ]['first_order'] = $this->calendar->format( $ts ); }
            if ( $ts > $groups[ $key ]['last_order_ts'] ) { $groups[ $key ]['last_order_ts'] = $ts; $groups[ $key ]['last_order'] = $this->calendar->format( $ts ); }
        }
        foreach ( $groups as &$row ) {
            if ( ! $all_history ) {
                $identity = $row['customer_id'] ? (string) $row['customer_id'] : ( 0 === strpos( (string) $row['_identity'], 'e:' ) ? substr( (string) $row['_identity'], 2 ) : '' );
                $first = $identity ? $this->orders->first_order_timestamp( $identity ) : null;
                if ( $first ) { $row['first_order_ts'] = $first; $row['first_order'] = $this->calendar->format( $first ); }
            }
            $row['aov'] = $row['orders'] ? $row['net_spend'] / $row['orders'] : 0;
        }
        unset( $row );
        return $groups;
    }

    private function order_metrics( \WC_Order $order ): array {
        $subtotal = 0.0; $line_total = 0.0; $items = 0;
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $subtotal += (float) $item->get_subtotal();
            $line_total += (float) $item->get_total();
            $items += max( 0, (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item( $item->get_id() ) );
        }
        $shipping = (float) $order->get_shipping_total();
        $tax = (float) $order->get_total_tax();
        $refunds = (float) $order->get_total_refunded();
        return array(
            'line_subtotal' => $subtotal, 'gross_sales' => $subtotal + $shipping, 'discounts' => max( 0, $subtotal - $line_total ), 'shipping' => $shipping,
            'tax' => $tax, 'refunds' => $refunds, 'order_total' => (float) $order->get_total(), 'net_sales' => max( 0, (float) $order->get_total() - $refunds ), 'items' => $items,
        );
    }

    private function zero_sales_group( string $currency ): array {
        return array( 'currency' => $currency, 'orders' => 0, 'items' => 0, 'gross_sales' => 0.0, 'discounts' => 0.0, 'shipping' => 0.0, 'tax' => 0.0, 'refunds' => 0.0, 'net_sales' => 0.0, 'order_total' => 0.0, 'guest_orders' => 0, 'aov' => 0.0 );
    }

    private function summary_from_currency_groups( array $groups ): array {
        if ( 1 === count( $groups ) ) {
            $row = reset( $groups );
            return array( 'currency' => $row['currency'], 'gross_sales' => $row['gross_sales'], 'net_sales' => $row['net_sales'], 'orders' => $row['orders'], 'items' => $row['items'], 'refunds' => $row['refunds'], 'discounts' => $row['discounts'], 'shipping' => $row['shipping'], 'tax' => $row['tax'], 'aov' => $row['aov'] );
        }
        return array( 'currency' => '', 'gross_sales' => null, 'net_sales' => null, 'orders' => array_sum( array_column( $groups, 'orders' ) ), 'items' => array_sum( array_column( $groups, 'items' ) ), 'refunds' => null, 'discounts' => null, 'shipping' => null, 'tax' => null, 'aov' => null );
    }

    private function date_bucket( int $timestamp, string $group_by ): string {
        $dt = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() );
        switch ( $group_by ) {
            case 'hour':
                return $this->calendar->format( $timestamp, 'Y-m-d' ) . ' ' . $dt->format( 'H:00' );
            case 'week':
                $settings = wp_parse_args( (array) get_option( 'wcar_settings', array() ), \WCAR\Installer::default_settings() );
                $start_day = (int) ( $settings['first_day_of_week'] ?? 1 );
                $delta = ( (int) $dt->format( 'w' ) - $start_day + 7 ) % 7;
                return $this->calendar->format( $dt->modify( '-' . $delta . ' days' ), 'Y-m-d' );
            case 'month':
                return $this->calendar->format( $timestamp, 'Y-m' );
            case 'quarter':
                $ym = $this->calendar->format( $timestamp, 'Y-n' );
                [ $year, $month ] = array_map( 'intval', explode( '-', $ym ) );
                return $year . '-Q' . (int) ceil( $month / 3 );
            case 'year':
                return $this->calendar->format( $timestamp, 'Y' );
            default:
                return $this->calendar->format( $timestamp, 'Y-m-d' );
        }
    }

    private function customer_identity( \WC_Order $order ): string {
        if ( $order->get_customer_id() ) { return 'u:' . $order->get_customer_id(); }
        $email = strtolower( trim( (string) $order->get_billing_email() ) );
        return $email ? 'e:' . $email : 'o:' . $order->get_id();
    }

    private function product_sales_columns(): array {
        return array( 'product_id' => __( 'Product ID', 'woocommerce-advanced-reports' ), 'variation_id' => __( 'Variation ID', 'woocommerce-advanced-reports' ), 'sku' => __( 'SKU', 'woocommerce-advanced-reports' ), 'product' => __( 'Product', 'woocommerce-advanced-reports' ), 'type' => __( 'Type', 'woocommerce-advanced-reports' ), 'category' => __( 'Category', 'woocommerce-advanced-reports' ), 'currency' => __( 'Currency', 'woocommerce-advanced-reports' ), 'quantity_sold' => __( 'Quantity Sold', 'woocommerce-advanced-reports' ), 'orders' => __( 'Orders', 'woocommerce-advanced-reports' ), 'gross_sales' => __( 'Gross Sales', 'woocommerce-advanced-reports' ), 'discounts' => __( 'Discounts', 'woocommerce-advanced-reports' ), 'refunds' => __( 'Refunds', 'woocommerce-advanced-reports' ), 'net_sales' => __( 'Net Sales', 'woocommerce-advanced-reports' ), 'avg_selling_price' => __( 'Average Selling Price', 'woocommerce-advanced-reports' ), 'regular_price' => __( 'Regular Price', 'woocommerce-advanced-reports' ), 'sale_price' => __( 'Sale Price', 'woocommerce-advanced-reports' ), 'stock' => __( 'Current Stock', 'woocommerce-advanced-reports' ), 'stock_status' => __( 'Stock Status', 'woocommerce-advanced-reports' ) );
    }

    private function inventory_columns(): array {
        return array( 'product_id' => __( 'Product ID', 'woocommerce-advanced-reports' ), 'product' => __( 'Product', 'woocommerce-advanced-reports' ), 'sku' => __( 'SKU', 'woocommerce-advanced-reports' ), 'type' => __( 'Type', 'woocommerce-advanced-reports' ), 'category' => __( 'Category', 'woocommerce-advanced-reports' ), 'stock' => __( 'Stock Quantity', 'woocommerce-advanced-reports' ), 'stock_status' => __( 'Stock Status', 'woocommerce-advanced-reports' ), 'low_stock' => __( 'Low Stock Threshold', 'woocommerce-advanced-reports' ), 'backorders' => __( 'Backorders', 'woocommerce-advanced-reports' ), 'regular_price' => __( 'Regular Price', 'woocommerce-advanced-reports' ), 'sale_price' => __( 'Sale Price', 'woocommerce-advanced-reports' ), 'retail_value' => __( 'Inventory Retail Value', 'woocommerce-advanced-reports' ), 'cost_value' => __( 'Inventory Cost Value', 'woocommerce-advanced-reports' ), 'units_sold' => __( 'Units Sold', 'woocommerce-advanced-reports' ), 'last_sale' => __( 'Last Sale', 'woocommerce-advanced-reports' ), 'stock_coverage_days' => __( 'Stock Coverage (days)', 'woocommerce-advanced-reports' ) );
    }

    private function customer_columns(): array {
        return array( 'customer_id' => __( 'Customer ID', 'woocommerce-advanced-reports' ), 'name' => __( 'Name', 'woocommerce-advanced-reports' ), 'email' => __( 'Email', 'woocommerce-advanced-reports' ), 'phone' => __( 'Phone', 'woocommerce-advanced-reports' ), 'customer_type' => __( 'Type', 'woocommerce-advanced-reports' ), 'currency' => __( 'Currency', 'woocommerce-advanced-reports' ), 'registration_date' => __( 'Registration Date', 'woocommerce-advanced-reports' ), 'first_order' => __( 'First Order', 'woocommerce-advanced-reports' ), 'last_order' => __( 'Last Order', 'woocommerce-advanced-reports' ), 'orders' => __( 'Orders', 'woocommerce-advanced-reports' ), 'items' => __( 'Items', 'woocommerce-advanced-reports' ), 'gross_spend' => __( 'Gross Spend', 'woocommerce-advanced-reports' ), 'refunds' => __( 'Refunds', 'woocommerce-advanced-reports' ), 'net_spend' => __( 'Net Spend', 'woocommerce-advanced-reports' ), 'aov' => __( 'AOV', 'woocommerce-advanced-reports' ), 'country' => __( 'Country', 'woocommerce-advanced-reports' ), 'city' => __( 'City', 'woocommerce-advanced-reports' ) );
    }

    private function product_cost_value( \WC_Product $product, $qty ) {
        if ( null === $qty ) { return ''; }
        $cost = '';
        foreach ( array( '_cogs_total_value', '_wc_cog_cost', '_alg_wc_cog_cost' ) as $meta_key ) {
            $value = $product->get_meta( $meta_key, true );
            if ( '' !== $value && is_numeric( $value ) ) { $cost = (float) $value; break; }
        }
        return '' === $cost ? '' : $cost * (int) $qty;
    }

    private function product_activity( ReportFilter $f ): array {
        $activity = array();
        foreach ( $this->orders->iterate( $f ) as $order ) {
            $ts = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
            foreach ( $order->get_items( 'line_item' ) as $item ) {
                $qty = max( 0, (int) $item->get_quantity() - abs( (int) $order->get_qty_refunded_for_item( $item->get_id() ) ) );
                foreach ( array_unique( array_filter( array( (int) $item->get_product_id(), (int) $item->get_variation_id() ) ) ) as $id ) {
                    if ( ! isset( $activity[ $id ] ) ) { $activity[ $id ] = array( 'units' => 0, 'last_ts' => 0 ); }
                    $activity[ $id ]['units'] += $qty;
                    $activity[ $id ]['last_ts'] = max( $activity[ $id ]['last_ts'], $ts );
                }
            }
        }
        return $activity;
    }

    private function customer_registration_date( int $user_id ): string {
        if ( ! $user_id ) { return ''; }
        $user = get_userdata( $user_id );
        if ( ! $user || ! $user->user_registered ) { return ''; }
        return $this->calendar->format( $user->user_registered );
    }

    private function quintile( $value, array $sorted ): int {
        $n = count( $sorted ); if ( $n < 2 ) { return 5; }
        $rank = 0;
        foreach ( $sorted as $i => $v ) { if ( $value >= $v ) { $rank = $i; } else { break; } }
        return min( 5, max( 1, (int) floor( 5 * $rank / max( 1, $n - 1 ) ) + 1 ) );
    }

    private function rfm_segment( int $r, int $f, int $m ): string {
        if ( $r >= 4 && $f >= 4 && $m >= 4 ) { return __( 'Champions', 'woocommerce-advanced-reports' ); }
        if ( $r >= 3 && $f >= 4 ) { return __( 'Loyal Customers', 'woocommerce-advanced-reports' ); }
        if ( $r >= 4 && $f <= 3 ) { return __( 'Potential Loyalists', 'woocommerce-advanced-reports' ); }
        if ( $r >= 4 && 1 === $f ) { return __( 'Recent Customers', 'woocommerce-advanced-reports' ); }
        if ( $r <= 2 && $f >= 3 ) { return __( 'At Risk', 'woocommerce-advanced-reports' ); }
        if ( $r <= 2 && $f <= 2 ) { return __( 'Lost Customers', 'woocommerce-advanced-reports' ); }
        return __( 'Needs Attention', 'woocommerce-advanced-reports' );
    }
}
