<?php

declare(strict_types=1);

use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Kernel\Application;

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

$request = Request::fromGlobals();

/** @var Kernel $kernel */
$kernel = $app->get(Kernel::class);
$response = $kernel->handle($request);

$response->send();
