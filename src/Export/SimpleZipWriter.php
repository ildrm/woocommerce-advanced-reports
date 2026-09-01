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
        if ( strlen( $data ) > 0xffffffff ) { throw new \RuntimeException( 'ZIP entry exceeds the supported size.' ); }
        $this->add_entry( $name, strlen( $data ), $this->unsigned_crc32( $data ), static function ( $fp ) use ( $data ): void { self::write_all( $fp, $data ); } );
    }

    public function add_file( string $name, string $path ): void {
        if ( ! is_file( $path ) ) { throw new \RuntimeException( 'ZIP source file is missing.' ); }
        $size = filesize( $path );
        $hash = hash_file( 'crc32b', $path );
        if ( false === $size || false === $hash ) { throw new \RuntimeException( 'Cannot inspect ZIP source file.' ); }
        if ( $size > 0xffffffff ) { throw new \RuntimeException( 'ZIP entry exceeds the supported size.' ); }
        $crc = (int) hexdec( $hash );
        $this->add_entry( $name, (int) $size, $crc, static function ( $fp ) use ( $path ): void {
            $in = fopen( $path, 'rb' );
            if ( ! $in ) { throw new \RuntimeException( 'Cannot read ZIP source file.' ); }
            try {
                while ( ! feof( $in ) ) {
                    $chunk = fread( $in, 1048576 );
                    if ( false === $chunk ) { throw new \RuntimeException( 'Cannot read ZIP source file.' ); }
                    if ( '' !== $chunk ) { self::write_all( $fp, $chunk ); }
                }
            } finally {
                fclose( $in );
            }
        } );
    }

    public function close(): void {
        if ( ! is_resource( $this->fp ) ) { return; }
        $fp = $this->fp;
        $this->fp = null;
        try {
            $central_offset = ftell( $fp );
            if ( false === $central_offset ) { throw new \RuntimeException( 'Cannot determine ZIP output position.' ); }
            $central_data = implode( '', $this->central );
            if ( $this->entries > 0xffff || $central_offset > 0xffffffff || strlen( $central_data ) > 0xffffffff ) { throw new \RuntimeException( 'ZIP output exceeds the supported size.' ); }
            self::write_all( $fp, $central_data );
            self::write_all( $fp, pack( 'VvvvvVVv', 0x06054b50, 0, 0, $this->entries, $this->entries, strlen( $central_data ), $central_offset, 0 ) );
        } finally {
            fclose( $fp );
        }
    }

    public function __destruct() {
        try { $this->close(); } catch ( \Throwable $e ) { /* Destructors must not mask the active exception. */ }
    }

    private function add_entry( string $name, int $size, int $crc, callable $writer ): void {
        $name = str_replace( '\\', '/', ltrim( $name, '/' ) );
        if ( '' === $name || strlen( $name ) > 0xffff ) { throw new \RuntimeException( 'Invalid ZIP entry name.' ); }
        $offset = ftell( $this->fp );
        if ( false === $offset ) { throw new \RuntimeException( 'Cannot determine ZIP output position.' ); }
        [ $dos_time, $dos_date ] = $this->dos_datetime();
        $name_len = strlen( $name );
        self::write_all( $this->fp, pack( 'VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $dos_time, $dos_date, $crc, $size, $size, $name_len, 0 ) );
        self::write_all( $this->fp, $name );
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

    private static function write_all( $fp, string $data ): void {
        $length = strlen( $data );
        $offset = 0;
        while ( $offset < $length ) {
            $written = fwrite( $fp, substr( $data, $offset ) );
            if ( false === $written || 0 === $written ) { throw new \RuntimeException( 'Cannot write ZIP output.' ); }
            $offset += $written;
        }
    }
}
