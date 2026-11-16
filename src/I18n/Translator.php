<?php

declare(strict_types=1);

namespace Prime\I18n;

use Prime\Cache\CacheInterface;
use Prime\Hooks\Hooks;
use Prime\Kernel\Application;
use Prime\Log\Logger;
use Throwable;

/**
 * Locale-aware translation engine backed by PHP language files.
 *
 * Keys use the `group.nested.path` format: the first segment is the file name inside
 * `resources/lang/{locale}/`, the remaining segments walk the returned array. Keys whose first
 * segment is not a language file (e.g. `dashboard.title`) are resolved against `core.php`.
 *
 * Language files are plain PHP arrays (never `unserialize()`), merged in path registration order so
 * that themes and plugins can extend or override core strings.
 */
final class Translator
{
    public const DEFAULT_LOCALE = 'fr';

    /**
     * Process-wide instance used by the global `t()`/`tc()` helpers outside a booted container
     * (CLI commands, standalone template rendering) and replaced by the bound service on boot.
     */
    private static ?Translator $fallbackInstance = null;

    public const FALLBACK_LOCALE = 'en';

    /**
     * Languages shipped with Prime CMS, used to validate site and user preferences.
     *
     * @var list<string>
     */
    public const SUPPORTED_LOCALES = ['fr', 'en'];

    /**
     * @var list<string>
     */
    private array $paths;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $groupCache = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $treeCache = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $registered = [];

    /**
     * @var array<string, list<string>>
     */
    private array $groupsCache = [];

    /**
     * @var list<string>
     */
    private array $missing = [];

    /**
     * Bind the container-resolved translator so global helpers share a single instance.
     */
    public static function setInstance(?Translator $translator): void
    {
        self::$fallbackInstance = $translator;
    }

    /**
     * Return the bound translator, or build a detached one reading `resources/lang` directly.
     */
    public static function fallback(): Translator
    {
        if (self::$fallbackInstance !== null) {
            return self::$fallbackInstance;
        }

        $root = dirname(__DIR__, 2);

        return self::$fallbackInstance = new self(
            locale: self::DEFAULT_LOCALE,
            fallbackLocale: self::FALLBACK_LOCALE,
            paths: [$root . '/resources/lang'],
        );
    }

    /**
     * @param list<string> $paths Directories containing `{locale}/{group}.php` language files
     * @param ?CacheInterface $cache Optional persistent cache for the merged language trees;
     *                               the key embeds a fingerprint of the catalogue files, so
     *                               editing one (or installing a theme/plugin) invalidates
     *                               the entry without any manual flush.
     */
    public function __construct(
        private string $locale = self::DEFAULT_LOCALE,
        private string $fallbackLocale = self::FALLBACK_LOCALE,
        array $paths = [],
        private readonly ?Hooks $hooks = null,
        private readonly ?CacheInterface $cache = null,
    ) {
        $this->paths = array_values(array_filter(
            array_map(static fn (string $path): string => rtrim($path, '/\\'), $paths),
            static fn (string $path): bool => $path !== '' && is_dir($path),
        ));
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function fallbackLocale(): string
    {
        return $this->fallbackLocale;
    }

    /**
     * Switch the active locale and emit the `locale.changed` hook.
     */
    public function setLocale(string $locale): void
    {
        $normalized = $this->normalize($locale);
        if ($normalized === '' || $normalized === $this->locale) {
            return;
        }

        $previous = $this->locale;
        $this->locale = $normalized;
        $this->hooks?->emit('locale.changed', $normalized, $previous);
    }

    public function setFallbackLocale(string $locale): void
    {
        $normalized = $this->normalize($locale);
        if ($normalized !== '') {
            $this->fallbackLocale = $normalized;
        }
    }

    /**
     * Register an additional language root (a plugin or theme `lang/` directory).
     */
    public function addPath(string $path): void
    {
        $clean = rtrim($path, '/\\');
        if ($clean === '' || !is_dir($clean) || in_array($clean, $this->paths, true)) {
            return;
        }

        $this->paths[] = $clean;
        $this->forgetCaches();
    }

    /**
     * Merge a translation tree at runtime (`translations.register` hook entry point).
     *
     * @param array<string, mixed> $messages
     */
    public function register(string $locale, array $messages): void
    {
        $normalized = $this->normalize($locale);
        if ($normalized === '' || $messages === []) {
            return;
        }

        $existing = $this->registered[$normalized] ?? [];
        $this->registered[$normalized] = $this->mergeDeep($existing, $messages);
        $this->forgetCaches();
    }

    /**
     * Translate a key, replacing `:placeholder` tokens and `%s` style modifiers.
     *
     * @param array<string|int, mixed> $replace
     */
    public function translate(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale = $this->normalize($locale ?? $this->locale);
        $line = $this->lookup($key, $locale);

        if ($line === null && $locale !== $this->fallbackLocale) {
            $line = $this->lookup($key, $this->fallbackLocale);
        }

        if ($line === null) {
            $this->noteMissing($key, $locale);

            return $key;
        }

        if (is_array($line)) {
            $line = $this->firstStringOf($key, $line);
        }

        return $this->applyReplacements($line, $replace, $locale);
    }

    /**
     * Translate a pluralized line using `|`-separated variants (`one|other`).
     *
     * @param array<string|int, mixed> $replace
     */
    public function choice(string $key, int|float $number, array $replace = [], ?string $locale = null): string
    {
        $locale = $this->normalize($locale ?? $this->locale);
        $line = $this->lookup($key, $locale) ?? $this->lookup($key, $this->fallbackLocale);

        if (!is_string($line)) {
            return $this->translate($key, $replace, $locale);
        }

        $segments = explode('|', $line);
        $template = trim($segments[min($this->pluralIndex($number), count($segments) - 1)]);

        $replace = array_merge([
            ':count' => $this->formatCount($number),
            ':number' => $this->formatCount($number),
        ], $replace);

        return $this->applyReplacements($template, $replace, $locale);
    }

    /**
     * Determine whether a key resolves in the given (or active) locale.
     */
    public function has(string $key, ?string $locale = null): bool
    {
        $locale = $this->normalize($locale ?? $this->locale);

        return $this->lookup($key, $locale) !== null
            || $this->lookup($key, $this->fallbackLocale) !== null;
    }

    /**
     * Return the translation tree of a locale flattened to `group.key => string` pairs.
     *
     * @return array<string, string>
     */
    public function messages(?string $locale = null): array
    {
        $flat = [];
        $this->collect($this->tree($locale), '', $flat);

        return $flat;
    }

    /**
     * Return the nested raw language tree of a locale (files + runtime registrations).
     *
     * @return array<string, mixed>
     */
    public function tree(?string $locale = null): array
    {
        $locale = $this->normalize($locale ?? $this->locale);
        if (isset($this->treeCache[$locale])) {
            return $this->treeCache[$locale];
        }

        $cached = $this->cache?->get($this->cacheKey($locale));
        if (is_array($cached)) {
            /** @var array<string, mixed> $cached */
            return $this->treeCache[$locale] = $cached;
        }

        $tree = [];
        foreach ($this->groupFiles($locale) as $group) {
            $tree[$group] = $this->loadGroup($locale, $group);
        }

        $registered = $this->registered[$locale] ?? [];
        if ($registered !== []) {
            $tree = $this->mergeDeep($tree, $registered);
        }

        $this->treeCache[$locale] = $tree;

        if ($this->cache !== null) {
            $this->cache->set($this->cacheKey($locale), $tree, 86400);
        }

        return $tree;
    }

    /**
     * Persistent cache key for a locale's merged tree, invalidated by any catalogue change.
     */
    private function cacheKey(string $locale): string
    {
        $fingerprint = [];

        foreach ($this->paths as $path) {
            foreach ($this->groupFiles($locale) as $group) {
                $file = $path . '/' . $locale . '/' . $group . '.php';
                if (is_file($file)) {
                    $fingerprint[] = $file . ':' . (string) (@filemtime($file) ?: 0);
                }
            }
        }

        return 'translations.' . $locale . '.' . sha1(implode('|', $fingerprint));
    }

    /**
     * List the language groups (files) available for a locale.
     *
     * @return list<string>
     */
    public function groups(?string $locale = null): array
    {
        return $this->groupFiles($this->normalize($locale ?? $this->locale));
    }

    /**
     * List the locales that ship at least one language file.
     *
     * @return list<string>
     */
    public function availableLocales(): array
    {
        $locales = [];
        foreach ($this->paths as $path) {
            $entries = @scandir($path) ?: [];
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || !is_dir($path . '/' . $entry)) {
                    continue;
                }
                if ($this->groupFiles($entry) !== []) {
                    $locales[$entry] = true;
                }
            }
        }

        foreach ($this->registered as $locale => $messages) {
            if ($messages !== []) {
                $locales[$locale] = true;
            }
        }

        $result = array_keys($locales);
        sort($result);

        return array_map(static fn (int|string $l): string => (string) $l, $result);
    }

    /**
     * Determine whether a locale can be offered in the interface.
     */
    public function isSupported(string $locale): bool
    {
        $normalized = $this->normalize($locale);
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, self::SUPPORTED_LOCALES, true)
            || in_array($normalized, $this->availableLocales(), true);
    }

    /**
     * Return the `locale:key` pairs that could not be resolved (diagnostics).
     *
     * @return list<string>
     */
    public function missingKeys(): array
    {
        return $this->missing;
    }

    public function resetMissingKeys(): void
    {
        $this->missing = [];
    }

    /**
     * Reduce a locale to its primary language subtag (`fr-FR`, `fr_FR`, `fr` → `fr`).
     */
    public function normalize(string $locale): string
    {
        $trimmed = strtolower(trim(str_replace('_', '-', $locale)));
        if ($trimmed === '') {
            return '';
        }

        $parts = explode('-', $trimmed);

        return $parts[0];
    }

    /**
     * Expand an ISO locale into a full `Intl` tag (`fr` → `fr-FR`, `en` → `en-US`).
     */
    public function localeTag(string $locale): string
    {
        $normalized = $this->normalize($locale);
        $raw = trim(str_replace('_', '-', $locale), '-_');

        if ($raw !== '' && $raw !== $normalized) {
            $parts = explode('-', $raw);
            $language = strtolower((string) $parts[0]);
            $region = (string) ($parts[1] ?? '');

            if (preg_match('/^[A-Za-z]{2}$/', $region) === 1) {
                return $language . '-' . strtoupper($region);
            }

            if (preg_match('/^\d{3}$/', $region) === 1) {
                return $language . '-' . $region;
            }

            return strtolower($raw);
        }

        return match ($normalized) {
            'fr' => 'fr-FR',
            'en' => 'en-US',
            'de' => 'de-DE',
            'es' => 'es-ES',
            'it' => 'it-IT',
            'pt' => 'pt-BR',
            'nl' => 'nl-NL',
            default => $normalized,
        };
    }

    /**
     * Return the registered language roots.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return $this->paths;
    }

    /**
     * Format an integer count with non-breaking thousands separators.
     */
    public function formatCount(int|float $number): string
    {
        if (is_float($number)) {
            $rounded = round($number);
            if (abs($number - $rounded) < 0.0000001) {
                return number_format((int) $rounded, 0, '.', ' ');
            }

            return number_format($number, 2, '.', ' ');
        }

        return number_format($number, 0, '.', ' ');
    }

    /**
     * @param array<string|int, mixed> $replace
     */
    private function applyReplacements(string $line, array $replace, string $locale): string
    {
        if ($replace === []) {
            return $line;
        }

        $named = [];
        $positional = [];
        foreach ($replace as $key => $value) {
            if (is_int($key)) {
                $positional[] = $value;
                continue;
            }

            $named[$key] = $value;
        }

        foreach ($named as $token => $value) {
            $placeholder = str_starts_with((string) $token, ':') ? (string) $token : ':' . $token;
            $rendered = $this->stringify($value, $locale);

            if ($rendered === '') {
                // Drop the token together with one adjacent space so the sentence stays readable.
                $before = str_replace(' ' . $placeholder, '', $line);
                $line = $before !== $line
                    ? $before
                    : str_replace([$placeholder . ' ', $placeholder], ['', ''], $line);
            } else {
                $line = str_replace($placeholder, $rendered, $line);
            }

            $capitalized = ucfirst(ltrim($placeholder, ':'));
            if ($capitalized !== '') {
                $line = str_replace(':' . $capitalized, $this->stringify($this->capitalize($value), $locale), $line);
            }
        }

        if (str_contains($line, '  ')) {
            $line = str_replace('  ', ' ', $line);
        }

        if ($positional !== [] && preg_match('/%[+\-0 ]?\d*(?:\.\d+)?[bcdeEfFgGosuxX]/', $line) === 1) {
            try {
                $args = array_map(fn (mixed $v): string => $this->stringify($v, $locale), $positional);
                $line = vsprintf($line, $args);
            } catch (Throwable) {
                // Argument mismatch: render the raw line instead of breaking the page.
            }
        }

        return $line;
    }

    private function stringify(mixed $value, string $locale): string
    {
        if (is_bool($value)) {
            return $value ? $this->scalar('core.yes', $locale) : $this->scalar('core.no', $locale);
        }

        if ($value === null) {
            return '';
        }

        if (is_int($value) || is_float($value)) {
            return $this->formatCount($value);
        }

        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                $parts[] = $this->stringify($item, $locale);
            }

            return implode(', ', $parts);
        }

        if (is_object($value)) {
            return method_exists($value, '__toString') ? (string) $value : '';
        }

        return (string) $value;
    }

    private function scalar(string $key, string $locale): string
    {
        $line = $this->lookup($key, $locale) ?? $this->lookup($key, $this->fallbackLocale);

        return is_string($line) ? $line : $key;
    }

    private function capitalize(mixed $value): mixed
    {
        return is_string($value) ? ucfirst($value) : $value;
    }

    /**
     * Resolve `plugin::hello-prime.views.admin.title` against the `lang/` folder shipped by
     * the `hello-prime` plugin (or `theme::aurora.…` for a theme).
     *
     * @return array<string, mixed>|string|null
     */
    private function lookupNamespaced(string $key, string $locale): array|string|null
    {
        $parts = explode('::', $key, 2);
        $namespace = strtolower($parts[0]);
        $rest = $parts[1] ?? '';

        // `plugin::hello-prime.views.admin.title` => namespace `plugin`, slug `hello-prime`.
        $cut = strpos($rest, '.');
        if ($cut === false) {
            return null;
        }

        $slug = substr($rest, 0, $cut);
        $segments = explode('.', substr($rest, $cut + 1));
        if ($namespace === '' || $slug === '' || $segments[0] === '') {
            return null;
        }

        $directory = $this->namespaceDirectory($namespace, $slug);
        if ($directory === null) {
            return null;
        }

        foreach (['messages', ...$this->groupsOfPath($directory, $locale)] as $group) {
            $file = $directory . '/' . $locale . '/' . $group . '.php';
            if (!is_file($file)) {
                continue;
            }

            $loaded = require $file;
            if (!is_array($loaded)) {
                continue;
            }

            /** @var array<string, mixed> $loaded */
            $found = $this->walk($loaded, $segments);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Locate the `lang/` root shipped by the plugin or theme named `$slug`.
     */
    private function namespaceDirectory(string $namespace, string $slug): ?string
    {
        $kind = match ($namespace) {
            'plugin', 'plugins' => 'plugins',
            'theme', 'themes' => 'themes',
            default => null,
        };

        if ($kind === null) {
            return null;
        }

        foreach ($this->paths as $path) {
            $pluginDir = \dirname($path);
            if (basename($pluginDir) !== $slug || basename(\dirname($pluginDir)) !== $kind) {
                continue;
            }

            return $path;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function groupsOfPath(string $directory, string $locale): array
    {
        $files = @glob($directory . '/' . $locale . '/*.php') ?: [];
        $groups = [];
        foreach ($files as $file) {
            $name = basename((string) $file, '.php');
            if ($name !== '' && $name !== 'index' && $name !== 'messages') {
                $groups[] = $name;
            }
        }

        sort($groups);

        return $groups;
    }

    /**
     * CLDR-style `one` / `other` split used by French and English.
     */
    private function pluralIndex(int|float $number): int
    {
        $int = (int) (is_float($number) ? round($number) : $number);

        return abs($int) <= 1 ? 0 : 1;
    }

    /**
     * Resolve a key, honouring the `plugin::slug.key` / `theme::slug.key` namespaces.
     *
     * @return array<string, mixed>|string|null
     */
    private function lookup(string $key, string $locale): array|string|null
    {
        if ($key === '' || $locale === '') {
            return null;
        }

        if (str_contains($key, '::')) {
            return $this->lookupNamespaced($key, $locale);
        }

        $segments = explode('.', $key);
        $first = (string) $segments[0];
        $available = $this->groupFiles($locale);

        $groups = [];
        if (in_array($first, $available, true)) {
            $groups[] = $first;
        }
        if (!in_array('core', $groups, true) && in_array('core', $available, true)) {
            $groups[] = 'core';
        }

        foreach ($groups as $group) {
            $path = $group === $first ? array_slice($segments, 1) : $segments;
            if ($path === []) {
                $path = [$key];
            }

            $tree = $this->loadGroup($locale, $group);
            $found = $this->walk($tree, $path);
            if ($found !== null) {
                return $found;
            }

            $flatKey = implode('.', $path);
            if (isset($tree[$flatKey]) && is_string($tree[$flatKey])) {
                return $tree[$flatKey];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $tree
     * @param list<string>         $segments
     *
     * @return array<string, mixed>|string|null
     */
    private function walk(array $tree, array $segments): array|string|null
    {
        $current = $tree;
        foreach ($segments as $segment) {
            if (!is_array($current)) {
                return null;
            }

            // `'404' => [...]` is stored under the integer key 404 by PHP, while the dotted key
            // keeps the string segment, so numeric segments must be tried both ways.
            $resolved = array_key_exists($segment, $current)
                ? $segment
                : (ctype_digit($segment) && array_key_exists((int) $segment, $current) ? (int) $segment : null);

            if ($resolved === null) {
                return null;
            }

            $current = $current[$resolved];
        }

        if (is_string($current)) {
            return $current;
        }

        return is_array($current) && $current !== [] ? $current : null;
    }

    /**
     * Resolve a leaf string when a key points at a nested group (e.g. `admin.actions`).
     *
     * @param array<string, mixed> $tree
     */
    private function firstStringOf(string $key, array $tree): string
    {
        $flat = [];
        $this->collect($tree, $key, $flat);

        foreach (['title', 'label', 'name', 'value'] as $suffix) {
            if (isset($flat[$key . '.' . $suffix])) {
                return $flat[$key . '.' . $suffix];
            }
        }

        $first = reset($flat);

        return is_string($first) ? $first : $key;
    }

    /**
     * Collect string leaves of a tree into dotted keys.
     *
     * @param array<array-key, mixed> $tree
     * @param array<string, string>   $out
     */
    private function collect(array $tree, string $prefix, array &$out): void
    {
        foreach ($tree as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . (string) $key;

            if (is_array($value)) {
                $this->collect($value, $path, $out);
                continue;
            }

            if (is_string($value)) {
                $out[$path] = $value;
            }
        }
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overlay
     *
     * @return array<string, mixed>
     */
    private function mergeDeep(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                /** @var array<string, mixed> $sub */
                $sub = $base[$key];
                /** @var array<string, mixed> $subOverlay */
                $subOverlay = $value;
                $base[$key] = $this->mergeDeep($sub, $subOverlay);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * @return list<string>
     */
    private function groupFiles(string $locale): array
    {
        if ($locale === '') {
            return [];
        }

        if (isset($this->groupsCache[$locale])) {
            return $this->groupsCache[$locale];
        }

        $groups = [];
        foreach ($this->paths as $path) {
            $files = @glob($path . '/' . $locale . '/*.php') ?: [];
            foreach ($files as $file) {
                $name = basename((string) $file, '.php');
                if ($name !== '' && $name !== 'index') {
                    $groups[$name] = true;
                }
            }
        }

        $registered = $this->registered[$locale] ?? [];
        foreach ($registered as $group => $value) {
            if (is_array($value) || is_string($value)) {
                $groups[$group] = true;
            }
        }

        $result = array_keys($groups);
        sort($result);

        $normalized = array_map(static fn (int|string $g): string => (string) $g, $result);
        $this->groupsCache[$locale] = $normalized;

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadGroup(string $locale, string $group): array
    {
        $cacheKey = $locale . '/' . $group;
        if (isset($this->groupCache[$cacheKey])) {
            return $this->groupCache[$cacheKey];
        }

        $tree = [];
        foreach ($this->paths as $path) {
            $file = $path . '/' . $locale . '/' . $group . '.php';
            if (!is_file($file)) {
                continue;
            }

            $loaded = require $file;
            if (is_array($loaded)) {
                /** @var array<string, mixed> $loaded */
                $tree = $this->mergeDeep($tree, $loaded);
            }
        }

        $registered = $this->registered[$locale][$group] ?? null;
        if (is_array($registered)) {
            /** @var array<string, mixed> $registered */
            $tree = $this->mergeDeep($tree, $registered);
        }

        $this->groupCache[$cacheKey] = $tree;

        return $tree;
    }

    private function forgetCaches(): void
    {
        $this->groupCache = [];
        $this->treeCache = [];
        $this->groupsCache = [];
    }

    private function noteMissing(string $key, string $locale): void
    {
        $entry = $locale . ':' . $key;
        if (in_array($entry, $this->missing, true)) {
            return;
        }

        $this->missing[] = $entry;
        $this->hooks?->emit('translations.missing', $key, $locale);

        if (!Application::hasInstance()) {
            return;
        }

        $app = Application::getInstance();
        if (!$app->isDebug() || !$app->container()->has(Logger::class)) {
            return;
        }

        try {
            /** @var Logger $logger */
            $logger = $app->container()->get(Logger::class);
            $logger->warning(sprintf('Traduction manquante [%s] : %s', $locale, $key));
        } catch (Throwable) {
            // Diagnostics must never break rendering.
        }
    }
}
