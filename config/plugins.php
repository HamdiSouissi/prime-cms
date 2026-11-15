<?php

declare(strict_types=1);

use Prime\Kernel\Env;

return [
    // Directory where installed plugins live (`content/plugins/{slug}/`).
    'path' => 'content/plugins',

    // Public directory where whitelisted plugin assets are published (`public/assets/plugins/{slug}/`).
    'public_assets_path' => 'public/assets/plugins',
    'public_assets_url' => '/assets/plugins',

    // Safe mode disables loading ALL plugins on boot (emergency recovery).
    'safe_mode' => (bool) Env::get('PRIME_SAFE_MODE', false),

    // Controls whether ZIP upload is enabled from the `/admin/plugins` screen.
    'allow_upload' => (bool) Env::get('PLUGINS_ALLOW_UPLOAD', true),

    // Maximum ZIP archive upload size in megabytes (default 20 MB).
    'max_upload_mb' => max(1, (int) Env::get('PLUGINS_MAX_UPLOAD_MB', '20')),

    // ZIP bomb & archive safety limits (L7).
    'max_zip_files' => 2000,
    'max_zip_uncompressed_bytes' => 100 * 1024 * 1024,
    'max_zip_compression_ratio' => 100.0,
];
