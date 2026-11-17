<?php

declare(strict_types=1);

namespace Prime\Http\Middleware;

use Prime\Http\Request;
use Prime\Http\Response;

/**
 * No-op middleware used when an optional pipeline stage cannot be built — for instance the page
 * cache or the locale resolver on a site whose tables do not exist yet.
 *
 * Registering a stage as a middleware *alias* whose factory falls back to this class keeps the
 * pipeline order intact while letting `/install` and `/health` answer before installation.
 */
final class PassthroughMiddleware implements MiddlewareInterface
{
    /**
     * @param callable(Request): Response $next
     */
    public function process(Request $request, callable $next): Response
    {
        return $next($request);
    }
}
