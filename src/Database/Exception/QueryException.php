<?php

declare(strict_types=1);

namespace Prime\Database\Exception;

/**
 * Thrown when a prepared SQL statement fails during preparation or execution.
 */
final class QueryException extends DatabaseException
{
    public function __construct(
        string $message,
        private readonly string $sql = '',
        private readonly string $sqlState = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function sql(): string
    {
        return $this->sql;
    }

    public function sqlState(): string
    {
        return $this->sqlState;
    }
}
