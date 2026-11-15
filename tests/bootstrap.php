<?php

declare(strict_types=1);

use Prime\Kernel\Env;

/**
 * PHPUnit isolated test bootstrap.
 *
 * Guarantees tests NEVER read a developer's local `.env`, `storage/installed.lock`,
 * `storage/sessions`, or `storage/logs`, while preserving `PRIME_TEST_DB_*` variables
 * passed explicitly for `#[Group('database')]` integration suites.
 */
$rootDir = dirname(__DIR__);
$composerAutoload = $rootDir . '/vendor/autoload.php';
$coreAutoload = $rootDir . '/bootstrap/autoload.php';

if (is_file($composerAutoload)) {
    require_once $composerAutoload;
} elseif (is_file($coreAutoload)) {
    require_once $coreAutoload;
}

$isolatedStorage = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR . '/')
    . DIRECTORY_SEPARATOR
    . 'prime_phpunit_storage_'
    . getmypid()
    . '_'
    . bin2hex(random_bytes(4));

foreach (['', 'logs', 'sessions', 'cache', 'tmp', 'plugins'] as $sub) {
    $dir = $sub === '' ? $isolatedStorage : $isolatedStorage . DIRECTORY_SEPARATOR . $sub;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

$isolatedEnv = [
    'APP_ENV' => 'testing',
    'APP_NAME' => 'Prime CMS',
    'APP_DEBUG' => 'false',
    'APP_URL' => 'http://localhost:8080',
    'APP_TIMEZONE' => 'UTC',
    'APP_LOCALE' => 'fr',
    'APP_KEY' => '',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_DATABASE' => 'prime_cms_test',
    'DB_USERNAME' => 'prime',
    'DB_PASSWORD' => '',
    'DB_PREFIX' => 'pc_',
    'LOG_LEVEL' => 'debug',
    'LOG_PATH' => $isolatedStorage . DIRECTORY_SEPARATOR . 'logs',
    'PRIME_STORAGE_PATH' => $isolatedStorage,
    'PRIME_DISABLE_DOTENV' => 'true',
    'PRIME_SAFE_MODE' => 'false',
    'PLUGINS_ALLOW_UPLOAD' => 'true',
    'PLUGINS_MAX_UPLOAD_MB' => '20',
];

foreach ($isolatedEnv as $key => $value) {
    Env::set($key, $value, overwrite: true);
}

register_shutdown_function(static function () use ($isolatedStorage): void {
    if (!is_dir($isolatedStorage)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($isolatedStorage, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    /** @var SplFileInfo $item */
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($isolatedStorage);
});
