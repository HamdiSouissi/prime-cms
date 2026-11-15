<?php

declare(strict_types=1);

use Prime\Kernel\Application;
use Prime\Kernel\Autoloader;

/** @var Autoloader $autoloader */
$autoloader = require __DIR__ . '/autoload.php';

$app = new Application(dirname(__DIR__));
$app->container()->instance(Autoloader::class, $autoloader);
$app->boot();

return $app;
