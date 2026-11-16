<?php

declare(strict_types=1);

namespace Prime\Themes\Exception;

/**
 * Raised when a theme manifest or theme option payload fails validation.
 */
final class ThemeValidationException extends ThemeException
{
    /**
     * @param array<string, list<string>> $errors
     */
    public function __construct(
        string $message,
        private readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
