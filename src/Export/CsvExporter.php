<?php
namespace WCAR\Export;

final class CsvExporter implements ExporterInterface {
    public function extension(): string { return 'csv'; }
    public function mime_type(): string { return 'text/csv; charset=UTF-8'; }

    public function write_file( string $path, string $title, array $columns, iterable $rows, array $meta = array() ): void {
        $fp = fopen( $path, 'wb' );
        if ( ! $fp ) { throw new \RuntimeException( 'Cannot open CSV output.' ); }
        $settings = wp_parse_args( (array) get_option( 'wcar_settings', array() ), \WCAR\Installer::default_settings() );
        if ( 'yes' === ( $settings['csv_bom'] ?? 'yes' ) ) { fwrite( $fp, "\xEF\xBB\xBF" ); }
        fputcsv( $fp, array( $this->scalar( $title ) ), ',', '"', '' );
        foreach ( $meta as $key => $value ) { fputcsv( $fp, array( $this->scalar( (string) $key ), $this->scalar( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ) ), ',', '"', '' ); }
        fputcsv( $fp, array(), ',', '"', '' );
        fputcsv( $fp, array_map( array( $this, 'scalar' ), array_values( $columns ) ), ',', '"', '' );
        foreach ( $rows as $row ) {
            $line = array();
            foreach ( array_keys( $columns ) as $key ) { $line[] = $this->scalar( $row[ $key ] ?? '' ); }
            fputcsv( $fp, $line, ',', '"', '' );
        }
        fclose( $fp );
    }

    private function scalar( $value ): string {
        if ( null === $value ) { return ''; }
        if ( is_bool( $value ) ) { return $value ? '1' : '0'; }
        if ( is_scalar( $value ) ) { $text = (string) $value; } else { $text = wp_json_encode( $value ); }
        // Prevent spreadsheet formula injection when CSV is opened in Excel/LibreOffice.
        if ( preg_match( '/^[\x00-\x20]*[=+\-@]/u', $text ) ) { $text = "'" . $text; }
        return $text;
    }
}
