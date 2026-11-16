<?php

declare(strict_types=1);

namespace Prime\Content\Exception;

/**
 * Thrown when entry data, blocks, or custom fields fail validation.
 */
final class ContentValidationException extends ContentException
{
    /**
     * @param array<string, list<string>> $errors
     */
    public function __construct(
        private readonly array $errors,
        string $message = 'Les données du contenu sont invalides.',
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

    /**
     * @return list<string>
     */
    public function flatErrors(): array
    {
        $flat = [];
        foreach ($this->errors as $messages) {
            foreach ($messages as $msg) {
                $flat[] = $msg;
            }
        }

        return $flat;
    }
}
