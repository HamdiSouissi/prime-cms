<?php

declare(strict_types=1);

namespace Prime\Http\Middleware;

use Prime\Http\Request;
use Prime\Http\Response;

/**
 * Contract for HTTP middlewares executed around route dispatch.
 */
interface MiddlewareInterface
{
    /**
     * Process an incoming request and delegate to the next handler in the pipeline.
     *
     * @param callable(Request): Response $next
     */
    public function process(Request $request, callable $next): Response;
}
