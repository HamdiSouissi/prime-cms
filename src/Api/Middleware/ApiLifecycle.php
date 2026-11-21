<?php

declare(strict_types=1);

namespace Prime\Api\Middleware;

use Prime\Auth\User;
use Prime\Hooks\Hooks;
use Prime\Http\Middleware\MiddlewareInterface;
use Prime\Http\Request;
use Prime\Http\Response;
use Throwable;

/**
 * Outermost API middleware: times the request, lets plugins adjust the response through the
 * `api.response` filter and announces the `api.request` event afterwards.
 *
 * A filter that throws or returns something other than a Response is ignored: a plugin can
 * never break an API answer. The event payload carries no request body and no secret.
 */
final class ApiLifecycle implements MiddlewareInterface
{
    public function __construct(private readonly Hooks $hooks)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $started = microtime(true);
        $response = $next($request);

        try {
            $filtered = $this->hooks->apply('api.response', $response, $request);
            if ($filtered instanceof Response) {
                $response = $filtered;
            }
        } catch (Throwable) {
            // Keep the core response: a broken filter must not change what the client receives.
        }

        $user = $request->attribute('api.user');

        try {
            $this->hooks->emit('api.request', [
                'method' => strtoupper($request->method()),
                'path' => $request->path(),
                'status' => $response->status(),
                'duration_ms' => round((microtime(true) - $started) * 1000, 2),
                'user_id' => $user instanceof User ? $user->id : null,
                'token_id' => $request->attribute('api.token_id'),
            ]);
        } catch (Throwable) {
            // Listeners are informational only.
        }

        return $response;
    }
}
