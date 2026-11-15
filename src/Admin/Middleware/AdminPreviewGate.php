<?php

declare(strict_types=1);

namespace Prime\Admin\Middleware;

use Prime\Http\Exception\NotFoundException;
use Prime\Http\Middleware\MiddlewareInterface;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Kernel\Application;

/**
 * Temporary Phase 2 preview gate restricting `/admin/*` routes to `APP_ENV=local`.
 *
 * Returns a standard 404 outside local development so no unauthenticated dashboard
 * shell is ever exposed in staging or production. Will be replaced by real session
 * authentication and RBAC middleware in Phase 4.
 */
final class AdminPreviewGate implements MiddlewareInterface
{
    public function __construct(private readonly Application $app)
    {
    }

    /**
     * @param callable(Request): Response $next
     */
    public function process(Request $request, callable $next): Response
    {
        if ($this->app->environment() !== 'local') {
            throw new NotFoundException('Page introuvable.');
        }

        return $next($request);
    }
}
