<?php

declare(strict_types=1);

use Prime\Kernel\Autoloader;

$rootDir = dirname(__DIR__);

if (!class_exists(Autoloader::class, false)) {
    require_once $rootDir . '/src/Kernel/Autoloader.php';
}

$autoloader = null;

foreach (spl_autoload_functions() as $callback) {
    if (is_array($callback) && $callback[0] instanceof Autoloader) {
        $autoloader = $callback[0];
        break;
    }
}

if (!$autoloader instanceof Autoloader) {
    $autoloader = new Autoloader();
    $autoloader->addNamespace('Prime\\', $rootDir . '/src');
    $autoloader->register();
}

require_once $rootDir . '/src/Support/helpers.php';

return $autoloader;
