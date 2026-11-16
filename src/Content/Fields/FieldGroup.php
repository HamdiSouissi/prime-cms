<?php

declare(strict_types=1);

namespace Prime\Content\Fields;

use InvalidArgumentException;

/**
 * Immutable group of custom fields rendered in the editor (`main` column or `sidebar`).
 */
final readonly class FieldGroup
{
    public const LOCATION_MAIN = 'main';
    public const LOCATION_SIDEBAR = 'sidebar';

    public const ID_PATTERN = '/^[a-z][a-z0-9_.-]{1,100}$/';

    /**
     * @param list<string> $contentTypes
     * @param list<Field> $fields
     */
    public function __construct(
        public string $id,
        public string $title,
        public array $contentTypes,
        public array $fields,
        public string $location = self::LOCATION_MAIN,
        public int $order = 10,
        public string $description = '',
        public string $source = 'core',
    ) {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid FieldGroup id [%s].', $id));
        }

        if (trim($title) === '') {
            throw new InvalidArgumentException(sprintf('FieldGroup [%s] must have a non-empty title.', $id));
        }

        if (!in_array($location, [self::LOCATION_MAIN, self::LOCATION_SIDEBAR], true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid FieldGroup location [%s] on [%s]. Expected "main" or "sidebar".',
                $location,
                $id,
            ));
        }
    }

    public function appliesTo(string $contentType): bool
    {
        return $this->contentTypes === [] || in_array($contentType, $this->contentTypes, true);
    }

    public function withSource(string $source): self
    {
        return new self(
            id: $this->id,
            title: $this->title,
            contentTypes: $this->contentTypes,
            fields: $this->fields,
            location: $this->location,
            order: $this->order,
            description: $this->description,
            source: $source,
        );
    }
}
