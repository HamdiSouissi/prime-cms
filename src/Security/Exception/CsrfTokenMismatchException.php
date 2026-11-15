<?php

declare(strict_types=1);

namespace Prime\Security\Exception;

use Prime\Http\Exception\HttpException;

/**
 * Thrown when a state-changing HTTP request fails CSRF verification (HTTP 419).
 */
final class CsrfTokenMismatchException extends HttpException
{
    public function __construct(string $message = 'Jeton CSRF invalide ou session expirée.')
    {
        parent::__construct(419, $message);
    }
}
