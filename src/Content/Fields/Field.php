<?php

declare(strict_types=1);

namespace Prime\Content\Fields;

use InvalidArgumentException;

/**
 * Immutable definition of a single custom field attached to a FieldGroup.
 */
final readonly class Field
{
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,120}$/';

    /**
     * All 12 supported Phase 6 field types.
     *
     * @var list<string>
     */
    public const VALID_TYPES = [
        'text',
        'textarea',
        'number',
        'email',
        'url',
        'select',
        'checkbox',
        'toggle',
        'date',
        'datetime',
        'color',
        'entry',
    ];

    /**
     * @param array<string, string> $options Key => label options for `select` fields
     * @param list<string> $entryTypes Allowed content types for `entry` relation fields
     * @param list<string> $rules Extra `Prime\Validation\Validator` rules
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $label,
        public string $description = '',
        public bool $required = false,
        public mixed $default = null,
        public string $placeholder = '',
        public array $options = [],
        public ?float $min = null,
        public ?float $max = null,
        public ?float $step = null,
        public array $entryTypes = [],
        public array $rules = [],
    ) {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid custom field name [%s].', $name));
        }

        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported custom field type [%s] on field [%s].',
                $type,
                $name,
            ));
        }

        if (trim($label) === '') {
            throw new InvalidArgumentException(sprintf('Custom field [%s] must have a label.', $name));
        }
    }
}
