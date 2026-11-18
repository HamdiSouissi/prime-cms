<?php

declare(strict_types=1);

namespace Prime\Api\Middleware;

use Prime\Http\Middleware\MiddlewareInterface;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Settings\Settings;

/**
 * Active ou désactive l'API REST entière via le réglage `api.enabled`.
 *
 * Quand l'API est désactivée, toute requête visant une route API reçoit une
 * réponse JSON 404 `not_found`. Par défaut, l'API est activée.
 */
final class ApiEnabled implements MiddlewareInterface
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        if (!$this->settings->getBool('api.enabled', true)) {
            return Response::json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'L\'API REST est désactivée.',
                    'status' => 404,
                ],
            ], 404, [
                'Content-Type' => 'application/json',
            ]);
        }

        return $next($request);
    }
}
