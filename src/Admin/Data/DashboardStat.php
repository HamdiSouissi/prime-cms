<?php

declare(strict_types=1);

namespace Prime\Admin\Data;

/**
 * Immutable normalized value object for dashboard KPI stat tiles (`admin.dashboard.stats`).
 */
final readonly class DashboardStat
{
    public const ALLOWED_DIRECTIONS = ['up', 'down', 'flat'];

    /**
     * @param 'up'|'down'|'flat' $trendDirection
     */
    public function __construct(
        public string $label,
        public string $value,
        public string $icon,
        public string $accent,
        public string $trend,
        public string $trendDirection,
        public string $hint,
        public string $url,
    ) {
    }

    /**
     * Validate and normalize a raw KPI stat tile returned by a filter.
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

        $label = FilterValueHelper::requiredString($raw, 'label');
        if ($label === null || !array_key_exists('value', $raw)) {
            return null;
        }

        $rawValue = $raw['value'];
        if (is_string($rawValue)) {
            $value = trim($rawValue);
        } elseif (is_int($rawValue) || is_float($rawValue)) {
            $value = (string) $rawValue;
        } else {
            return null;
        }

        if ($value === '') {
            return null;
        }

        $icon = FilterValueHelper::optionalString($raw, 'icon', 'activity');
        $accent = FilterValueHelper::optionalString($raw, 'accent', 'brand');
        $trend = FilterValueHelper::optionalString($raw, 'trend', '');
        $trendDirection = FilterValueHelper::optionalString($raw, 'trendDirection', 'flat');
        $hint = FilterValueHelper::optionalString($raw, 'hint', '');
        $url = FilterValueHelper::optionalString($raw, 'url', '');

        if (
            $icon === null || $accent === null || $trend === null
            || $trendDirection === null || $hint === null || $url === null
        ) {
            return null;
        }

        if ($icon === '') {
            $icon = 'activity';
        }
        if ($accent === '') {
            $accent = 'brand';
        }
        if ($trendDirection === '') {
            $trendDirection = 'flat';
        }

        if (
            !FilterValueHelper::isValidIcon($icon)
            || !FilterValueHelper::isValidAccent($accent)
            || !in_array($trendDirection, self::ALLOWED_DIRECTIONS, true)
        ) {
            return null;
        }

        if ($url !== '' && !FilterValueHelper::isSafeUrl($url)) {
            return null;
        }

        /** @var 'up'|'down'|'flat' $validatedDirection */
        $validatedDirection = $trendDirection;

        return new self(
            label: $label,
            value: $value,
            icon: $icon,
            accent: $accent,
            trend: $trend,
            trendDirection: $validatedDirection,
            hint: $hint,
            url: $url,
        );
    }

    /**
     * @return array{label: string, value: string, icon: string, accent: string, trend: string, trendDirection: 'up'|'down'|'flat', hint: string, url: string}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'value' => $this->value,
            'icon' => $this->icon,
            'accent' => $this->accent,
            'trend' => $this->trend,
            'trendDirection' => $this->trendDirection,
            'hint' => $this->hint,
            'url' => $this->url,
        ];
    }
}
