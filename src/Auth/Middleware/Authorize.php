<?php

declare(strict_types=1);

namespace Prime\Auth\Middleware;

use Prime\Auth\Auth;
use Prime\Http\Exception\ForbiddenException;
use Prime\Http\Middleware\MiddlewareInterface;
use Prime\Http\Request;
use Prime\Http\Response;

/**
 * Capability-checking route middleware (`can:<capability>`).
 *
 * Throws `ForbiddenException` (HTTP 403 HTML or JSON via ErrorHandler) when the
 * authenticated user does not hold the required capability.
 */
final class Authorize implements MiddlewareInterface
{
    public function __construct(
        private readonly Auth $auth,
        private readonly string $capability,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        if (!$this->auth->can($this->capability, $request)) {
            throw new ForbiddenException(
                'Vous ne disposez pas des permissions nécessaires pour accéder à cette ressource.',
            );
        }

        return $next($request);
    }
}
