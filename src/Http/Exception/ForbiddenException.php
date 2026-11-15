<?php

declare(strict_types=1);

namespace Prime\Http\Exception;

use Throwable;

/**
 * Thrown when an authenticated user lacks the capability required to access a route or resource.
 */
final class ForbiddenException extends HttpException
{
    public function __construct(
        string $message = 'Vous n\'avez pas la permission d\'accéder à cette ressource.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(403, $message, [], $previous);
    }
}
