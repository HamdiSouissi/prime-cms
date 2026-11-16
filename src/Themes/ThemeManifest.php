<?php

declare(strict_types=1);

namespace Prime\Themes;

/**
 * Immutable validated representation of a theme's `theme.json` manifest.
 */
final readonly class ThemeManifest
{
    /**
     * @param array{prime: ?string, php: ?string} $requires
     * @param array<string, string> $menus Location slug => label
     * @param array<string, array{label: string, description: string}> $zones Zone slug => metadata
     * @param array<string, array<string, string>> $templates Content type => [template slug => label]
     * @param array<string, array{width: int, height: int, crop: bool}> $imageSizes
     * @param array{
     *     sections: array<string, array{label: string, description: string}>,
     *     fields: array<string, array<string, mixed>>
     * } $options
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $version,
        public string $description,
        public string $author,
        public ?string $authorUrl,
        public array $requires,
        public ?string $parent,
        public string $namespace,
        public string $main,
        public array $menus,
        public array $zones,
        public array $templates,
        public array $imageSizes,
        public array $options,
    ) {
    }

    /**
     * Return templates declared for a specific content type (`template_slug => label`).
     *
     * @return array<string, string>
     */
    public function templatesFor(string $contentType): array
    {
        return $this->templates[$contentType] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'version' => $this->version,
            'description' => $this->description,
            'author' => $this->author,
            'author_url' => $this->authorUrl,
            'requires' => $this->requires,
            'parent' => $this->parent,
            'namespace' => $this->namespace,
            'main' => $this->main,
            'menus' => $this->menus,
            'zones' => $this->zones,
            'templates' => $this->templates,
            'image_sizes' => $this->imageSizes,
            'options' => $this->options,
        ];
    }
}
