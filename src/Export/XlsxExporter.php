<?php
namespace WCAR\Export;

final class XlsxExporter implements ExporterInterface {
    public function extension(): string { return 'xlsx'; }
    public function mime_type(): string { return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'; }

    public function write_file( string $path, string $title, array $columns, iterable $rows, array $meta = array() ): void {
        $tmp = wp_tempnam( 'wcar-sheet.xml' );
        $fp = fopen( $tmp, 'wb' );
        if ( ! $fp ) { throw new \RuntimeException( 'Cannot create worksheet.' ); }
        fwrite( $fp, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' );
        $r = 1;
        $this->write_row( $fp, $r++, array( $title ), true );
        foreach ( $meta as $k => $v ) { $this->write_row( $fp, $r++, array( (string) $k, is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) ) ); }
        ++$r;
        $header_row = $r;
        $this->write_row( $fp, $r++, array_values( $columns ), true );
        foreach ( $rows as $row ) {
            $vals = array();
            foreach ( array_keys( $columns ) as $key ) { $vals[] = $row[ $key ] ?? ''; }
            $this->write_row( $fp, $r++, $vals );
        }
        $last_col = $this->col( max( 1, count( $columns ) ) );
        fwrite( $fp, '</sheetData><autoFilter ref="A' . $header_row . ':' . $last_col . max( $header_row, $r - 1 ) . '"/></worksheet>' );
        fclose( $fp );

        $zip = new SimpleZipWriter( $path );
        $zip->add_string( '[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>' );
        $zip->add_string( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>' );
        $zip->add_string( 'xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>' );
        $zip->add_string( 'xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>' );
        $zip->add_string( 'xl/styles.xml', '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>' );
        $zip->add_file( 'xl/worksheets/sheet1.xml', $tmp );
        $zip->close();
        @unlink( $tmp );
    }

    private function write_row( $fp, int $row_num, array $values, bool $bold = false ): void {
        fwrite( $fp, '<row r="' . $row_num . '">' );
        foreach ( array_values( $values ) as $i => $value ) {
            $ref = $this->col( $i + 1 ) . $row_num;
            $style = $bold ? ' s="1"' : '';
            if ( is_int( $value ) || is_float( $value ) ) {
                fwrite( $fp, '<c r="' . $ref . '"' . $style . ' t="n"><v>' . $value . '</v></c>' );
            } else {
                $text = is_scalar( $value ) || null === $value ? (string) $value : wp_json_encode( $value );
                fwrite( $fp, '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">' . htmlspecialchars( $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</t></is></c>' );
            }
        }
        fwrite( $fp, '</row>' );
    }

    private function col( int $n ): string {
        $s = '';
        while ( $n > 0 ) { $n--; $s = chr( 65 + ( $n % 26 ) ) . $s; $n = intdiv( $n, 26 ); }
        return $s;
    }
}
