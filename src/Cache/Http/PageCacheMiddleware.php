<?php

declare(strict_types=1);

namespace Prime\Cache\Http;

use Prime\Cache\PageCache;
use Prime\Http\Middleware\MiddlewareInterface;
use Prime\Http\Request;
use Prime\Http\Response;

/**
 * Global HTTP middleware serving and storing full-page cache entries for anonymous public GET requests.
 *
 * Registered as the innermost global middleware so that security headers, trailing-slash redirects,
 * and the installation gate always run before (and after) the cached response is returned.
 */
final class PageCacheMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly PageCache $cache,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        if (!$this->cache->isEnabled()) {
            return $next($request);
        }

        if (!$this->cache->isCacheableRequest($request)) {
            return $next($request);
        }

        $cached = $this->cache->lookup($request);
        if ($cached !== null) {
            return $cached;
        }

        $response = $next($request);

        return $this->cache->store($request, $response);
    }
}
