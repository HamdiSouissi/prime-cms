<?php

declare(strict_types=1);

namespace Prime\Auth\Middleware;

use Prime\Auth\Auth;
use Prime\Http\Middleware\MiddlewareInterface;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Http\Session;

/**
 * Ensures the visitor is authenticated before entering `/admin` routes.
 *
 * - Redirects guests to `/admin/login` while saving a validated internal `/admin*` intended URL
 *   (strictly rejecting external URLs, protocol-relative URLs, backslashes, or CRLF).
 * - Returns HTTP 401 JSON when the client requests JSON (`Accept: application/json`).
 * - Applies `Cache-Control: no-store` and `Pragma: no-cache` on all admin responses.
 */
final class Authenticate implements MiddlewareInterface
{
    public const SESSION_INTENDED_KEY = '_auth.intended_url';

    public function __construct(
        private readonly Auth $auth,
        private readonly Session $session,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        if (!$this->auth->check($request)) {
            if ($request->wantsJson()) {
                return Response::json([
                    'error' => [
                        'code' => 401,
                        'message' => 'Authentification requise.',
                    ],
                ], 401)->withHeaders(NoStoreHeaders::HEADERS);
            }

            if (in_array($request->method(), ['GET', 'HEAD'], true)) {
                $target = $request->path();
                $query = $request->query();
                if ($query !== []) {
                    $target .= '?' . http_build_query($query);
                }

                if (self::isSafeAdminRedirect($target)) {
                    $this->session->set(self::SESSION_INTENDED_KEY, $target);
                }
            }

            return Response::redirect('/admin/login', 302)->withHeaders(NoStoreHeaders::HEADERS);
        }

        $response = $next($request);

        return $response->withHeaders(NoStoreHeaders::HEADERS);
    }

    /**
     * Validate an intended post-login redirect URL to strictly prevent open redirects.
     *
     * Only allows relative internal paths starting with `/admin` (never `//`, schemes, backslashes,
     * control characters, or auth endpoints such as `/admin/login` / `/admin/logout`).
     */
    public static function isSafeAdminRedirect(?string $url): bool
    {
        if ($url === null || $url === '') {
            return false;
        }

        // Reject control characters (CR, LF, NUL, tabs) or backslashes.
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $url) === 1) {
            return false;
        }

        // Reject protocol-relative (`//evil.com`) or scheme-prefixed (`https://...`) URLs.
        if (str_starts_with($url, '//') || str_contains($url, '://')) {
            return false;
        }

        $parsed = parse_url($url);
        if ($parsed === false) {
            return false;
        }

        if (isset($parsed['scheme']) || isset($parsed['host']) || isset($parsed['user']) || isset($parsed['pass'])) {
            return false;
        }

        $path = $parsed['path'] ?? '';
        if ($path !== '/admin' && !str_starts_with($path, '/admin/')) {
            return false;
        }

        // Reject traversal segments or auth loop targets.
        if (str_contains($path, '..')) {
            return false;
        }

        $disallowed = [
            '/admin/login',
            '/admin/login/two-factor',
            '/admin/logout',
            '/admin/forgot-password',
            '/admin/reset-password',
        ];

        foreach ($disallowed as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Pop and return a validated intended redirect URL from session, defaulting to `/admin`.
     */
    public static function pullIntendedUrl(Session $session): string
    {
        $raw = $session->get(self::SESSION_INTENDED_KEY);
        $session->remove(self::SESSION_INTENDED_KEY);

        if (is_string($raw) && self::isSafeAdminRedirect($raw)) {
            return $raw;
        }

        return '/admin';
    }
}
