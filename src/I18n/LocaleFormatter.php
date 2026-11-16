<?php

declare(strict_types=1);

namespace Prime\I18n;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use IntlDateFormatter;
use NumberFormatter;
use Throwable;

/**
 * Locale-aware formatting of dates, times, numbers, currencies and relative distances.
 *
 * Uses the `intl` extension whenever it is available and degrades to translated PHP formatting
 * (month/weekday names and separators come from `resources/lang/{locale}/core.php`) otherwise, so
 * that a missing extension never breaks the interface.
 */
final class LocaleFormatter
{
    /**
     * `IntlDateFormatter` style/date type combinations for each supported preset.
     *
     * Values mirror the extension constants (NONE = 0, FULL = 1, LONG = 2, MEDIUM = 3, SHORT = 4)
     * so that the class can be loaded on runtimes compiled without `intl`.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private const INTL_PRESETS = [
        'short' => [4, 0],
        'medium' => [3, 0],
        'long' => [2, 0],
        'full' => [1, 0],
        'time' => [0, 4],
        'timeLong' => [0, 3],
        'dateTime' => [3, 4],
        'dateTimeLong' => [2, 4],
    ];

    /**
     * Fallback PHP-style patterns, overridable per locale through `core.date.formats.*`.
     *
     * @var array<string, string>
     */
    private const FALLBACK_PATTERNS = [
        'short' => 'd/m/Y',
        'medium' => 'j F Y',
        'long' => 'l j F Y',
        'full' => 'l j F Y',
        'time' => 'H\h i',
        'timeLong' => 'G:i',
        'dateTime' => 'j F Y \à H\h i',
        'dateTimeLong' => 'l j F Y \à H\h i',
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private array $nameCache = [];

    public function __construct(
        private readonly Translator $translator,
        private string $timezone = 'UTC',
    ) {
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): void
    {
        try {
            new DateTimeZone($timezone);
        } catch (Throwable) {
            return;
        }

        $this->timezone = $timezone;
    }

    /**
     * Format a date using a locale-aware preset.
     */
    public function date(DateTimeInterface|int|string $date, string $preset = 'medium', ?string $locale = null): string
    {
        return $this->format($date, $preset, $locale);
    }

    public function time(DateTimeInterface|int|string $date, string $preset = 'time', ?string $locale = null): string
    {
        return $this->format($date, $preset, $locale);
    }

    public function dateTime(DateTimeInterface|int|string $date, string $preset = 'dateTime', ?string $locale = null): string
    {
        return $this->format($date, $preset, $locale);
    }

    /**
     * Human readable distance to "now" ("il y a 3 heures", "dans 2 jours").
     */
    public function relative(DateTimeInterface|int|string $date, DateTimeInterface|int|string|null $reference = null, ?string $locale = null): string
    {
        $moment = $this->timestamp($date);
        if ($moment === null) {
            return '';
        }

        $now = $reference === null ? time() : $this->timestamp($reference);
        if ($now === null) {
            $now = time();
        }

        $diff = $moment - $now;
        $absolute = abs($diff);

        if ($absolute < 60) {
            return $this->translator->translate('core.time.just_now', [], $locale);
        }

        $units = [
            'year' => 31536000,
            'month' => 2592000,
            'week' => 604800,
            'day' => 86400,
            'hour' => 3600,
            'minute' => 60,
        ];

        $future = $diff > 0;
        foreach ($units as $unit => $seconds) {
            if ($absolute < $seconds) {
                continue;
            }

            $count = (int) round($absolute / $seconds);
            $key = $future ? 'core.time.in_' . $unit : 'core.time.' . $unit . '_ago';
            if (!$this->translator->has($key)) {
                $key = $future ? 'core.time.in_day' : 'core.time.day_ago';
            }

            return $this->translator->choice($key, $count, [':count' => $count], $locale);
        }

        $minutes = (int) round($absolute / 60);

        return $this->translator->choice('core.time.minute_ago', $minutes, [':count' => $minutes], $locale);
    }

    /**
     * Format a number with locale separators and a fixed number of decimals.
     */
    public function number(int|float $value, ?int $decimals = null, ?string $locale = null): string
    {
        $locale ??= $this->translator->locale();

        if (extension_loaded('intl') && class_exists(NumberFormatter::class)) {
            try {
                $formatter = new NumberFormatter($this->translator->localeTag($locale), NumberFormatter::DECIMAL);
                if ($decimals !== null) {
                    $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, max(0, $decimals));
                    $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, max(0, $decimals));
                }

                $formatted = $formatter->format($value);
                if (is_string($formatted) && $formatted !== '') {
                    return $formatted;
                }
            } catch (Throwable) {
                // Fall through to the manual formatter below.
            }
        }

        $decimals = max(0, $decimals ?? (is_int($value) ? 0 : 2));

        return number_format(
            (float) $value,
            $decimals,
            $this->symbol('core.locale.decimal_separator', ','),
            $this->symbol('core.locale.thousands_separator', ' '),
        );
    }

    /**
     * Format a monetary amount, defaulting to the locale currency.
     */
    public function currency(int|float $value, ?string $currency = null, ?string $locale = null): string
    {
        $locale ??= $this->translator->locale();
        $code = strtoupper(trim((string) ($currency ?? $this->translator->translate('core.locale.currency', [], $locale))));
        if ($code === $this->translator->normalize($code) && strlen($code) !== 3) {
            $code = 'EUR';
        }

        if (extension_loaded('intl') && class_exists(NumberFormatter::class)) {
            try {
                $formatter = new NumberFormatter($this->translator->localeTag($locale), NumberFormatter::CURRENCY);
                $formatted = $formatter->formatCurrency((float) $value, $code);
                if (is_string($formatted) && $formatted !== '') {
                    return $formatted;
                }
            } catch (Throwable) {
                // Fall through to the manual formatter below.
            }
        }

        $symbol = match ($code) {
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            'CHF' => 'CHF',
            'CAD' => 'CA$',
            default => $code,
        };

        return $this->number($value, 2, $locale) . "\u{202f}" . $symbol;
    }

    /**
     * Format a ratio as a percentage value (`0.154` → `15 %`).
     */
    public function percent(int|float $value, int $decimals = 0, ?string $locale = null): string
    {
        $locale ??= $this->translator->locale();

        if (extension_loaded('intl') && class_exists(NumberFormatter::class)) {
            try {
                $formatter = new NumberFormatter($this->translator->localeTag($locale), NumberFormatter::PERCENT);
                $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, max(0, $decimals));
                $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, max(0, $decimals));

                $formatted = $formatter->format($value);
                if (is_string($formatted) && $formatted !== '') {
                    return $formatted;
                }
            } catch (Throwable) {
                // Fall through to the manual formatter below.
            }
        }

        return $this->number($value * 100, $decimals, $locale) . "\u{202f}%";
    }

    /**
     * Format a byte count for system health screens (locale independent, always metric units).
     */
    public function bytes(int $bytes, ?string $locale = null): string
    {
        $absolute = abs($bytes);
        $units = $this->byteUnits($locale);
        $index = 0;
        $value = (float) $absolute;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        $formatted = $index === 0
            ? (string) (int) $value
            : number_format($value, $value < 10 ? 1 : 0, $this->symbol('core.locale.decimal_separator', ','), '');

        return ($bytes < 0 ? '-' : '') . $formatted . ' ' . $units[$index];
    }

    /**
     * Display name of a locale in the current interface language.
     */
    public function localeName(string $locale, ?string $inLocale = null): string
    {
        $normalized = $this->translator->normalize($locale);
        if ($normalized === '') {
            return '';
        }

        $inLocale ??= $this->translator->locale();
        $key = 'core.locales.' . $normalized;
        $translated = $this->translator->translate($key, [], $inLocale);
        if ($translated !== $key) {
            return $translated;
        }

        if (extension_loaded('intl') && class_exists(\Locale::class)) {
            $name = \Locale::getDisplayName($this->translator->localeTag($locale), $this->translator->localeTag($inLocale));
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return strtoupper($normalized);
    }

    /**
     * Text direction of a locale (`rtl` for right-to-left languages).
     */
    public function direction(?string $locale = null): string
    {
        $locale ??= $this->translator->locale();
        $normalized = $this->translator->normalize($locale);

        $tree = $this->translator->tree($normalized);
        $fromLang = $tree['locale']['direction'] ?? null;
        if ($fromLang === 'rtl' || $fromLang === 'ltr') {
            return (string) $fromLang;
        }

        return in_array($normalized, ['ar', 'arc', 'dv', 'fa', 'ha', 'he', 'kwh', 'ks', 'ku', 'ml', 'ps', 'sd', 'ug', 'ur', 'yi'], true)
            ? 'rtl'
            : 'ltr';
    }

    public function isRtl(?string $locale = null): bool
    {
        return $this->direction($locale) === 'rtl';
    }

    /**
     * Index of the first day of the week (0 = Sunday, 1 = Monday).
     */
    public function firstDayOfWeek(?string $locale = null): int
    {
        $locale ??= $this->translator->locale();
        $value = $this->localeValue('first_day_of_week', $locale);

        if ($value === null) {
            return $this->translator->normalize($locale) === 'en' ? 0 : 1;
        }

        $index = (int) $value;

        return $index >= 0 && $index <= 6 ? $index : 1;
    }

    /**
     * Full month names, indexed from 1 (January) to 12.
     *
     * @return array<int, string>
     */
    public function monthNames(string $context = 'wide', ?string $locale = null): array
    {
        return $this->names('months', $context, $locale, [
            'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December',
        ]);
    }

    /**
     * Weekday names indexed from 0 (Sunday) to 6 (Saturday).
     *
     * @return array<int, string>
     */
    public function weekdayNames(string $context = 'wide', ?string $locale = null): array
    {
        return $this->names('weekdays', $context, $locale, [
            'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday',
        ]);
    }

    /**
     * Locale data consumed by the admin JavaScript components (`window.PrimeLocale`).
     *
     * @return array<string, mixed>
     */
    public function toArray(?string $locale = null): array
    {
        $locale ??= $this->translator->locale();

        return [
            'locale' => $locale,
            'tag' => $this->translator->localeTag($locale),
            'direction' => $this->direction($locale),
            'firstDayOfWeek' => $this->firstDayOfWeek($locale),
            'decimalSeparator' => $this->symbol('core.locale.decimal_separator', ',', $locale),
            'thousandsSeparator' => $this->symbol('core.locale.thousands_separator', ' ', $locale),
            'currency' => strtoupper($this->translator->translate('core.locale.currency', [], $locale)),
            'months' => array_values($this->monthNames('wide', $locale)),
            'monthsShort' => array_values($this->monthNames('abbreviated', $locale)),
            'weekdays' => array_values($this->weekdayNames('wide', $locale)),
            'weekdaysShort' => array_values($this->weekdayNames('abbreviated', $locale)),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function names(string $type, string $context, ?string $locale, array $englishDefaults): array
    {
        $locale ??= $this->translator->locale();
        $cacheKey = $type . '|' . $context . '|' . $locale;
        if (isset($this->nameCache[$cacheKey])) {
            return $this->nameCache[$cacheKey];
        }

        $fromLang = $this->translator->translate('core.date.' . $type . '.' . $context, [], $locale);
        $result = [];
        if ($fromLang !== 'core.date.' . $type . '.' . $context) {
            $decoded = json_decode($fromLang, true);
            if (is_array($decoded)) {
                foreach ($decoded as $index => $name) {
                    if (is_string($name)) {
                        $result[(int) $index] = $name;
                    }
                }
            }
        }

        if ($result === [] && extension_loaded('intl') && class_exists(IntlDateFormatter::class)) {
            $result = $this->namesFromIntl($type, $context, $locale) ?? [];
        }

        if ($result === []) {
            foreach ($englishDefaults as $index => $name) {
                $result[$index + ($type === 'months' ? 1 : 0)] = $name;
            }
        }

        $this->nameCache[$cacheKey] = $result;

        return $result;
    }

    /**
     * @return array<int, string>|null
     */
    private function namesFromIntl(string $type, string $context, string $locale): ?array
    {
        $pattern = $type === 'months'
            ? match ($context) {
                'abbreviated' => 'MMM',
                'narrow' => 'MMMMM',
                default => 'MMMM',
            }
            : match ($context) {
                'abbreviated' => 'EEE',
                'narrow' => 'EEEEE',
                default => 'EEEE',
            };

        $count = $type === 'months' ? 12 : 7;
        $base = $type === 'months' ? 1 : 0;
        $result = [];

        try {
            $formatter = new IntlDateFormatter(
                $this->translator->localeTag($locale),
                IntlDateFormatter::FULL,
                IntlDateFormatter::NONE,
                new DateTimeZone($this->timezone),
                IntlDateFormatter::GREGORIAN,
                $pattern,
            );

            for ($i = 0; $i < $count; $i++) {
                $date = $type === 'months'
                    ? new DateTimeImmutable(sprintf('2024-%02d-15 12:00:00', $i + 1))
                    : (new DateTimeImmutable('2024-01-07 12:00:00'))->modify('+' . $i . ' days');

                $name = $formatter->format($date);
                if (is_string($name) && $name !== '') {
                    $result[$i + $base] = $name;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return count($result) === $count ? $result : null;
    }

    private function format(DateTimeInterface|int|string $date, string $preset, ?string $locale): string
    {
        $timestamp = $this->timestamp($date);
        if ($timestamp === null) {
            return '';
        }

        $moment = (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone($this->timezone));
        $locale ??= $this->translator->locale();

        if (extension_loaded('intl') && class_exists(IntlDateFormatter::class) && isset(self::INTL_PRESETS[$preset])) {
            [$dateType, $timeType] = self::INTL_PRESETS[$preset];

            try {
                $formatter = new IntlDateFormatter(
                    $this->translator->localeTag($locale),
                    $dateType,
                    $timeType,
                    new DateTimeZone($this->timezone),
                    IntlDateFormatter::GREGORIAN,
                    $this->patternOverride($preset, $locale),
                );

                $formatted = $formatter->format($moment);
                if (is_string($formatted) && $formatted !== '') {
                    return $formatted;
                }
            } catch (Throwable) {
                // Fall back to the PHP renderer below.
            }
        }

        return $this->renderPattern($moment, $preset, $locale);
    }

    /**
     * Allow a locale to force an explicit ICU pattern through `core.date.patterns.{preset}`.
     */
    private function patternOverride(string $preset, string $locale): ?string
    {
        $key = 'core.date.patterns.' . $preset;
        $value = $this->translator->translate($key, [], $locale);

        return $value === $key ? null : $value;
    }

    /**
     * Render a PHP-style date pattern using translated month and weekday names.
     */
    private function renderPattern(DateTimeImmutable $moment, string $preset, string $locale): string
    {
        $key = 'core.date.formats.' . $preset;
        $pattern = $this->translator->translate($key, [], $locale);
        if ($pattern === $key) {
            $pattern = self::FALLBACK_PATTERNS[$preset] ?? self::FALLBACK_PATTERNS['medium'];
        }

        $months = $this->monthNames('wide', $locale);
        $shortMonths = $this->monthNames('abbreviated', $locale);
        $weekdays = $this->weekdayNames('wide', $locale);
        $shortWeekdays = $this->weekdayNames('abbreviated', $locale);

        $monthIndex = (int) $moment->format('n');
        $dayIndex = (int) $moment->format('w');

        $replacements = [
            'l' => $weekdays[$dayIndex] ?? $moment->format('l'),
            'D' => $shortWeekdays[$dayIndex] ?? $moment->format('D'),
            'F' => $months[$monthIndex] ?? $moment->format('F'),
            'M' => $shortMonths[$monthIndex] ?? $moment->format('M'),
        ];

        $result = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];

            if ($char === '\\') {
                $next = $pattern[$i + 1] ?? '';
                $result .= $next;
                $i++;
                continue;
            }

            if (isset($replacements[$char])) {
                $result .= $replacements[$char];
                continue;
            }

            $result .= $moment->format($char) !== '' ? $moment->format($char) : $char;
        }

        return str_replace("\0", '', $result);
    }

    private function symbol(string $key, string $default, ?string $locale = null): string
    {
        $value = $this->localeValue($this->localeKey($key), $locale);

        return $value === null || $value === '' ? $default : $value;
    }

    /**
     * Reduce a `core.locale.*` key to its leaf name.
     */
    private function localeKey(string $key): string
    {
        $prefix = 'core.locale.';

        return str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key;
    }

    /**
     * Read a raw `core.locale.*` value for one precise locale, without falling back to another
     * language (a French separator must never leak into an English date).
     */
    private function localeValue(string $key, ?string $locale): ?string
    {
        $tree = $this->translator->tree($locale ?? $this->translator->locale());
        $value = $tree['core']['locale'][$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function byteUnits(?string $locale): array
    {
        $value = $this->localeValue('byte_units', $locale);
        if ($value === null || trim($value) === '') {
            $fallback = $this->translator->normalize($locale ?? $this->translator->locale()) === 'en'
                ? 'B|KB|MB|GB|TB|PB'
                : 'o|Ko|Mo|Go|To|Po';

            return explode('|', $fallback);
        }

        $units = array_values(array_filter(explode('|', $value), static fn (string $unit): bool => $unit !== ''));

        return $units === [] ? ['o'] : $units;
    }

    private function timestamp(DateTimeInterface|int|string $date): ?int
    {
        if ($date instanceof DateTimeInterface) {
            return $date->getTimestamp();
        }

        if (is_int($date)) {
            return $date;
        }

        $trimmed = trim($date);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^\d{9,11}$/', $trimmed) === 1) {
            return (int) $trimmed;
        }

        try {
            return (new DateTime($trimmed, new DateTimeZone($this->timezone)))->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }
}
