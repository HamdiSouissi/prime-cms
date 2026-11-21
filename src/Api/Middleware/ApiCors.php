<?php

declare(strict_types=1);

namespace Prime\Api\Middleware;

use Prime\Http\Middleware\MiddlewareInterface;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Settings\Settings;

/**
 * Configure CORS sur l'API REST selon le réglage `api.cors_origins`
 * (une origine autorisée par ligne, vide = aucun en-tête CORS).
 *
 * Les requêtes de préflight `OPTIONS` reçoivent une réponse 204 avec les
 * en-têtes CORS quand l'origine est autorisée.
 */
final class ApiCors implements MiddlewareInterface
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        // CORS is a property of the REST API only. Browsers also send `Origin` on ordinary form
        // posts (admin, installer): those must not read the settings, which do not exist yet
        // while the site is being installed.
        $path = $request->path();
        if ($path !== '/api' && !str_starts_with($path, '/api/')) {
            return $next($request);
        }

        $origin = $request->header('origin');

        if ($origin === null || $origin === '' || !$this->isAllowed($origin)) {
            return $next($request);
        }

        // Préflight : répondre directement sans invoquer le contrôleur.
        if (strtoupper((string) $request->method()) === 'OPTIONS') {
            $allowHeaders = $request->header('access-control-request-headers') ?? '*';

            return Response::noContent([
                'Access-Control-Allow-Origin' => $origin,
                'Access-Control-Allow-Methods' => 'GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS',
                'Access-Control-Allow-Headers' => $allowHeaders,
                'Vary' => 'Origin',
            ])->withStatus(204);
        }

        $response = $next($request);

        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Vary', $this->varyWithOrigin($response));
    }

    private function isAllowed(string $origin): bool
    {
        $origins = $this->settings->getString('api.cors_origins', '');
        foreach (explode("\n", $origins) as $raw) {
            $candidate = trim((string) $raw);
            if ($candidate !== '' && $candidate === $origin) {
                return true;
            }
        }

        return false;
    }

    private function varyWithOrigin(Response $response): string
    {
        $existing = $response->header('Vary');
        $parts = $existing !== null ? array_map('trim', explode(',', (string) $existing)) : [];

        if (!in_array('Origin', $parts, true)) {
            $parts[] = 'Origin';
        }

        return implode(', ', $parts);
    }
}
