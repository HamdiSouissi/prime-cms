<?php

declare(strict_types=1);

namespace Prime\Install\Exception;

use RuntimeException;
use Throwable;

/**
 * Thrown when an installation step fails, identifying the exact failing step.
 */
final class InstallException extends RuntimeException
{
    public function __construct(
        private readonly string $step,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function step(): string
    {
        return $this->step;
    }
}
