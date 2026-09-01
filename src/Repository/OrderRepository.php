<?php
namespace WCAR\Repository;

use WCAR\Query\ReportFilter;

final class OrderRepository {
    public function iterate( ReportFilter $filter, ?array $override_statuses = null ): \Generator {
        $settings = wp_parse_args( (array) get_option( 'wcar_settings', array() ), \WCAR\Installer::default_settings() );
        $batch = min( 500, max( 25, absint( $settings['batch_size'] ?? 200 ) ) );
        $page = 1;
        do {
            $args = array(
                'limit'        => $batch,
                'paged'        => $page,
                'paginate'     => true,
                'return'       => 'objects',
                'orderby'      => 'date',
                'order'        => 'ASC',
                'date_created' => $filter->from->getTimestamp() . '...' . $filter->to->getTimestamp(),
            );
            $statuses = null !== $override_statuses ? $override_statuses : $filter->statuses;
            if ( $statuses ) {
                $args['status'] = $statuses;
            }
            $result = wc_get_orders( $args );
            foreach ( $result->orders as $order ) {
                if ( $this->matches( $order, $filter, $override_statuses ) ) {
                    yield $order;
                }
            }
            ++$page;
        } while ( $page <= (int) $result->max_num_pages );
    }

    public function iterate_all_until( ReportFilter $filter ): \Generator {
        $history = clone $filter;
        $history->from = new \DateTimeImmutable( '1970-01-01 00:00:00', wp_timezone() );
        $history->compare = false;
        $history->page = 1;
        yield from $this->iterate( $history );
    }

    public function iterate_refunds( ReportFilter $filter ): \Generator {
        $settings = wp_parse_args( (array) get_option( 'wcar_settings', array() ), \WCAR\Installer::default_settings() );
        $batch = min( 500, max( 25, absint( $settings['batch_size'] ?? 200 ) ) );
        $page = 1;
        do {
            $result = wc_get_orders( array(
                'type' => 'shop_order_refund', 'limit' => $batch, 'paged' => $page, 'paginate' => true, 'return' => 'objects',
                'orderby' => 'date', 'order' => 'ASC', 'date_created' => $filter->from->getTimestamp() . '...' . $filter->to->getTimestamp(),
            ) );
            foreach ( $result->orders as $refund ) {
                $parent = wc_get_order( $refund->get_parent_id() );
                if ( ! $parent ) { continue; }
                $parent_filter = clone $filter;
                $parent_filter->product_ids = array();
                $parent_filter->category_ids = array();
                if ( $this->matches( $parent, $parent_filter ) && $this->refund_items_match( $refund, $filter ) ) {
                    yield array( $refund, $parent );
                }
            }
            ++$page;
        } while ( $page <= (int) $result->max_num_pages );
    }

    public function first_order_timestamp( string $identity, array $statuses = array( 'processing', 'completed' ) ): ?int {
        sort( $statuses );
        $version = (string) get_option( 'wcar_order_cache_version', '1' );
        $key = 'wcar_first_' . md5( $version . '|' . strtolower( trim( $identity ) ) . '|' . implode( ',', $statuses ) );
        $cached = get_transient( $key );
        if ( false !== $cached ) {
            return $cached ? (int) $cached : null;
        }
        $args = array(
            'limit'   => 1,
            'orderby' => 'date',
            'order'   => 'ASC',
            'return'  => 'objects',
            'status'  => $statuses ?: array( 'processing', 'completed' ),
        );
        if ( is_email( $identity ) ) {
            $args['customer'] = strtolower( $identity );
        } elseif ( ctype_digit( $identity ) ) {
            $args['customer'] = (int) $identity;
        } else {
            set_transient( $key, 0, HOUR_IN_SECONDS );
            return null;
        }
        $orders = wc_get_orders( $args );
        $timestamp = null;
        if ( $orders && $orders[0]->get_date_created() ) {
            $timestamp = $orders[0]->get_date_created()->getTimestamp();
        }
        set_transient( $key, $timestamp ?: 0, DAY_IN_SECONDS );
        return $timestamp;
    }

    public static function bump_cache_version(): void {
        update_option( 'wcar_order_cache_version', (string) microtime( true ), false );
    }

    public function matches( \WC_Order $order, ReportFilter $filter, ?array $override_statuses = null ): bool {
        $statuses = null !== $override_statuses ? $override_statuses : $filter->statuses;
        if ( $statuses && ! in_array( $order->get_status(), $statuses, true ) ) {
            return false;
        }
        if ( $filter->currency && strtoupper( $order->get_currency() ) !== $filter->currency ) {
            return false;
        }
        if ( $filter->country && strtoupper( $order->get_billing_country() ) !== $filter->country ) {
            return false;
        }
        if ( $filter->payment_method && $order->get_payment_method() !== $filter->payment_method ) {
            return false;
        }
        if ( $filter->shipping_method ) {
            $methods = array_map( static fn( $item ) => $item->get_method_title(), $order->get_items( 'shipping' ) );
            if ( ! in_array( $filter->shipping_method, $methods, true ) ) {
                return false;
            }
        }
        if ( $filter->coupon ) {
            $codes = array_map( 'strtolower', $order->get_coupon_codes() );
            if ( ! in_array( strtolower( $filter->coupon ), $codes, true ) ) {
                return false;
            }
        }
        $total = (float) $order->get_total();
        if ( null !== $filter->min_amount && $total < $filter->min_amount ) {
            return false;
        }
        if ( null !== $filter->max_amount && $total > $filter->max_amount ) {
            return false;
        }
        if ( $filter->customer ) {
            $haystack = strtolower( implode( ' ', array(
                $order->get_billing_first_name(), $order->get_billing_last_name(), $order->get_billing_email(),
                $order->get_billing_phone(), (string) $order->get_customer_id(),
            ) ) );
            if ( false === strpos( $haystack, strtolower( $filter->customer ) ) ) {
                return false;
            }
        }
        if ( $filter->customer_type ) {
            $is_guest = 0 === (int) $order->get_customer_id();
            if ( ( 'guest' === $filter->customer_type && ! $is_guest ) || ( 'registered' === $filter->customer_type && $is_guest ) ) {
                return false;
            }
        }
        if ( $filter->product_ids || $filter->category_ids ) {
            $matched = false;
            foreach ( $order->get_items( 'line_item' ) as $item ) {
                if ( $this->item_matches( $item, $filter ) ) {
                    $matched = true;
                    break;
                }
            }
            if ( ! $matched ) {
                return false;
            }
        }
        return true;
    }

    public function item_matches( \WC_Order_Item_Product $item, ReportFilter $filter ): bool {
        $product_id = (int) $item->get_product_id();
        $variation_id = (int) $item->get_variation_id();
        if ( $filter->product_ids && ! in_array( $product_id, $filter->product_ids, true ) && ! in_array( $variation_id, $filter->product_ids, true ) ) {
            return false;
        }
        if ( $filter->category_ids ) {
            $terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
            if ( is_wp_error( $terms ) || ! array_intersect( $filter->category_ids_with_children(), array_map( 'intval', $terms ) ) ) {
                return false;
            }
        }
        return true;
    }

    private function refund_items_match( \WC_Order_Refund $refund, ReportFilter $filter ): bool {
        if ( ! $filter->product_ids && ! $filter->category_ids ) {
            return true;
        }
        foreach ( $refund->get_items( 'line_item' ) as $item ) {
            if ( $this->item_matches( $item, $filter ) ) {
                return true;
            }
        }
        return false;
    }
}
