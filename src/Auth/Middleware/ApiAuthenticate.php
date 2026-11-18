<?php

declare(strict_types=1);

namespace Prime\Auth\Middleware;

use Prime\Api\Token\ApiTokenService;
use Prime\Http\Middleware\MiddlewareInterface;
use Prime\Http\Request;
use Prime\Http\Response;

/**
 * Authentifie les requêtes API via un jeton Bearer dans l'en-tête `Authorization`.
 *
 * Format attendu : `Authorization: Bearer <token>`
 *
 * - Un jeton valide définit les attributs `api.user`, `api.abilities` et `api.token_id`
 *   sur la requête, puis laisse passer.
 * - Un jeton présent mais invalide renvoie HTTP 401 `unauthenticated`.
 * - Un jeton passé dans la query string renvoie HTTP 400 `invalid_request`.
 * - L'absence de jeton laisse la requête continuer en anonyme (aucun attribut défini).
 */
final class ApiAuthenticate implements MiddlewareInterface
{
    public function __construct(private readonly ApiTokenService $tokens)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        // Un jeton dans la query string est refusé.
        $accessToken = $request->query('access_token');
        if ($accessToken !== null && is_string($accessToken) && $accessToken !== '') {
            return $this->jsonError('invalid_request', 'Le jeton doit être transmis via l\'en-tête Authorization: Bearer.', 400);
        }

        $authorization = $request->header('authorization');
        if (!is_string($authorization) || !str_starts_with(strtolower($authorization), 'bearer ')) {
            // Aucun jeton : la requête continue en anonyme.
            return $next($request);
        }

        $token = substr($authorization, 7);

        $auth = $this->tokens->authenticate($token);
        if ($auth === null) {
            return $this->jsonError('unauthenticated', 'Jeton d\'API invalide, révoqué ou expiré.', 401);
        }

        $request = $request->withAttribute('api.user', $auth['user']);
        $request = $request->withAttribute('api.abilities', $auth['abilities']);
        $request = $request->withAttribute('api.token_id', $auth['token_id']);

        return $next($request);
    }

    private function jsonError(string $code, string $message, int $status): Response
    {
        return Response::json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'status' => $status,
            ],
        ], $status, [
            'Content-Type' => 'application/json',
        ]);
    }
}
