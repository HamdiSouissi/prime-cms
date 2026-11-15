<?php

declare(strict_types=1);

namespace Prime\Admin\Data;

/**
 * Immutable normalized value object for command palette entries (`admin.command_palette`).
 */
final readonly class CommandPaletteItem
{
    /**
     * @param list<string> $keywords
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $subtitle,
        public string $group,
        public string $section,
        public string $icon,
        public string $accent,
        public string $url,
        public array $keywords = [],
    ) {
    }

    /**
     * Validate and normalize a raw command palette item returned by a filter.
     */
    public static function fromArray(mixed $raw): ?self
    {
        if ($raw instanceof self) {
            return $raw;
        }

        if (!is_array($raw)) {
            return null;
        }

        $id = FilterValueHelper::requiredString($raw, 'id');
        $label = FilterValueHelper::requiredString($raw, 'label');
        $url = FilterValueHelper::requiredString($raw, 'url');
        if ($id === null || $label === null || $url === null || !FilterValueHelper::isSafeUrl($url)) {
            return null;
        }

        $subtitle = FilterValueHelper::optionalString($raw, 'subtitle', '');
        $group = FilterValueHelper::optionalString($raw, 'group', 'Raccourcis');
        $section = FilterValueHelper::optionalString($raw, 'section', 'Navigation');
        $icon = FilterValueHelper::optionalString($raw, 'icon', 'arrow-right');
        $accent = FilterValueHelper::optionalString($raw, 'accent', 'brand');

        if ($subtitle === null || $group === null || $section === null || $icon === null || $accent === null) {
            return null;
        }

        if ($group === '') {
            $group = 'Raccourcis';
        }
        if ($section === '') {
            $section = 'Navigation';
        }
        if ($icon === '') {
            $icon = 'arrow-right';
        }
        if ($accent === '') {
            $accent = 'brand';
        }

        if (!FilterValueHelper::isValidIcon($icon) || !FilterValueHelper::isValidAccent($accent)) {
            return null;
        }

        $keywords = [];
        if (array_key_exists('keywords', $raw) && $raw['keywords'] !== null) {
            if (!is_array($raw['keywords'])) {
                return null;
            }
            foreach ($raw['keywords'] as $kw) {
                if (!is_string($kw)) {
                    return null;
                }
                $trimmed = trim($kw);
                if ($trimmed !== '') {
                    $keywords[] = $trimmed;
                }
            }
        }

        return new self(
            id: $id,
            label: $label,
            subtitle: $subtitle,
            group: $group,
            section: $section,
            icon: $icon,
            accent: $accent,
            url: $url,
            keywords: $keywords,
        );
    }

    /**
     * @return array{id: string, label: string, subtitle: string, group: string, section: string, icon: string, accent: string, url: string, keywords: list<string>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'subtitle' => $this->subtitle,
            'group' => $this->group,
            'section' => $this->section,
            'icon' => $this->icon,
            'accent' => $this->accent,
            'url' => $this->url,
            'keywords' => $this->keywords,
        ];
    }
}
