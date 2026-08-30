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

    public static function from_request( array $input, ?Calendar $calendar = null ): self {
        $calendar = $calendar ?: new Calendar();
        $settings = wp_parse_args( (array) get_option( 'wcar_settings', array() ), \WCAR\Installer::default_settings() );
        $self = new self();

        $from_raw = isset( $input['date_from'] ) ? sanitize_text_field( wp_unslash( $input['date_from'] ) ) : '';
        $to_raw   = isset( $input['date_to'] ) ? sanitize_text_field( wp_unslash( $input['date_to'] ) ) : '';
        if ( '' === $from_raw || '' === $to_raw ) {
            $days = max( 1, absint( $settings['default_range'] ?? 30 ) );
            $today = new \DateTimeImmutable( 'today', wp_timezone() );
            $self->from = $today->modify( '-' . ( $days - 1 ) . ' days' );
            $self->to = $today->setTime( 23, 59, 59 );
        } else {
            $self->from = $calendar->parse_input( $from_raw, false );
            $self->to = $calendar->parse_input( $to_raw, true );
        }
        if ( ! $self->from || ! $self->to || $self->from > $self->to ) {
            $today = new \DateTimeImmutable( 'today', wp_timezone() );
            $self->from = $today->modify( '-29 days' );
            $self->to = $today->setTime( 23, 59, 59 );
        }

        $statuses = $input['status'] ?? ( $settings['default_statuses'] ?? array( 'processing', 'completed' ) );
        $self->statuses = array_values( array_filter( array_map( static fn( $v ) => sanitize_key( str_replace( 'wc-', '', (string) $v ) ), (array) $statuses ) ) );
        $self->product_ids = array_values( array_filter( array_map( 'absint', (array) ( $input['product'] ?? array() ) ) ) );
        $self->category_ids = array_values( array_filter( array_map( 'absint', (array) ( $input['category'] ?? array() ) ) ) );
        $self->customer = sanitize_text_field( wp_unslash( $input['customer'] ?? '' ) );
        $self->country = strtoupper( sanitize_text_field( wp_unslash( $input['country'] ?? '' ) ) );
        $self->payment_method = sanitize_key( $input['payment_method'] ?? '' );
        $self->shipping_method = sanitize_text_field( wp_unslash( $input['shipping_method'] ?? '' ) );
        $self->coupon = sanitize_text_field( wp_unslash( $input['coupon'] ?? '' ) );
        $self->customer_type = in_array( $input['customer_type'] ?? '', array( 'guest', 'registered' ), true ) ? $input['customer_type'] : '';
        $self->currency = strtoupper( sanitize_text_field( wp_unslash( $input['currency'] ?? '' ) ) );
        $self->group_by = in_array( $input['group_by'] ?? 'day', array( 'hour', 'day', 'week', 'month', 'quarter', 'year' ), true ) ? $input['group_by'] : 'day';
        $self->compare = ! empty( $input['compare'] );
        $self->page = max( 1, absint( $input['paged'] ?? 1 ) );
        $self->per_page = min( 200, max( 10, absint( $input['per_page'] ?? 50 ) ) );
        $self->inactive_days = max( 1, absint( $input['inactive_days'] ?? ( $settings['inactive_days'] ?? 90 ) ) );
        $self->dead_stock_days = max( 1, absint( $input['dead_stock_days'] ?? ( $settings['dead_stock_days'] ?? 90 ) ) );
        $self->dead_stock_max_sold = max( 0, absint( $input['dead_stock_max_sold'] ?? ( $settings['dead_stock_max_sold'] ?? 0 ) ) );

        foreach ( array( 'min_amount', 'max_amount' ) as $amount_key ) {
            if ( isset( $input[ $amount_key ] ) && '' !== $input[ $amount_key ] ) {
                $self->{$amount_key} = (float) wc_format_decimal( wp_unslash( $input[ $amount_key ] ) );
            }
        }
        return $self;
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

    public function cache_key(): string {
        $data = $this->to_array();
        unset( $data['page'], $data['per_page'] );
        return md5( wp_json_encode( $data ) );
    }

    public function previous_period(): self {
        $clone = clone $this;
        $seconds = max( DAY_IN_SECONDS, $this->to->getTimestamp() - $this->from->getTimestamp() + 1 );
        $clone->to = ( new \DateTimeImmutable( '@' . ( $this->from->getTimestamp() - 1 ) ) )->setTimezone( wp_timezone() );
        $clone->from = ( new \DateTimeImmutable( '@' . ( $clone->to->getTimestamp() - $seconds + 1 ) ) )->setTimezone( wp_timezone() );
        $clone->compare = false;
        return $clone;
    }
}
