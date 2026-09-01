<?php
namespace WCAR\Export;

final class CsvExporter implements ExporterInterface {
    public function extension(): string { return 'csv'; }
    public function mime_type(): string { return 'text/csv; charset=UTF-8'; }

    public function write_file( string $path, string $title, array $columns, iterable $rows, array $meta = array() ): void {
        $fp = fopen( $path, 'wb' );
        if ( ! $fp ) { throw new \RuntimeException( 'Cannot open CSV output.' ); }
        $settings = wp_parse_args( (array) get_option( 'wcar_settings', array() ), \WCAR\Installer::default_settings() );
        try {
            if ( 'yes' === ( $settings['csv_bom'] ?? 'yes' ) && 3 !== fwrite( $fp, "\xEF\xBB\xBF" ) ) { throw new \RuntimeException( 'Cannot write CSV output.' ); }
            $this->write_row( $fp, array( $this->scalar( $title ) ) );
            foreach ( $meta as $key => $value ) { $this->write_row( $fp, array( $this->scalar( (string) $key ), $this->scalar( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ) ) ); }
            $this->write_row( $fp, array() );
            $this->write_row( $fp, array_map( array( $this, 'scalar' ), array_values( $columns ) ) );
            foreach ( $rows as $row ) {
                $line = array();
                foreach ( array_keys( $columns ) as $key ) { $line[] = $this->scalar( $row[ $key ] ?? '' ); }
                $this->write_row( $fp, $line );
            }
        } finally {
            fclose( $fp );
        }
    }

    private function write_row( $fp, array $row ): void {
        if ( false === fputcsv( $fp, $row, ',', '"', '' ) ) { throw new \RuntimeException( 'Cannot write CSV output.' ); }
    }

    private function scalar( $value ): string {
        if ( null === $value ) { return ''; }
        if ( is_bool( $value ) ) { return $value ? '1' : '0'; }
        $protect_formula = is_string( $value );
        if ( is_scalar( $value ) ) { $text = (string) $value; } else { $text = (string) wp_json_encode( $value ); }
        $text = wp_check_invalid_utf8( $text, true );
        // Prevent spreadsheet formula injection when CSV is opened in Excel/LibreOffice.
        if ( $protect_formula && preg_match( '/^[\x00-\x20]*[=+\-@]/u', $text ) ) { $text = "'" . $text; }
        return $text;
    }
}
