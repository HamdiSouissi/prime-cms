<?php

declare(strict_types=1);

namespace Prime\Api\Middleware;

use Prime\Hooks\Hooks;
use Prime\Http\Middleware\MiddlewareInterface;
use Prime\Http\Request;
use Prime\Http\Response;

/**
 * Gestion du cycle de vie des requêtes API.
 *
 * - Stocke l'heure de debut de la requête sur l'attribut `api.start_time`.
 * - Emet l'evenement `api.request` des le debut de la requete.
 * - Apres la reponse, ajoute l'en-tete `X-Response-Time` et applique le
 *   filtre `api.response` (les filtres provenant de plugins qui levnt une
 *   exception sont isoles par le moteur de hooks et n'interrompent jamais la
 *   reponse).
 */
final class ApiLifecycle implements MiddlewareInterface
{
    public function __construct(private readonly Hooks $hooks)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $request = $request->withAttribute('api.start_time', microtime(true));

        $this->hooks->emit('api.request', $request);

        $response = $next($request);

        $start = $request->getAttribute('api.start_time', microtime(true));
        $durationMs = (microtime(true) - $start) * 1000;
        $response = $response->withHeader('X-Response-Time', sprintf('%.2fms', $durationMs));

        $filtered = $this->hooks->apply('api.response', $response, $request);
        if ($filtered instanceof Response) {
            $response = $filtered;
        }

        return $response;
    }
}
