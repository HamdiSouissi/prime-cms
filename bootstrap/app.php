<?php

declare(strict_types=1);

use Prime\Kernel\Application;
use Prime\Kernel\Autoloader;

/** @var Autoloader $autoloader */
$autoloader = require __DIR__ . '/autoload.php';

$registerErrorHandler = !defined('PHPUNIT_COMPOSER_INSTALL') && !defined('__PHPUNIT_PHAR__');

$app = new Application(dirname(__DIR__), registerErrorHandler: $registerErrorHandler);
$app->container()->instance(Autoloader::class, $autoloader);
$app->boot();

return $app;
