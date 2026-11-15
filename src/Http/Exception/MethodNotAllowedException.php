<?php

declare(strict_types=1);

namespace Prime\Http\Exception;

use Throwable;

/**
 * HTTP 405 Method Not Allowed exception with an `Allow` header.
 */
final class MethodNotAllowedException extends HttpException
{
    /**
     * @var list<string>
     */
    private readonly array $allowedMethods;

    /**
     * @param list<string> $allowedMethods
     * @param array<string, string> $headers
     */
    public function __construct(
        array $allowedMethods,
        string $message = 'Méthode non autorisée.',
        array $headers = [],
        ?Throwable $previous = null,
    ) {
        $normalized = array_values(array_unique(array_map('strtoupper', $allowedMethods)));
        $this->allowedMethods = $normalized;
        $headers['Allow'] = implode(', ', $normalized);

        parent::__construct(405, $message, $headers, $previous);
    }

    /**
     * @return list<string>
     */
    public function allowedMethods(): array
    {
        return $this->allowedMethods;
    }
}
