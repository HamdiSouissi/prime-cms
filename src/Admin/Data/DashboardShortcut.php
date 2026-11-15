<?php

declare(strict_types=1);

namespace Prime\Admin\Data;

/**
 * Immutable normalized value object for dashboard shortcut cards (`admin.dashboard.shortcuts`).
 */
final readonly class DashboardShortcut
{
    public function __construct(
        public string $title,
        public string $description,
        public string $icon,
        public string $accent,
        public string $url,
    ) {
    }

    /**
     * Validate and normalize a raw shortcut entry returned by a filter.
     * Returns null when required keys are missing or any field has an invalid type/value.
     */
    public static function fromArray(mixed $raw): ?self
    {
        if ($raw instanceof self) {
            return $raw;
        }

        if (!is_array($raw)) {
            return null;
        }

        $title = FilterValueHelper::requiredString($raw, 'title');
        $url = FilterValueHelper::requiredString($raw, 'url');
        if ($title === null || $url === null || !FilterValueHelper::isSafeUrl($url)) {
            return null;
        }

        $description = FilterValueHelper::optionalString($raw, 'description', '');
        $icon = FilterValueHelper::optionalString($raw, 'icon', 'sparkles');
        $accent = FilterValueHelper::optionalString($raw, 'accent', 'brand');

        if ($description === null || $icon === null || $accent === null) {
            return null;
        }

        if ($icon === '') {
            $icon = 'sparkles';
        }
        if ($accent === '') {
            $accent = 'brand';
        }

        if (!FilterValueHelper::isValidIcon($icon) || !FilterValueHelper::isValidAccent($accent)) {
            return null;
        }

        return new self(
            title: $title,
            description: $description,
            icon: $icon,
            accent: $accent,
            url: $url,
        );
    }

    /**
     * @return array{title: string, description: string, icon: string, accent: string, url: string}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'icon' => $this->icon,
            'accent' => $this->accent,
            'url' => $this->url,
        ];
    }
}
