<?php

declare(strict_types=1);

namespace Prime\Database\Exception;

/**
 * Thrown when establishing or maintaining a PDO database connection fails.
 *
 * Messages never expose database passwords or full DSN strings.
 */
final class ConnectionException extends DatabaseException
{
    public function __construct(
        string $message,
        private readonly string $sqlState = '',
        private readonly int $driverCode = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $driverCode, $previous);
    }

    public function sqlState(): string
    {
        return $this->sqlState;
    }

    public function driverCode(): int
    {
        return $this->driverCode;
    }
}
