<?php
namespace WCAR\Query;

use WCAR\Calendar\Calendar;

final class ReportFilter {
    public ?\DateTimeImmutable $from = null;
    public ?\DateTimeImmutable $to = null;
    public array $statuses = array();
    public array $product_ids = array();
    public array $category_ids = array();
    public string $customer = '';
    public string $country = '';
    public string $payment_method = '';
    public string $shipping_method = '';
    public string $coupon = '';
    public ?float $min_amount = null;
    public ?float $max_amount = null;
    public string $customer_type = '';
    public string $currency = '';
    public string $group_by = 'day';
    public bool $compare = false;
    public int $page = 1;
    public int $per_page = 50;
    public int $inactive_days = 90;
    public int $dead_stock_days = 90;
    public int $dead_stock_max_sold = 0;
    public bool $invalid_date_range = false;
    private ?array $expanded_category_ids = null;
    private string $expanded_category_key = '';

    public static function from_request( array $input, ?Calendar $calendar = null ): self {
        return self::from_input( (array) wp_unslash( $input ), $calendar );
    }

    public static function from_input( array $input, ?Calendar $calendar = null ): self {
        $calendar = $calendar ?: new Calendar();
        $settings = wp_parse_args( (array) get_option( 'wcar_settings', array() ), \WCAR\Installer::default_settings() );
        $self = new self();

        $input = self::sanitize_input_data( $input );
        $from_raw = (string) ( $input['date_from'] ?? '' );
        $to_raw   = (string) ( $input['date_to'] ?? '' );
        if ( '' === $from_raw || '' === $to_raw ) {
            $self->invalid_date_range = ( '' === $from_raw ) !== ( '' === $to_raw );
            $days = min( 3650, max( 1, absint( $settings['default_range'] ?? 30 ) ) );
            $today = new \DateTimeImmutable( 'today', wp_timezone() );
            $self->from = $today->modify( '-' . ( $days - 1 ) . ' days' );
            $self->to = $today->setTime( 23, 59, 59 );
        } else {
            $self->from = $calendar->parse_input( $from_raw, false );
            $self->to = $calendar->parse_input( $to_raw, true );
        }
        if ( ! $self->from || ! $self->to || $self->from > $self->to ) {
            $self->invalid_date_range = true;
            $days = min( 3650, max( 1, absint( $settings['default_range'] ?? 30 ) ) );
            $today = new \DateTimeImmutable( 'today', wp_timezone() );
            $self->from = $today->modify( '-' . ( $days - 1 ) . ' days' );
            $self->to = $today->setTime( 23, 59, 59 );
        }

        $statuses = $input['status'] ?? ( $settings['default_statuses'] ?? array( 'processing', 'completed' ) );
        $valid_statuses = array_map( static fn( $v ) => str_replace( 'wc-', '', $v ), array_keys( wc_get_order_statuses() ) );
        $self->statuses = array_values( array_intersect( (array) $statuses, $valid_statuses ) );
        if ( ! $self->statuses ) {
            $self->statuses = array_values( array_intersect( (array) ( $settings['default_statuses'] ?? array( 'processing', 'completed' ) ), $valid_statuses ) );
        }
        if ( ! $self->statuses ) { $self->statuses = array_values( array_intersect( array( 'processing', 'completed' ), $valid_statuses ) ); }
        if ( ! $self->statuses && $valid_statuses ) { $self->statuses = array( reset( $valid_statuses ) ); }
        $self->product_ids = (array) ( $input['product'] ?? array() );
        $self->category_ids = (array) ( $input['category'] ?? array() );
        $self->customer = (string) ( $input['customer'] ?? '' );
        $self->country = (string) ( $input['country'] ?? '' );
        $self->payment_method = (string) ( $input['payment_method'] ?? '' );
        $self->shipping_method = (string) ( $input['shipping_method'] ?? '' );
        $self->coupon = (string) ( $input['coupon'] ?? '' );
        $self->customer_type = in_array( $input['customer_type'] ?? '', array( 'guest', 'registered' ), true ) ? $input['customer_type'] : '';
        $self->currency = (string) ( $input['currency'] ?? '' );
        $self->group_by = in_array( $input['group_by'] ?? 'day', array( 'hour', 'day', 'week', 'month', 'quarter', 'year' ), true ) ? $input['group_by'] : 'day';
        $self->compare = ! empty( $input['compare'] );
        $self->page = max( 1, absint( $input['paged'] ?? 1 ) );
        $self->per_page = min( 200, max( 10, absint( $input['per_page'] ?? 50 ) ) );
        $self->inactive_days = min( 36500, max( 1, absint( $input['inactive_days'] ?? ( $settings['inactive_days'] ?? 90 ) ) ) );
        $self->dead_stock_days = min( 36500, max( 1, absint( $input['dead_stock_days'] ?? ( $settings['dead_stock_days'] ?? 90 ) ) ) );
        $self->dead_stock_max_sold = min( 1000000000, max( 0, absint( $input['dead_stock_max_sold'] ?? ( $settings['dead_stock_max_sold'] ?? 0 ) ) ) );

        foreach ( array( 'min_amount', 'max_amount' ) as $amount_key ) {
            if ( isset( $input[ $amount_key ] ) && '' !== $input[ $amount_key ] ) {
                $decimal = wc_format_decimal( $input[ $amount_key ] );
                if ( '' !== $decimal && is_numeric( $decimal ) ) {
                    $self->{$amount_key} = (float) $decimal;
                }
            }
        }
        return $self;
    }

    public static function from_storage( array $input, ?Calendar $calendar = null ): self {
        $from_timestamp = isset( $input['_wcar_from_timestamp'] ) && is_scalar( $input['_wcar_from_timestamp'] ) ? (int) $input['_wcar_from_timestamp'] : 0;
        $to_timestamp = isset( $input['_wcar_to_timestamp'] ) && is_scalar( $input['_wcar_to_timestamp'] ) ? (int) $input['_wcar_to_timestamp'] : 0;
        $self = self::from_input( $input, $calendar );
        if ( $from_timestamp > 0 && $to_timestamp >= $from_timestamp && $to_timestamp <= 253402300799 ) {
            try {
                $timezone = wp_timezone();
                $self->from = ( new \DateTimeImmutable( '@' . $from_timestamp ) )->setTimezone( $timezone );
                $self->to = ( new \DateTimeImmutable( '@' . $to_timestamp ) )->setTimezone( $timezone );
                $self->invalid_date_range = false;
            } catch ( \Exception $exception ) {
                // Keep the validated request-form dates when legacy/corrupt timestamps are unusable.
            }
        }
        return $self;
    }

    public static function request_data( array $input ): array {
        return self::sanitize_input_data( (array) wp_unslash( $input ) );
    }

    public static function input_data( array $input ): array {
        return self::sanitize_input_data( $input );
    }

    private static function sanitize_input_data( array $input ): array {
        $out = array();
        foreach ( array( 'date_from', 'date_to', 'customer', 'shipping_method', 'coupon' ) as $key ) {
            if ( isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ) {
                $out[ $key ] = sanitize_text_field( (string) $input[ $key ] );
            }
        }

        $status_values = array_filter( (array) ( $input['status'] ?? array() ), 'is_scalar' );
        $statuses = array_map( static fn( $value ) => sanitize_key( str_replace( 'wc-', '', (string) $value ) ), $status_values );
        if ( isset( $input['status'] ) ) {
            $out['status'] = array_values( array_unique( array_filter( $statuses ) ) );
        }

        $products = array_filter( (array) ( $input['product'] ?? array() ), 'is_scalar' );
        if ( isset( $input['product_csv'] ) && is_scalar( $input['product_csv'] ) ) {
            $products = array_merge( $products, preg_split( '/[\s,]+/', (string) $input['product_csv'] ) ?: array() );
        }
        if ( isset( $input['product'] ) || isset( $input['product_csv'] ) ) {
            $out['product'] = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $products ) ) ) ), 0, 500 );
        }
        if ( isset( $input['category'] ) ) {
            $categories = array_filter( (array) $input['category'], 'is_scalar' );
            $out['category'] = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $categories ) ) ) ), 0, 200 );
        }

        $out['country'] = isset( $input['country'] ) && is_scalar( $input['country'] ) ? strtoupper( sanitize_text_field( (string) $input['country'] ) ) : '';
        $out['currency'] = isset( $input['currency'] ) && is_scalar( $input['currency'] ) ? strtoupper( sanitize_text_field( (string) $input['currency'] ) ) : '';
        $out['payment_method'] = isset( $input['payment_method'] ) && is_scalar( $input['payment_method'] ) ? sanitize_key( (string) $input['payment_method'] ) : '';

        foreach ( array( 'min_amount', 'max_amount' ) as $key ) {
            if ( isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) && '' !== (string) $input[ $key ] ) {
                $out[ $key ] = sanitize_text_field( (string) $input[ $key ] );
            }
        }
        $out['customer_type'] = in_array( $input['customer_type'] ?? '', array( 'guest', 'registered' ), true ) ? $input['customer_type'] : '';
        $out['group_by'] = in_array( $input['group_by'] ?? '', array( 'hour', 'day', 'week', 'month', 'quarter', 'year' ), true ) ? $input['group_by'] : 'day';
        if ( ! empty( $input['compare'] ) ) {
            $out['compare'] = '1';
        }
        if ( isset( $input['paged'] ) && is_scalar( $input['paged'] ) ) {
            $out['paged'] = max( 1, absint( $input['paged'] ) );
        }
        if ( isset( $input['per_page'] ) && is_scalar( $input['per_page'] ) ) {
            $out['per_page'] = min( 200, max( 10, absint( $input['per_page'] ) ) );
        }
        foreach ( array( 'inactive_days', 'dead_stock_days' ) as $key ) {
            if ( isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ) {
                $out[ $key ] = min( 36500, max( 1, absint( $input[ $key ] ) ) );
            }
        }
        if ( isset( $input['dead_stock_max_sold'] ) && is_scalar( $input['dead_stock_max_sold'] ) ) {
            $out['dead_stock_max_sold'] = min( 1000000000, max( 0, absint( $input['dead_stock_max_sold'] ) ) );
        }
        return $out;
    }

    public function to_array(): array {
        return array(
            'date_from' => $this->from?->format( DATE_ATOM ), 'date_to' => $this->to?->format( DATE_ATOM ),
            'status' => $this->statuses, 'product' => $this->product_ids, 'category' => $this->category_ids,
            'customer' => $this->customer, 'country' => $this->country, 'payment_method' => $this->payment_method,
            'shipping_method' => $this->shipping_method, 'coupon' => $this->coupon, 'min_amount' => $this->min_amount,
            'max_amount' => $this->max_amount, 'customer_type' => $this->customer_type, 'currency' => $this->currency,
            'group_by' => $this->group_by, 'compare' => $this->compare, 'page' => $this->page, 'per_page' => $this->per_page,
            'inactive_days' => $this->inactive_days, 'dead_stock_days' => $this->dead_stock_days, 'dead_stock_max_sold' => $this->dead_stock_max_sold,
        );
    }

    public function to_request_array( Calendar $calendar ): array {
        $data = array(
            'date_from' => $calendar->format_input( $this->from ), 'date_to' => $calendar->format_input( $this->to ),
            'status' => $this->statuses, 'product' => $this->product_ids, 'category' => $this->category_ids,
            'customer' => $this->customer, 'country' => $this->country, 'payment_method' => $this->payment_method,
            'shipping_method' => $this->shipping_method, 'coupon' => $this->coupon, 'min_amount' => $this->min_amount,
            'max_amount' => $this->max_amount, 'customer_type' => $this->customer_type, 'currency' => $this->currency,
            'group_by' => $this->group_by, 'compare' => $this->compare ? '1' : '', 'per_page' => $this->per_page,
            'inactive_days' => $this->inactive_days, 'dead_stock_days' => $this->dead_stock_days, 'dead_stock_max_sold' => $this->dead_stock_max_sold,
        );
        return array_filter( $data, static fn( $value ) => null !== $value && '' !== $value && array() !== $value );
    }

    public function to_storage_array( Calendar $calendar ): array {
        return array_merge( $this->to_request_array( $calendar ), array(
            '_wcar_from_timestamp' => $this->from?->getTimestamp() ?: 0,
            '_wcar_to_timestamp' => $this->to?->getTimestamp() ?: 0,
        ) );
    }

    public function cache_key(): string {
        $data = $this->to_array();
        unset( $data['page'], $data['per_page'] );
        return md5( wp_json_encode( $data ) );
    }

    public function category_ids_with_children(): array {
        $selected = $this->category_ids;
        sort( $selected );
        $cache_key = implode( ',', $selected );
        if ( null !== $this->expanded_category_ids && $cache_key === $this->expanded_category_key ) { return $this->expanded_category_ids; }
        $ids = $selected;
        foreach ( $this->category_ids as $category_id ) {
            $children = get_term_children( $category_id, 'product_cat' );
            if ( ! is_wp_error( $children ) ) { $ids = array_merge( $ids, array_map( 'intval', $children ) ); }
        }
        $this->expanded_category_ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
        $this->expanded_category_key = $cache_key;
        return $this->expanded_category_ids;
    }

    public function previous_period(): self {
        $clone = clone $this;
        $start = $this->from->setTime( 0, 0, 0 );
        $days = max( 1, (int) $start->diff( $this->to->setTime( 0, 0, 0 ) )->days + 1 );
        $clone->to = $start->modify( '-1 second' );
        $clone->from = $start->modify( '-' . $days . ' days' );
        $clone->compare = false;
        return $clone;
    }
}
