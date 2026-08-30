<?php
namespace WCAR\Support;

final class Format {
    public static function settings(): array {
        return wp_parse_args( (array) get_option( 'wcar_settings', array() ), \WCAR\Installer::default_settings() );
    }

    public static function money( float $amount, string $currency = '' ): string {
        $currency = $currency ?: get_woocommerce_currency();
        return wp_strip_all_tags( html_entity_decode( wc_price( $amount, array( 'currency' => $currency ) ), ENT_QUOTES, get_bloginfo( 'charset' ) ) );
    }

    public static function decimal( $value, ?int $decimals = null ): string {
        $s = self::settings();
        $decimals = null === $decimals ? max( 0, min( 6, (int) ( $s['number_decimals'] ?? 2 ) ) ) : max( 0, min( 6, $decimals ) );
        return number_format( (float) $value, $decimals, (string) ( $s['decimal_separator'] ?? '.' ), (string) ( $s['thousand_separator'] ?? ',' ) );
    }

    public static function percent( $value, int $decimals = 1 ): string {
        return self::decimal( (float) $value, $decimals ) . '%';
    }

    public static function mask_email( string $email ): string {
        $privacy = self::settings()['privacy'] ?? 'full';
        if ( 'hidden' === $privacy ) {
            return __( 'Hidden', 'woocommerce-advanced-reports' );
        }
        if ( 'masked' !== $privacy || ! is_email( $email ) ) {
            return $email;
        }
        [ $local, $domain ] = explode( '@', $email, 2 );
        $head = function_exists( 'mb_substr' ) ? mb_substr( $local, 0, 1 ) : substr( $local, 0, 1 );
        return $head . str_repeat( '*', max( 3, strlen( $local ) - 1 ) ) . '@' . $domain;
    }

    public static function mask_phone( string $phone ): string {
        $privacy = self::settings()['privacy'] ?? 'full';
        if ( 'hidden' === $privacy ) {
            return __( 'Hidden', 'woocommerce-advanced-reports' );
        }
        if ( 'masked' !== $privacy || strlen( $phone ) < 5 ) {
            return $phone;
        }
        return substr( $phone, 0, 3 ) . str_repeat( '*', max( 3, strlen( $phone ) - 6 ) ) . substr( $phone, -3 );
    }

    public static function bool( bool $value ): string {
        return $value ? __( 'Yes', 'woocommerce-advanced-reports' ) : __( 'No', 'woocommerce-advanced-reports' );
    }
}
