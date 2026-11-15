<?php

declare(strict_types=1);

use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Kernel\Application;
use Prime\Kernel\ErrorHandler;

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

/** @var ErrorHandler $errorHandler */
$errorHandler = $app->get(ErrorHandler::class);
$errorHandler->register();

$request = Request::fromGlobals();

/** @var Kernel $kernel */
$kernel = $app->get(Kernel::class);
$response = $kernel->handle($request);

$response->send();
