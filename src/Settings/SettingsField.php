<?php

declare(strict_types=1);

namespace Prime\Settings;

use InvalidArgumentException;

/**
 * Declarative description of one setting: how it is stored, validated, sanitized and rendered.
 *
 * Screens (and the REST/CLI writers) never hand-code `switch ($type)` blocks — they iterate the
 * registry, which keeps form HTML, sanitization and persistence in lockstep.
 */
final class SettingsField
{
    /**
     * Every storage/rendering strategy understood by the registry.
     *
     * @var list<string>
     */
    public const TYPES = [
        'text',
        'textarea',
        'code',
        'number',
        'email',
        'url',
        'checkbox',
        'toggle',
        'radio',
        'select',
        'multiselect',
        'color',
        'date',
        'datetime',
        'time',
        'media',
        'hidden',
        'secret',
    ];

    /**
     * Types whose value must never be exposed to unprivileged readers or returned by the browser.
     *
     * @var list<string>
     */
    public const SENSITIVE_TYPES = ['secret'];

    /**
     * @param array<int|string, string> $options
     * @param array<string, mixed>      $attributes
     * @param array<array-key, mixed>   $rules
     * @param array<string, mixed>      $conditions
     * @param array<string, mixed>      $ui
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type = 'text',
        public readonly string $section = 'general',
        public readonly mixed $default = null,
        public readonly ?string $description = null,
        public readonly ?string $helpUrl = null,
        public readonly array $options = [],
        public readonly ?string $optionsSource = null,
        public readonly array $rules = [],
        public readonly bool $autoload = true,
        public readonly bool $encrypted = false,
        public readonly bool $public = false,
        public readonly int $priority = 10,
        public readonly ?string $placeholder = null,
        public readonly array $attributes = [],
        public readonly array $conditions = [],
        public readonly array $ui = [],
    ) {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException(sprintf('Type de réglage inconnu : « %s ».', $type));
        }

        if ($name === '' || !preg_match('/^[a-z0-9_.\-]{1,191}$/', $name)) {
            throw new InvalidArgumentException(sprintf('Nom de réglage invalide : « %s ».', $name));
        }
    }

    /**
     * Build a field from the array shape used by core, themes and plugins.
     *
     * @param array<string, mixed> $definition
     */
    public static function fromArray(array $definition): self
    {
        $name = trim((string) ($definition['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Un réglage déclaré doit porter une clé « name ».');
        }

        $rules = $definition['rules'] ?? [];
        if (is_string($rules)) {
            $rules = self::parseRules($rules);
        }

        $options = $definition['options'] ?? [];
        $source = isset($definition['source']) && is_string($definition['source']) ? $definition['source'] : null;
        if (is_string($options)) {
            $source = $options;
            $options = [];
        }

        return new self(
            name: $name,
            label: (string) ($definition['label'] ?? $name),
            type: (string) ($definition['type'] ?? 'text'),
            section: (string) ($definition['section'] ?? 'general'),
            default: $definition['default'] ?? null,
            description: isset($definition['description']) ? (string) $definition['description'] : null,
            helpUrl: isset($definition['help']) ? (string) $definition['help'] : null,
            options: self::normalizeOptions($options),
            optionsSource: $source,
            rules: self::normalizeRules($rules),
            autoload: (bool) ($definition['autoload'] ?? true),
            encrypted: (bool) ($definition['encrypted'] ?? in_array((string) ($definition['type'] ?? ''), self::SENSITIVE_TYPES, true)),
            public: (bool) ($definition['public'] ?? false),
            priority: (int) ($definition['priority'] ?? 10),
            placeholder: isset($definition['placeholder']) ? (string) $definition['placeholder'] : null,
            attributes: self::stringKeyed($definition['attributes'] ?? null),
            conditions: self::stringKeyed($definition['conditions'] ?? null),
            ui: self::stringKeyed($definition['ui'] ?? null),
        );
    }

    public function isSensitive(): bool
    {
        return $this->encrypted || in_array($this->type, self::SENSITIVE_TYPES, true);
    }

    /**
     * Whether the field may be filled from an incoming form submission.
     */
    public function isEditable(): bool
    {
        return $this->type !== 'hidden';
    }

    /**
     * Default HTML input attributes derived from the definition (type, required, min, max, step…).
     *
     * @return array<string, string|bool|int|float>
     */
    public function htmlAttributes(): array
    {
        $attributes = [];

        foreach ($this->rules as $rule => $value) {
            if ($rule === 'required') {
                $attributes['required'] = true;
                continue;
            }

            if ($rule === 'step') {
                if (is_numeric($value)) {
                    $attributes['step'] = (string) $value;
                }
                continue;
            }

            if (in_array($rule, ['min', 'max', 'maxlength'], true) && is_numeric($value)) {
                $attributes[$rule] = (int) $value;
            }
        }

        foreach ($this->attributes as $key => $value) {
            // The attributes bag is keyed by HTML attribute names; only scalars can be rendered.
            if (is_scalar($value)) {
                $attributes[$key] = $value;
            }
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'type' => $this->type,
            'section' => $this->section,
            'default' => $this->default,
            'description' => $this->description,
            'options' => $this->options,
            'source' => $this->optionsSource,
            'rules' => $this->rules,
            'autoload' => $this->autoload,
            'encrypted' => $this->encrypted,
            'public' => $this->public,
            'priority' => $this->priority,
            'placeholder' => $this->placeholder,
            'attributes' => $this->attributes,
            'conditions' => $this->conditions,
            'ui' => $this->ui,
        ];
    }

    /**
     * Accept both the `['required', 'max:120']` list shape and the `['required' => '', 'max' => 120]`
     * map, plus the `required|max:120` string form a plugin may hand over instead. A list keeps its
     * integer keys — `SettingsFormProcessor::rulesFor()` is what turns them into a rule map.
     *
     * @param mixed $rules
     *
     * @return array<array-key, mixed>
     */
    private static function normalizeRules(mixed $rules): array
    {
        if (is_string($rules)) {
            return self::parseRules($rules);
        }

        if (!is_array($rules)) {
            return [];
        }

        $normalized = [];

        foreach ($rules as $key => $value) {
            if (is_int($key)) {
                if (!is_string($value)) {
                    continue;
                }

                [$rule, $param] = self::parseRule($value);
                $normalized[$rule] = $param;
                continue;
            }

            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function parseRule(string $rule): array
    {
        $colon = strpos($rule, ':');
        if ($colon === false) {
            return [$rule, ''];
        }

        return [substr($rule, 0, $colon), substr($rule, $colon + 1)];
    }

    /**
     * @return array<string, string>
     */
    private static function parseRules(string $rules): array
    {
        $parsed = [];

        foreach (array_filter(array_map('trim', explode('|', $rules))) as $rule) {
            $colon = strpos($rule, ':');
            if ($colon === false) {
                $parsed[$rule] = '';
                continue;
            }

            $parsed[substr($rule, 0, $colon)] = substr($rule, $colon + 1);
        }

        return $parsed;
    }

    /**
     * Re-key an arbitrary map so the declared `array<string, mixed>` shapes stay accurate.
     *
     * @return array<string, mixed>
     */
    private static function stringKeyed(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    /**
     * Choice options may be a map (`'fr' => 'Français'`) or a plain list of values.
     *
     * @return array<int|string, string>
     */
    private static function normalizeOptions(mixed $options): array
    {
        if (!is_array($options)) {
            return [];
        }

        $normalized = [];
        foreach ($options as $key => $value) {
            if (is_array($value)) {
                $label = $value['label'] ?? (string) $key;
                $normalized[(string) $key] = (string) $label;
                continue;
            }

            if (is_string($value) && !is_string($key)) {
                $normalized[$value] = $value;
                continue;
            }

            $normalized[(string) $key] = (string) $value;
        }

        return $normalized;
    }
}
