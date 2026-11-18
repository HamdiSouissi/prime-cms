<?php

declare(strict_types=1);

namespace Prime\Api\Middleware;

use Prime\Api\ApiError;
use Prime\Api\ApiRoute;
use Prime\Auth\User;
use Prime\Http\Middleware\MiddlewareInterface;
use Prime\Http\Request;
use Prime\Http\Response;

/**
 * Enforces the capability an API route declares (`api.can:<capability>`).
 *
 * The user attached by `ApiAuthenticate` already carries the effective capabilities
 * (account ∩ token), so a plain `can()` covers both. Anonymous callers get 401, callers
 * lacking the capability 403.
 */
final class ApiAuthorize implements MiddlewareInterface
{
    public function __construct(private readonly string $capability)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $user = $request->attribute('api.user');
        if (!$user instanceof User) {
            return ApiError::response('unauthenticated', 401, $request);
        }

        if ($this->capability !== '' && $this->capability !== ApiRoute::AUTHENTICATED && !$user->can($this->capability)) {
            return ApiError::response('forbidden', 403, $request);
        }

        return $next($request);
    }
}
