<?php

declare(strict_types=1);

namespace Prime\Api\Middleware;

use Prime\Http\Middleware\MiddlewareInterface;
use Prime\Http\Request;
use Prime\Http\Response;

/**
 * Enregistre l'heure de début de la requête API pour le calcul de la durée
 * de l'événement `api.request` (émis via le hook `http.response`).
 */
final class ApiLifecycle implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        $request = $request->withAttribute('api.start_time', microtime(true));

        return $next($request);
    }
}
