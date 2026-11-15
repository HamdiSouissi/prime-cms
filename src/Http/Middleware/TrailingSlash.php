<?php

declare(strict_types=1);

namespace Prime\Http\Middleware;

use Prime\Http\Request;
use Prime\Http\Response;

/**
 * Redirects URLs ending with a trailing slash (except `/`) via 301 Permanent Redirect.
 */
final class TrailingSlash implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        $path = $request->path();

        if ($path !== '/' && str_ends_with($path, '/')) {
            $normalized = rtrim($path, '/');
            if ($normalized === '') {
                $normalized = '/';
            }

            $query = $request->query();
            if (is_array($query) && $query !== []) {
                $normalized .= '?' . http_build_query($query);
            }

            return Response::redirect($normalized, 301);
        }

        return $next($request);
    }
}
