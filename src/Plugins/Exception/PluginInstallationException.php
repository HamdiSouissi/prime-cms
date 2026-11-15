<?php

declare(strict_types=1);

namespace Prime\Plugins\Exception;

use Throwable;

/**
 * Thrown when ZIP plugin archive validation or installation fails,
 * including recoverable version-replacement conflicts.
 */
final class PluginInstallationException extends PluginException
{
    public function __construct(
        string $message,
        public readonly ?string $conflictSlug = null,
        public readonly ?string $existingVersion = null,
        public readonly ?string $incomingVersion = null,
        public readonly bool $isDowngradeOrSame = false,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function isReplacementConflict(): bool
    {
        return $this->conflictSlug !== null;
    }
}
