<?php

declare(strict_types=1);

namespace Prime\Http\Exception;

use Throwable;

/**
 * HTTP 404 Not Found exception.
 */
final class NotFoundException extends HttpException
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        string $message = 'Page introuvable.',
        array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct(404, $message, $headers, $previous);
    }
}
