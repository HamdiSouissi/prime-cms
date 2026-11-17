<?php

declare(strict_types=1);

namespace Prime\Settings;

use InvalidArgumentException;

/**
 * Declarative catalogue of every setting known to Prime CMS: sections, fields, defaults, and the
 * sanitize/validate pipeline shared by the admin screens, the CLI and the REST endpoints.
 *
 * Plugins and themes contribute through the `settings.register` filter, which hands them this same
 * registry — no core change is required to add a screen or a field.
 */
final class SettingsRegistry
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $sections = [];

    /**
     * @var array<string, SettingsField>
     */
    private array $fields = [];

    /**
     * @var array<string, list<string>> Fields contributed by each owner (for unloading).
     */
    private array $ownerIndex = [];

    /**
     * Raw declarations, kept so an override replaces only the keys it mentions.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $definitions = [];

    private bool $locked = false;

    private ?SettingsOptionSources $sources = null;

    /**
     * Register a section (a settings screen or a card inside a screen).
     *
     * @param array<string, mixed> $definition
     */
    public function section(string $id, array $definition = []): void
    {
        $this->assertMutable();

        if ($id === '' || !preg_match('/^[a-z0-9_\-]{1,64}$/', $id)) {
            throw new InvalidArgumentException(sprintf('Identifiant de section invalide : « %s ».', $id));
        }

        $existing = $this->sections[$id] ?? [];

        $this->sections[$id] = array_merge([
            'id' => $id,
            'label' => $definition['label'] ?? ucfirst(str_replace('_', ' ', $id)),
            'description' => '',
            'icon' => 'settings',
            'capability' => 'options.manage',
            'priority' => 10,
            'page' => 'general',
            'tab' => null,
        ], $existing, array_intersect_key($definition, array_flip([
            'label',
            'description',
            'icon',
            'capability',
            'priority',
            'page',
            'tab',
        ])));
    }

    /**
     * Declare a single setting.
     *
     * @param array<string, mixed> $definition
     */
    public function add(array $definition, ?string $owner = null): void
    {
        $this->assertMutable();

        $field = SettingsField::fromArray($definition);

        if (!isset($this->sections[$field->section])) {
            $this->section($field->section, ['label' => $field->section]);
        }

        $previous = $this->definitions[$field->name] ?? null;
        if ($previous !== null) {
            if ($field->type !== (string) ($previous['type'] ?? $field->type)) {
                throw new InvalidArgumentException(sprintf('Le réglage « %s » est déjà déclaré avec un autre type.', $field->name));
            }

            // Later declarations refine earlier ones (a plugin may relabel or re-default a core
            // field): only the keys the override actually mentions win.
            $definition = array_merge($previous, $definition);
            $field = SettingsField::fromArray($definition);
        }

        $this->definitions[$field->name] = $definition;
        $this->fields[$field->name] = $field;

        if ($owner !== null && $owner !== '') {
            $this->ownerIndex[$owner][] = $field->name;
        }
    }

    /**
     * Declare sections and fields at once (the `settings.register` contract used by extensions).
     *
     * @param array<int|string, array<string, mixed>> $sections
     * @param array<int|string, array<string, mixed>> $fields
     */
    public function register(array $sections, array $fields, ?string $owner = null): void
    {
        foreach ($sections as $id => $section) {
            if (is_int($id) && isset($section['id']) && is_string($section['id'])) {
                $this->section($section['id'], $section);
                continue;
            }

            if (is_string($id)) {
                $this->section($id, is_array($section) ? $section : []);
            }
        }

        foreach ($fields as $field) {
            if (is_array($field)) {
                $this->add($field, $owner);
            }
        }
    }

    /**
     * Forget every field contributed by an owner (plugin deactivation).
     */
    public function forgetOwner(string $owner): void
    {
        $this->assertMutable();

        foreach ($this->ownerIndex[$owner] ?? [] as $name) {
            unset($this->fields[$name], $this->definitions[$name]);
        }

        unset($this->ownerIndex[$owner]);
    }

    /**
     * Prevent extensions from mutating the catalogue after boot.
     */
    public function lock(): void
    {
        $this->locked = true;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function has(string $name): bool
    {
        return isset($this->fields[$name]);
    }

    public function field(string $name): ?SettingsField
    {
        return $this->fields[$name] ?? null;
    }

    /**
     * Fields of a section (or every field), ordered by priority then name.
     *
     * @return array<string, SettingsField>
     */
    public function fields(?string $section = null): array
    {
        $fields = $this->fields;

        if ($section !== null) {
            $fields = array_filter(
                $fields,
                static fn (SettingsField $field): bool => $field->section === $section,
            );
        }

        uasort($fields, static function (SettingsField $a, SettingsField $b): int {
            if ($a->priority === $b->priority) {
                return strcmp($a->name, $b->name);
            }

            return $a->priority <=> $b->priority;
        });

        return $fields;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function sections(?string $page = null): array
    {
        $sections = $this->sections;

        if ($page !== null) {
            $sections = array_filter(
                $sections,
                static fn (array $section): bool => ($section['page'] ?? 'general') === $page,
            );
        }

        uasort($sections, static function (array $a, array $b): int {
            $order = ((int) ($a['priority'] ?? 10)) <=> ((int) ($b['priority'] ?? 10));

            return $order !== 0 ? $order : strcmp((string) $a['id'], (string) $b['id']);
        });

        return $sections;
    }

    /**
     * Values of every declared setting, defaults applied, ready to be rendered.
     *
     * @return array<string, mixed>
     */
    public function values(Settings $settings, ?string $section = null): array
    {
        $values = [];

        foreach ($this->fields($section) as $name => $field) {
            if ($field->type === 'secret' || $field->encrypted) {
                $values[$name] = null;
                continue;
            }

            $stored = $settings->get($name, '__prime_unset__');
            $values[$name] = $stored === '__prime_unset__' ? $field->default : $stored;
        }

        return $values;
    }

    /**
     * Defaults of every declared setting (or of one section).
     *
     * @return array<string, mixed>
     */
    public function defaults(?string $section = null): array
    {
        $defaults = [];

        foreach ($this->fields($section) as $name => $field) {
            $defaults[$name] = $field->default;
        }

        return $defaults;
    }

    /**
     * Values that may be exposed to the browser (public site, JS boot payload).
     *
     * @return array<string, mixed>
     */
    public function publicValues(Settings $settings): array
    {
        $values = [];

        foreach ($this->fields as $name => $field) {
            if (!$field->public || $field->isSensitive()) {
                continue;
            }

            $values[$name] = $settings->get($name, $field->default);
        }

        return $values;
    }

    public function setSources(?SettingsOptionSources $sources): void
    {
        $this->sources = $sources;
    }

    public function sources(): ?SettingsOptionSources
    {
        return $this->sources;
    }

    /**
     * Resolve the options of a choice field: static map, overlaid by the lazy source if declared.
     *
     * @return array<int|string, string>
     */
    public function options(SettingsField $field): array
    {
        if ($field->optionsSource === null) {
            return $field->options;
        }

        $resolved = $this->sources?->resolve($field->optionsSource) ?? [];

        return $field->options === [] ? $resolved : array_merge($field->options, $resolved);
    }

    private function assertMutable(): void
    {
        if ($this->locked) {
            throw new InvalidArgumentException('Le registre des réglages est verrouillé après le boot des extensions.');
        }
    }
}
