<?php

declare(strict_types=1);

namespace Prime\Settings;

use Prime\Content\LabelTranslator;

/**
 * Display text of registry settings in the active locale.
 *
 * Core settings are declared with French literals in `config/settings.php` (readable, and what the
 * CLI prints); their translations live under `core.settings.*`. A plugin declares either a
 * translation key (`plugin::shop.settings.currency`) or a literal — both go through
 * {@see LabelTranslator}, so nothing has to be registered twice.
 */
final class SettingsText
{
    public static function label(SettingsField $field): string
    {
        return self::lookup('core.settings.fields.' . $field->name . '.label', $field->label);
    }

    public static function description(SettingsField $field): ?string
    {
        if ($field->description === null || trim($field->description) === '') {
            return null;
        }

        return self::lookup('core.settings.fields.' . $field->name . '.description', $field->description);
    }

    public static function option(SettingsField $field, int|string $key, string $fallback): string
    {
        return self::lookup('core.settings.fields.' . $field->name . '.options.' . self::optionKey($key), $fallback);
    }

    /**
     * @param array<string, mixed> $section
     */
    public static function sectionLabel(array $section): string
    {
        return self::lookup('core.settings.sections.' . (string) ($section['id'] ?? '') . '.label', (string) ($section['label'] ?? ''));
    }

    /**
     * @param array<string, mixed> $section
     */
    public static function sectionDescription(array $section): string
    {
        return self::lookup('core.settings.sections.' . (string) ($section['id'] ?? '') . '.description', (string) ($section['description'] ?? ''));
    }

    /**
     * Catalogue-safe form of an option value: `''` → `none`, `/blog/%postname%` → `_blog__postname_`.
     */
    public static function optionKey(int|string $key): string
    {
        $key = (string) $key;

        return $key === '' ? 'none' : (string) preg_replace('/[^a-z0-9_-]/', '_', strtolower($key));
    }

    private static function lookup(string $key, string $fallback): string
    {
        $translated = t($key);

        return $translated !== $key ? $translated : LabelTranslator::resolve($fallback);
    }
}
