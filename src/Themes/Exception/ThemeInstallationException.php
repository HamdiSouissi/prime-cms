<?php

declare(strict_types=1);

namespace Prime\Themes\Exception;

/**
 * Raised when ZIP archive validation or theme installation fails.
 */
final class ThemeInstallationException extends ThemeException
{
    public function __construct(
        string $message,
        public readonly ?string $conflictSlug = null,
        public readonly ?string $existingVersion = null,
        public readonly ?string $incomingVersion = null,
        public readonly bool $isDowngradeOrSame = false,
    ) {
        parent::__construct($message);
    }

    public function isConflict(): bool
    {
        return $this->conflictSlug !== null;
    }
}
