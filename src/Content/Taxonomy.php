<?php

declare(strict_types=1);

namespace Prime\Content;

use InvalidArgumentException;

/**
 * Immutable code-declared taxonomy definition (core `category`/`tag` or plugin taxonomy).
 */
final readonly class Taxonomy
{
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{1,39}$/';

    /**
     * @var array<string, string> Raw labels (translation keys or literals); read them through label().
     */
    public array $labels;

    /**
     * @var list<string>
     */
    public array $contentTypes;

    /**
     * @param array<array-key, mixed> $labels
     * @param list<string> $contentTypes
     */
    public function __construct(
        public string $name,
        array $labels,
        public bool $hierarchical = false,
        array $contentTypes = [],
        public bool $public = true,
        public string $capability = 'content.edit_any',
        public ?string $defaultTermSlug = null,
        public string $icon = 'tag',
        public string $source = 'core',
    ) {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid taxonomy name [%s]. Must match %s.',
                $name,
                self::NAME_PATTERN,
            ));
        }

        $clean = [];
        foreach ($labels as $labelKey => $value) {
            if (!is_string($labelKey) || !is_string($value)) {
                continue;
            }
            $value = trim($value);
            if ($value !== '') {
                $clean[$labelKey] = $value;
            }
        }
        $clean['singular'] ??= ucfirst($name);
        $this->labels = $clean;

        $types = [];
        foreach ($contentTypes as $ct) {
            $clean = trim($ct);
            if ($clean !== '' && !in_array($clean, $types, true)) {
                $types[] = $clean;
            }
        }
        $this->contentTypes = $types;
    }

    /**
     * Label in the active locale (translation key or literal, see {@see ContentType::label()}).
     */
    public function label(string $key): string
    {
        if (isset($this->labels[$key])) {
            return LabelTranslator::resolve($this->labels[$key]);
        }

        $singular = LabelTranslator::resolve($this->labels['singular']);
        $plural = isset($this->labels['plural']) ? LabelTranslator::resolve($this->labels['plural']) : $singular . 's';
        if ($key === 'plural') {
            return $plural;
        }

        $translated = t('core.taxonomies.defaults.' . $key, [
            'singular' => mb_strtolower($singular),
            'plural' => $plural,
        ]);

        return $translated === 'core.taxonomies.defaults.' . $key ? $singular : $translated;
    }

    public function appliesTo(string $contentType): bool
    {
        return in_array($contentType, $this->contentTypes, true);
    }

    public function adminUrl(): string
    {
        return '/admin/taxonomies/' . $this->name;
    }

    public function withSource(string $source): self
    {
        return new self(
            name: $this->name,
            labels: $this->labels,
            hierarchical: $this->hierarchical,
            contentTypes: $this->contentTypes,
            public: $this->public,
            capability: $this->capability,
            defaultTermSlug: $this->defaultTermSlug,
            icon: $this->icon,
            source: $source,
        );
    }
}
