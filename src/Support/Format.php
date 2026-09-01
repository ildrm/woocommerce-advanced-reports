<?php
namespace WCAR\Support;

final class Format {
    public static function settings(): array {
        return wp_parse_args( (array) get_option( 'wcar_settings', array() ), \WCAR\Installer::default_settings() );
    }

    public static function money( float $amount, string $currency = '' ): string {
        $currency = $currency ?: get_woocommerce_currency();
        $settings = self::settings();
        return wp_strip_all_tags( html_entity_decode( wc_price( $amount, array(
            'currency' => $currency,
            'decimals' => max( 0, min( 6, (int) ( $settings['number_decimals'] ?? 2 ) ) ),
            'decimal_separator' => (string) ( $settings['decimal_separator'] ?? '.' ),
            'thousand_separator' => (string) ( $settings['thousand_separator'] ?? ',' ),
        ) ), ENT_QUOTES, get_bloginfo( 'charset' ) ) );
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
        if ( '' === $email ) {
            return '';
        }
        $privacy = self::settings()['privacy'] ?? 'full';
        if ( 'hidden' === $privacy ) {
            return __( 'Hidden', 'woocommerce-advanced-reports' );
        }
        if ( 'masked' !== $privacy ) {
            return $email;
        }
        if ( ! is_email( $email ) ) {
            $head = function_exists( 'mb_substr' ) ? mb_substr( $email, 0, 1 ) : substr( $email, 0, 1 );
            return $head . '***';
        }
        [ $local, $domain ] = explode( '@', $email, 2 );
        $head = function_exists( 'mb_substr' ) ? mb_substr( $local, 0, 1 ) : substr( $local, 0, 1 );
        return $head . str_repeat( '*', max( 3, strlen( $local ) - 1 ) ) . '@' . $domain;
    }

    public static function mask_phone( string $phone ): string {
        if ( '' === $phone ) {
            return '';
        }
        $privacy = self::settings()['privacy'] ?? 'full';
        if ( 'hidden' === $privacy ) {
            return __( 'Hidden', 'woocommerce-advanced-reports' );
        }
        if ( 'masked' !== $privacy ) {
            return $phone;
        }
        if ( strlen( $phone ) < 7 ) {
            return substr( $phone, 0, 1 ) . '***' . substr( $phone, -1 );
        }
        return substr( $phone, 0, 3 ) . str_repeat( '*', max( 3, strlen( $phone ) - 6 ) ) . substr( $phone, -3 );
    }

    public static function bool( bool $value ): string {
        return $value ? __( 'Yes', 'woocommerce-advanced-reports' ) : __( 'No', 'woocommerce-advanced-reports' );
    }
}
