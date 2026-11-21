<?php

declare(strict_types=1);

namespace Prime\Auth\Middleware;

use Prime\Api\Token\ApiTokenService;
use Prime\Auth\Auth;
use Prime\Http\Middleware\MiddlewareInterface;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\I18n\Translator;
use Prime\Security\Csrf;

/**
 * Authentifie les requêtes API via un jeton Bearer dans l'en-tête `Authorization`,
 * ou via une session d'administration active.
 *
 * Format attendu : `Authorization: Bearer <token>`
 *
 * - Un jeton valide définit les attributs `api.user`, `api.abilities` et `api.token_id`
 *   sur la requête, puis laisse passer.
 * - Un jeton présent mais invalide renvoie HTTP 401 `unauthenticated`.
 * - Un jeton passé dans la query string renvoie HTTP 400 `invalid_request`.
 * - Sans jeton mais avec un utilisateur connecté en session, les attributs `api.user`
 *   (capacités du compte) et `api.abilities = ['*']` sont définis.
 * - Pour toute méthode autre que GET/HEAD authentifiée par session, l'en-tête
 *   `X-Prime-CSRF` est exigé, sinon 403.
 * - L'absence de jeton et de session laisse la requête continuer en anonyme.
 */
final class ApiAuthenticate implements MiddlewareInterface
{
    public const CSRF_HEADER = 'X-Prime-CSRF';

    public function __construct(
        private readonly ApiTokenService $tokens,
        private readonly Auth $auth,
        private readonly Csrf $csrf,
        private readonly Translator $translator,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        // Un jeton dans la query string est refusé.
        $accessToken = $request->query('access_token');
        if ($accessToken !== null && is_string($accessToken) && $accessToken !== '') {
            return $this->jsonError('invalid_request', 400);
        }

        $authorization = $request->header('authorization');
        $ip = $request->server('REMOTE_ADDR') ?? null;

        if (is_string($authorization) && str_starts_with(strtolower($authorization), 'bearer ')) {
            // Authentification par jeton Bearer.
            $token = substr($authorization, 7);

            $auth = $this->tokens->authenticate($token, $ip);
            if ($auth === null) {
                return $this->jsonError('unauthenticated', 401);
            }

            $request = $request->withAttribute('api.user', $auth['user']);
            $request = $request->withAttribute('api.abilities', $auth['abilities']);
            $request = $request->withAttribute('api.token_id', $auth['token_id']);

            return $next($request);
        }

        // Aucun jeton Bearer : tenter l'authentification par session.
        $sessionUser = $this->auth->user($request);
        if ($sessionUser !== null) {
            // A cookie-authenticated write must prove it comes from our own pages: the
            // `X-Prime-CSRF` header carries the session CSRF token. A bearer token is never
            // subject to CSRF since browsers do not attach it on their own.
            $method = strtoupper($request->method());
            if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
                $csrfToken = $request->header(self::CSRF_HEADER);
                if (!is_string($csrfToken) || !$this->csrf->validate($csrfToken)) {
                    return $this->jsonError('csrf_mismatch', 403);
                }
            }

            $request = $request->withAttribute('api.user', $sessionUser);
            $request = $request->withAttribute('api.abilities', ['*']);
            $request = $request->withAttribute('api.token_id', null);

            return $next($request);
        }

        // Aucun jeton ni session : la requête continue en anonyme.
        return $next($request);
    }

    /**
     * Retourne une réponse JSON d'erreur avec message traduit.
     *
     * Format : {"error": {"code": "...", "message": "...", "status": N}}
     *
     * @param array<string, string> $headers
     */
    private function jsonError(string $code, int $status, array $headers = []): Response
    {
        $message = $this->translator->translate('api.errors.' . $code);

        return Response::json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'status' => $status,
            ],
        ], $status, array_merge([
            'Content-Type' => 'application/json',
        ], $headers));
    }
}
