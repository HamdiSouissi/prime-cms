<?php

declare(strict_types=1);

namespace Prime\Api\Middleware;

use Prime\Http\Middleware\MiddlewareInterface;
use Prime\Http\Request;
use Prime\Http\Response;

/**
 * Vérifie que l'utilisateur authentifié par l'API détient la capacité requise.
 *
 * Lit l'attribut `api.user` défini par `ApiAuthenticate`. Retourne 403 `forbidden`
 * si l'utilisateur est absent ou ne détient pas la capacité.
 */
final class ApiAuthorize implements MiddlewareInterface
{
    public function __construct(private readonly string $capability)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $user = $request->attribute('api.user');

        if ($user === null || !$user->can($this->capability)) {
            return Response::json([
                'error' => [
                    'code' => 'forbidden',
                    'message' => 'Accès interdit.',
                    'status' => 403,
                ],
            ], 403, [
                'Content-Type' => 'application/json',
            ]);
        }

        return $next($request);
    }
}
