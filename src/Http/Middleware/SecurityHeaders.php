<?php

declare(strict_types=1);

namespace Prime\Http\Middleware;

use Prime\Http\Request;
use Prime\Http\Response;

/**
 * Applies baseline HTTP security headers to every outgoing response.
 */
final class SecurityHeaders implements MiddlewareInterface
{
    /**
     * Default security headers enforced across the application.
     *
     * @var array<string, string>
     */
    private const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Content-Security-Policy' => "default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; script-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'",
    ];

    public function process(Request $request, callable $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }
}
