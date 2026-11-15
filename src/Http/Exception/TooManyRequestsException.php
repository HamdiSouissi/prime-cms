<?php

declare(strict_types=1);

namespace Prime\Http\Exception;

use Throwable;

/**
 * Thrown when a client exceeds a rate-limiting threshold (HTTP 429 Too Many Requests).
 */
final class TooManyRequestsException extends HttpException
{
    public function __construct(
        string $message = 'Trop de tentatives. Veuillez réessayer ultérieurement.',
        ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        $headers = $retryAfterSeconds !== null && $retryAfterSeconds > 0
            ? ['Retry-After' => (string) $retryAfterSeconds]
            : [];

        parent::__construct(429, $message, $headers, $previous);
    }
}
