<?php

declare(strict_types=1);

namespace Prime\Admin\Data;

/**
 * Immutable normalized value object for topbar "Créer" dropdown items (`admin.create.items`).
 */
final readonly class CreateMenuItem
{
    public function __construct(
        public string $id,
        public string $label,
        public string $description,
        public string $icon,
        public string $accent,
        public string $url,
        public ?string $capability = null,
    ) {
    }

    /**
     * Validate and normalize a raw quick-create menu entry returned by a filter.
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

        $description = FilterValueHelper::optionalString($raw, 'description', '');
        $icon = FilterValueHelper::optionalString($raw, 'icon', 'plus');
        $accent = FilterValueHelper::optionalString($raw, 'accent', 'brand');
        $capability = FilterValueHelper::optionalString($raw, 'capability', '');

        if ($description === null || $icon === null || $accent === null || $capability === null) {
            return null;
        }

        if ($icon === '') {
            $icon = 'plus';
        }
        if ($accent === '') {
            $accent = 'brand';
        }

        if (!FilterValueHelper::isValidIcon($icon) || !FilterValueHelper::isValidAccent($accent)) {
            return null;
        }

        return new self(
            id: $id,
            label: $label,
            description: $description,
            icon: $icon,
            accent: $accent,
            url: $url,
            capability: $capability !== '' ? $capability : null,
        );
    }

    /**
     * @return array{id: string, label: string, description: string, icon: string, accent: string, url: string, capability?: string}
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'label' => $this->label,
            'description' => $this->description,
            'icon' => $this->icon,
            'accent' => $this->accent,
            'url' => $this->url,
        ];

        if ($this->capability !== null) {
            $data['capability'] = $this->capability;
        }

        return $data;
    }
}
