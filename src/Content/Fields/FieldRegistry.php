<?php

declare(strict_types=1);

namespace Prime\Content\Fields;

use InvalidArgumentException;
use Prime\Hooks\Hooks;

/**
 * Registry of code-declared custom field groups (extensible via `content.field_groups`).
 */
final class FieldRegistry
{
    /**
     * @var array<string, FieldGroup>
     */
    private array $groups = [];

    public function __construct(
        private readonly ?Hooks $hooks = null,
    ) {
    }

    public function registerGroup(FieldGroup $group): void
    {
        $this->groups[$group->id] = $group;
    }

    /**
     * @return array<string, FieldGroup>
     */
    public function all(): array
    {
        $sorted = $this->resolved();
        uasort($sorted, static function (FieldGroup $a, FieldGroup $b): int {
            $cmp = $a->order <=> $b->order;

            return $cmp !== 0 ? $cmp : strcmp($a->id, $b->id);
        });

        return $sorted;
    }

    /**
     * Return all field groups targeting `$contentType`, optionally filtered by `main` or `sidebar`.
     *
     * @return list<FieldGroup>
     */
    public function forContentType(string $contentType, ?string $location = null): array
    {
        $result = [];

        foreach ($this->all() as $group) {
            if (!$group->appliesTo($contentType)) {
                continue;
            }
            if ($location !== null && $group->location !== $location) {
                continue;
            }
            $result[] = $group;
        }

        return $result;
    }

    /**
     * Return flat map of all fields defined for a content type keyed by field name.
     *
     * @return array<string, Field>
     */
    public function fieldsForContentType(string $contentType): array
    {
        $fields = [];
        foreach ($this->forContentType($contentType) as $group) {
            foreach ($group->fields as $field) {
                $fields[$field->name] = $field;
            }
        }

        return $fields;
    }

    /**
     * @return array<string, FieldGroup>
     */
    private function resolved(): array
    {
        if ($this->hooks === null) {
            return $this->groups;
        }

        $filtered = $this->hooks->apply('content.field_groups', $this->groups);
        if (!is_array($filtered)) {
            return $this->groups;
        }

        $validated = [];
        foreach ($filtered as $key => $group) {
            if ($group instanceof FieldGroup) {
                $validated[$group->id] = $group;
            } elseif (is_string($key)) {
                throw new InvalidArgumentException(sprintf(
                    'Filter "content.field_groups" returned invalid definition for group [%s].',
                    $key,
                ));
            }
        }

        return $validated;
    }
}
