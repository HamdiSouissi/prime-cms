<?php

declare(strict_types=1);

namespace Prime\Auth\Middleware;

use Prime\Http\Middleware\MiddlewareInterface;
use Prime\Http\Request;
use Prime\Http\Response;

/**
 * Prevents browsers and intermediate proxies from caching sensitive admin or auth pages.
 */
final class NoStoreHeaders implements MiddlewareInterface
{
    /**
     * @var array<string, string>
     */
    public const HEADERS = [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        'Pragma' => 'no-cache',
    ];

    public function process(Request $request, callable $next): Response
    {
        return $next($request)->withHeaders(self::HEADERS);
    }
}
