<?php

declare(strict_types=1);

use Prime\Kernel\Env;

return [
    'path' => dirname(__DIR__) . '/content/themes',
    'default' => 'aurora',
    'allow_upload' => (bool) Env::get('THEMES_ALLOW_UPLOAD', true),
    'max_upload_mb' => max(1, (int) Env::get('THEMES_MAX_UPLOAD_MB', 20)),
    'max_zip_files' => max(50, (int) Env::get('THEMES_MAX_ZIP_FILES', 2000)),
    'max_uncompressed_bytes' => max(1048576, (int) Env::get('THEMES_MAX_UNCOMPRESSED_BYTES', 104857600)),
    'max_compression_ratio' => 100.0,
];
