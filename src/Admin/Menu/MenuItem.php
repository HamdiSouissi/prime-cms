<?php

declare(strict_types=1);

namespace Prime\Admin\Menu;

use Prime\Admin\Data\FilterValueHelper;
use Prime\Admin\Exception\InvalidMenuItemException;
use Prime\Content\LabelTranslator;

/**
 * Immutable definition of an admin sidebar menu entry.
 */
final readonly class MenuItem
{
    /**
     * Allowed vibrant accent identifiers matching `tokens.css`.
     */
    public const ALLOWED_ACCENTS = [
        'brand',
        'violet',
        'teal',
        'sky',
        'amber',
        'success',
        'neutral',
    ];

    public function __construct(
        public string $id,
        public string $label,
        public string $icon,
        public string $url,
        public int $position = 50,
        public string $accent = 'neutral',
        public ?string $badge = null,
        public ?string $group = null,
        public ?string $capability = null,
        public ?string $route = null,
    ) {
        if (trim($id) === '') {
            throw new InvalidMenuItemException('Menu item id must be a non-empty string.');
        }

        if (trim($label) === '') {
            throw new InvalidMenuItemException(sprintf('Menu item [%s] must have a non-empty label.', $id));
        }

        if (trim($icon) === '') {
            throw new InvalidMenuItemException(sprintf('Menu item [%s] must specify an icon name.', $id));
        }

        if (!in_array($accent, self::ALLOWED_ACCENTS, true)) {
            throw new InvalidMenuItemException(sprintf(
                'Menu item [%s] has invalid accent [%s]. Allowed: %s.',
                $id,
                $accent,
                implode(', ', self::ALLOWED_ACCENTS),
            ));
        }
    }

    /**
     * Resolve the menu label through the translator.
     *
     * Core items are declared with an `admin.menu.*` key so they follow the active locale;
     * a plugin that passes a literal (`label: 'Shop'`) keeps its own text untouched.
     */
    public function translatedLabel(): string
    {
        return self::translate($this->label);
    }

    /**
     * Resolve the section label (`Contenu`, `Système`, …) through the translator.
     */
    public function translatedGroup(): ?string
    {
        return $this->group === null ? null : self::translate($this->group);
    }

    /**
     * Resolve the badge through the translator (same key convention as the label).
     */
    public function translatedBadge(): ?string
    {
        return $this->badge === null ? null : self::translate($this->badge);
    }

    /**
     * A label is a translation key when it is a dotted identifier (`admin.menu.pages`,
     * `hello-prime::menu.title`); anything else is a literal kept as declared.
     */
    private static function translate(string $value): string
    {
        return LabelTranslator::resolve($value);
    }

    /**
     * Validate and build a MenuItem from a raw array payload (returns null if invalid).
     */
    public static function fromArray(mixed $raw): ?self
    {
        if ($raw instanceof self) {
            return FilterValueHelper::isSafeUrl($raw->url) ? $raw : null;
        }

        if (!is_array($raw)) {
            return null;
        }

        $id = FilterValueHelper::requiredString($raw, 'id');
        $label = FilterValueHelper::requiredString($raw, 'label');
        $icon = FilterValueHelper::requiredString($raw, 'icon');
        $url = FilterValueHelper::requiredString($raw, 'url');

        if (
            $id === null || $label === null || $icon === null || $url === null
            || !FilterValueHelper::isValidIcon($icon)
            || !FilterValueHelper::isSafeUrl($url)
        ) {
            return null;
        }

        $position = 50;
        if (array_key_exists('position', $raw) && $raw['position'] !== null) {
            if (!is_int($raw['position'])) {
                return null;
            }
            $position = $raw['position'];
        }

        $accent = FilterValueHelper::optionalString($raw, 'accent', 'neutral');
        $badge = FilterValueHelper::optionalString($raw, 'badge', '');
        $group = FilterValueHelper::optionalString($raw, 'group', '');
        $capability = FilterValueHelper::optionalString($raw, 'capability', '');
        $route = FilterValueHelper::optionalString($raw, 'route', '');

        if ($accent === null || $badge === null || $group === null || $capability === null || $route === null) {
            return null;
        }

        if ($accent === '') {
            $accent = 'neutral';
        }
        if (!FilterValueHelper::isValidAccent($accent)) {
            return null;
        }

        return new self(
            id: $id,
            label: $label,
            icon: $icon,
            url: $url,
            position: $position,
            accent: $accent,
            badge: $badge !== '' ? $badge : null,
            group: $group !== '' ? $group : null,
            capability: $capability !== '' ? $capability : null,
            route: $route !== '' ? $route : null,
        );
    }

    /**
     * Return a copy of this item with a resolved URL.
     */
    public function withUrl(string $url): self
    {
        return new self(
            id: $this->id,
            label: $this->label,
            icon: $this->icon,
            url: $url,
            position: $this->position,
            accent: $this->accent,
            badge: $this->badge,
            group: $this->group,
            capability: $this->capability,
            route: $this->route,
        );
    }
}
