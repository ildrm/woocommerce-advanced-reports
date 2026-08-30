<?php
namespace WCAR\Export;

interface ExporterInterface {
    public function extension(): string;
    public function mime_type(): string;
    public function write_file( string $path, string $title, array $columns, iterable $rows, array $meta = array() ): void;
}
