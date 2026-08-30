<?php
namespace WCAR\Export;

/**
 * Minimal ZIP (store/no-compression) writer used to create XLSX files without
 * requiring the optional PHP ZipArchive extension.
 */
final class SimpleZipWriter {
    private $fp;
    private array $central = array();
    private int $entries = 0;

    public function __construct( string $path ) {
        $this->fp = fopen( $path, 'wb' );
        if ( ! $this->fp ) {
            throw new \RuntimeException( 'Cannot create ZIP output.' );
        }
    }

    public function add_string( string $name, string $data ): void {
        $this->add_entry( $name, strlen( $data ), $this->unsigned_crc32( $data ), static function ( $fp ) use ( $data ): void { fwrite( $fp, $data ); } );
    }

    public function add_file( string $name, string $path ): void {
        if ( ! is_file( $path ) ) { throw new \RuntimeException( 'ZIP source file is missing.' ); }
        $size = filesize( $path );
        $hash = hash_file( 'crc32b', $path );
        $crc = (int) hexdec( $hash );
        $this->add_entry( $name, (int) $size, $crc, static function ( $fp ) use ( $path ): void {
            $in = fopen( $path, 'rb' );
            if ( ! $in ) { throw new \RuntimeException( 'Cannot read ZIP source file.' ); }
            while ( ! feof( $in ) ) {
                $chunk = fread( $in, 1048576 );
                if ( false === $chunk ) { fclose( $in ); throw new \RuntimeException( 'Cannot read ZIP source file.' ); }
                if ( '' !== $chunk ) { fwrite( $fp, $chunk ); }
            }
            fclose( $in );
        } );
    }

    public function close(): void {
        if ( ! is_resource( $this->fp ) ) { return; }
        $central_offset = ftell( $this->fp );
        $central_data = implode( '', $this->central );
        fwrite( $this->fp, $central_data );
        fwrite( $this->fp, pack( 'VvvvvVVv', 0x06054b50, 0, 0, $this->entries, $this->entries, strlen( $central_data ), $central_offset, 0 ) );
        fclose( $this->fp );
        $this->fp = null;
    }

    public function __destruct() { $this->close(); }

    private function add_entry( string $name, int $size, int $crc, callable $writer ): void {
        $name = str_replace( '\\', '/', ltrim( $name, '/' ) );
        $offset = ftell( $this->fp );
        [ $dos_time, $dos_date ] = $this->dos_datetime();
        $name_len = strlen( $name );
        fwrite( $this->fp, pack( 'VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $dos_time, $dos_date, $crc, $size, $size, $name_len, 0 ) );
        fwrite( $this->fp, $name );
        $writer( $this->fp );
        $this->central[] = pack( 'VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $dos_time, $dos_date, $crc, $size, $size, $name_len, 0, 0, 0, 0, 0, $offset ) . $name;
        ++$this->entries;
    }

    private function dos_datetime(): array {
        $y = (int) gmdate( 'Y' ); $m = (int) gmdate( 'n' ); $d = (int) gmdate( 'j' ); $h = (int) gmdate( 'G' ); $i = (int) gmdate( 'i' ); $s = (int) gmdate( 's' );
        $y = max( 1980, min( 2107, $y ) );
        $time = ( $h << 11 ) | ( $i << 5 ) | intdiv( $s, 2 );
        $date = ( ( $y - 1980 ) << 9 ) | ( $m << 5 ) | $d;
        return array( $time, $date );
    }

    private function unsigned_crc32( string $data ): int {
        return (int) hexdec( hash( 'crc32b', $data ) );
    }
}
