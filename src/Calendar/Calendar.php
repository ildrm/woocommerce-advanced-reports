<?php
namespace WCAR\Calendar;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;

final class Calendar {
    public function type(): string {
        $settings = wp_parse_args( (array) get_option( 'wcar_settings', array() ), \WCAR\Installer::default_settings() );
        return 'jalali' === ( $settings['calendar'] ?? '' ) ? 'jalali' : 'gregorian';
    }

    public function parse_input( string $date, bool $end_of_day = false ): ?DateTimeImmutable {
        $date = trim( $date );
        if ( '' === $date ) {
            return null;
        }
        $timezone = wp_timezone();
        try {
            if ( 'jalali' === $this->type() ) {
                if ( ! preg_match( '/^(\d{1,4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $date, $matches ) ) {
                    return null;
                }
                [ $jy, $jm, $jd ] = array_map( 'intval', array_slice( $matches, 1 ) );
                [ $gy, $gm, $gd ] = self::jalali_to_gregorian( $jy, $jm, $jd );
                $time = $end_of_day ? '23:59:59' : '00:00:00';
                return new DateTimeImmutable( sprintf( '%04d-%02d-%02d %s', $gy, $gm, $gd, $time ), $timezone );
            }
            if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches ) || ! checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] ) ) {
                return null;
            }
            $time = $end_of_day ? ' 23:59:59' : ' 00:00:00';
            return new DateTimeImmutable( $date . $time, $timezone );
        } catch ( Exception $e ) {
            return null;
        }
    }

    public function format_input( ?DateTimeInterface $date ): string {
        if ( ! $date ) {
            return '';
        }
        return 'jalali' === $this->type() ? $this->format( $date, 'Y/m/d' ) : wp_date( 'Y-m-d', $date->getTimestamp(), wp_timezone() );
    }

    public function format( $date, string $format = '' ): string {
        if ( ! $date ) {
            return '';
        }
        if ( $date instanceof \WC_DateTime ) {
            $dt = ( new DateTimeImmutable( '@' . $date->getTimestamp() ) )->setTimezone( wp_timezone() );
        } elseif ( $date instanceof DateTimeInterface ) {
            $dt = ( new DateTimeImmutable( '@' . $date->getTimestamp() ) )->setTimezone( wp_timezone() );
        } elseif ( is_numeric( $date ) ) {
            $dt = ( new DateTimeImmutable( '@' . (int) $date ) )->setTimezone( wp_timezone() );
        } else {
            try {
                $dt = new DateTimeImmutable( (string) $date, wp_timezone() );
            } catch ( Exception $e ) {
                return '';
            }
        }

        $settings = wp_parse_args( (array) get_option( 'wcar_settings', array() ), \WCAR\Installer::default_settings() );
        if ( 'jalali' !== $this->type() ) {
            return wp_date( $format ?: ( $settings['date_format'] ?? 'Y-m-d' ), $dt->getTimestamp(), wp_timezone() );
        }
        [ $jy, $jm, $jd ] = self::gregorian_to_jalali( (int) $dt->format( 'Y' ), (int) $dt->format( 'n' ), (int) $dt->format( 'j' ) );
        $fmt = $format ?: ( $settings['jalali_date_format'] ?? 'Y/m/d' );
        $months_en = array( 1 => 'Farvardin', 'Ordibehesht', 'Khordad', 'Tir', 'Mordad', 'Shahrivar', 'Mehr', 'Aban', 'Azar', 'Dey', 'Bahman', 'Esfand' );
        $months_fa = array( 1 => 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند' );
        $months = 0 === strpos( determine_locale(), 'fa' ) ? $months_fa : $months_en;
        $filtered_months = apply_filters( 'wcar_jalali_month_names', $months );
        if ( is_array( $filtered_months ) ) {
            foreach ( $filtered_months as $month_number => $month_name ) {
                if ( isset( $months[ $month_number ] ) && is_scalar( $month_name ) ) { $months[ $month_number ] = (string) $month_name; }
            }
        }
        $map = array(
            'Y' => sprintf( '%04d', $jy ), 'y' => sprintf( '%02d', $jy % 100 ), 'm' => sprintf( '%02d', $jm ), 'n' => (string) $jm,
            'd' => sprintf( '%02d', $jd ), 'j' => (string) $jd, 'F' => $months[ $jm ] ?? (string) $jm, 'M' => function_exists( 'mb_substr' ) ? mb_substr( $months[ $jm ] ?? (string) $jm, 0, 3 ) : substr( $months[ $jm ] ?? (string) $jm, 0, 3 ),
            'H' => $dt->format( 'H' ), 'G' => $dt->format( 'G' ), 'i' => $dt->format( 'i' ), 's' => $dt->format( 's' ),
        );
        $out = ''; $escape = false;
        $format_characters = preg_split( '//u', $fmt, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $format_characters ) ) { return ''; }
        foreach ( $format_characters as $ch ) {
            if ( $escape ) { $out .= $ch; $escape = false; continue; }
            if ( '\\' === $ch ) { $escape = true; continue; }
            $out .= $map[ $ch ] ?? $ch;
        }
        return $out;
    }

    public static function gregorian_to_jalali( int $gy, int $gm, int $gd ): array {
        $g_d_m = array( 0,31,59,90,120,151,181,212,243,273,304,334 );
        $gy2 = ( $gm > 2 ) ? ( $gy + 1 ) : $gy;
        $days = 355666 + ( 365 * $gy ) + intdiv( $gy2 + 3, 4 ) - intdiv( $gy2 + 99, 100 ) + intdiv( $gy2 + 399, 400 ) + $gd + $g_d_m[ $gm - 1 ];
        $jy = -1595 + ( 33 * intdiv( $days, 12053 ) );
        $days %= 12053;
        $jy += 4 * intdiv( $days, 1461 );
        $days %= 1461;
        if ( $days > 365 ) {
            $jy += intdiv( $days - 1, 365 );
            $days = ( $days - 1 ) % 365;
        }
        if ( $days < 186 ) {
            $jm = 1 + intdiv( $days, 31 );
            $jd = 1 + ( $days % 31 );
        } else {
            $jm = 7 + intdiv( $days - 186, 30 );
            $jd = 1 + ( ( $days - 186 ) % 30 );
        }
        return array( $jy, $jm, $jd );
    }

    public static function jalali_to_gregorian( int $jy, int $jm, int $jd ): array {
        if ( $jy < 1 || $jm < 1 || $jm > 12 || $jd < 1 || $jd > 31 ) {
            throw new \InvalidArgumentException( 'Invalid Jalali date.' );
        }
        // Invert the tested Gregorian→Jalali conversion over the narrow
        // Gregorian window in which a Jalali year can occur. This avoids
        // storage/calendar drift and correctly handles leap years.
        $start = new DateTimeImmutable( sprintf( '%04d-03-15 00:00:00', $jy + 621 ), new \DateTimeZone( 'UTC' ) );
        for ( $i = 0; $i < 380; ++$i ) {
            $candidate = $start->modify( '+' . $i . ' days' );
            [ $cy, $cm, $cd ] = self::gregorian_to_jalali( (int) $candidate->format( 'Y' ), (int) $candidate->format( 'n' ), (int) $candidate->format( 'j' ) );
            if ( $cy === $jy && $cm === $jm && $cd === $jd ) {
                return array( (int) $candidate->format( 'Y' ), (int) $candidate->format( 'n' ), (int) $candidate->format( 'j' ) );
            }
        }
        throw new \InvalidArgumentException( 'Invalid Jalali date.' );
    }
}
