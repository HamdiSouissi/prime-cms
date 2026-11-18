<?php

declare(strict_types=1);

namespace Prime\Api\Middleware;

use Prime\Cache\CacheManager;
use Prime\Http\Middleware\MiddlewareInterface;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Settings\Settings;

/**
 * Limite le nombre de requêtes par fenêtre glissante d'une minute.
 *
 * Un utilisateur authentifié par jeton est compté par jeton (`api.token_id`),
 * un appelant anonyme par adresse IP. Les en-têtes `X-RateLimit-Limit` et
 * `X-RateLimit-Remaining` sont renseignés sur chaque réponse ; au-delà du
 * quota, HTTP 429 `rate_limited` avec un en-tête `Retry-After`.
 *
 * Une limite `<= 0` désactive le contrôle pour la catégorie concernée.
 */
final class ApiRateLimit implements MiddlewareInterface
{
    private const WINDOW_SECONDS = 60;

    public function __construct(
        private readonly Settings $settings,
        private readonly CacheManager $cache,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $user = $request->attribute('api.user');
        $tokenId = $request->attribute('api.token_id');

        if ($user !== null && $tokenId !== null) {
            $limit = (int) $this->settings->getInt('api.rate_limit_authenticated', 60);
            $key = 'api:rl:token:' . (int) $tokenId;
        } else {
            $limit = (int) $this->settings->getInt('api.rate_limit_anonymous', 20);
            $key = 'api:rl:anon:' . ((string) ($request->server('REMOTE_ADDR') ?? '0.0.0.0'));
        }

        if ($limit <= 0) {
            return $next($request);
        }

        $current = (int) $this->cache->data()->increment($key, 1, self::WINDOW_SECONDS);
        $remaining = max(0, $limit - $current);

        $response = $next($request);
        $response = $response
            ->withHeader('X-RateLimit-Limit', (string) $limit)
            ->withHeader('X-RateLimit-Remaining', (string) $remaining);

        if ($current > $limit) {
            return Response::json([
                'error' => [
                    'code' => 'rate_limited',
                    'message' => t('api.errors.rate_limited'),
                    'status' => 429,
                ],
            ], 429, array_merge([
                'Content-Type' => 'application/json',
                'Retry-After' => (string) self::WINDOW_SECONDS,
            ], $response->headers(), [
                'X-RateLimit-Limit' => (string) $limit,
                'X-RateLimit-Remaining' => '0',
            ]));
        }

        return $response;
    }
}
