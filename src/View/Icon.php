<?php

declare(strict_types=1);

namespace Prime\View;

use Prime\Kernel\Application;
use Prime\View\Exception\ViewException;

/**
 * Renders Lucide SVG icons from the local admin sprite with validation and escaping.
 */
final class Icon
{
    /**
     * Cached symbol ID sets keyed by sprite file path.
     *
     * @var array<string, array<string, true>>
     */
    private static array $symbolCache = [];

    /**
     * Render an accessible inline `<svg><use .../></svg>` referencing the local icon sprite.
     */
    public static function render(
        string $name,
        int $size = 20,
        string $class = '',
        ?bool $debug = null,
        ?string $spritePath = null,
    ): string {
        $resolvedPath = $spritePath ?? self::defaultSpritePath();
        $isDebug = $debug ?? (Application::hasInstance() && Application::getInstance()->isDebug());

        if ($name === '' || !self::exists($name, $resolvedPath)) {
            if ($isDebug) {
                throw new ViewException(sprintf('Unknown icon [%s] in sprite [%s].', $name, $resolvedPath));
            }

            return '';
        }

        $safeSize = max(1, $size);
        $classes = trim('pc-icon ' . trim($class));
        $href = self::spriteUrl() . '#' . rawurlencode($name);

        return sprintf(
            '<svg class="%s" width="%d" height="%d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><use href="%s"></use></svg>',
            self::escape($classes),
            $safeSize,
            $safeSize,
            self::escape($href),
        );
    }

    /**
     * Determine whether an icon symbol exists in the SVG sprite.
     */
    public static function exists(string $name, ?string $spritePath = null): bool
    {
        $resolvedPath = $spritePath ?? self::defaultSpritePath();
        $symbols = self::symbols($resolvedPath);

        return isset($symbols[$name]);
    }

    /**
     * Return all available icon names from the sprite.
     *
     * @return list<string>
     */
    public static function names(?string $spritePath = null): array
    {
        $resolvedPath = $spritePath ?? self::defaultSpritePath();
        $names = array_keys(self::symbols($resolvedPath));
        sort($names);

        return $names;
    }

    /**
     * Clear the cached sprite symbol table (primarily for tests).
     */
    public static function clearCache(): void
    {
        self::$symbolCache = [];
    }

    /**
     * @return array<string, true>
     */
    private static function symbols(string $spritePath): array
    {
        if (isset(self::$symbolCache[$spritePath])) {
            return self::$symbolCache[$spritePath];
        }

        if (!is_file($spritePath) || !is_readable($spritePath)) {
            self::$symbolCache[$spritePath] = [];

            return [];
        }

        $contents = file_get_contents($spritePath);
        if ($contents === false) {
            self::$symbolCache[$spritePath] = [];

            return [];
        }

        $map = [];
        if (preg_match_all('/<symbol\b[^>]*\bid="([^"]+)"/', $contents, $matches) > 0) {
            foreach ($matches[1] as $id) {
                $map[$id] = true;
            }
        }

        self::$symbolCache[$spritePath] = $map;

        return $map;
    }

    private static function defaultSpritePath(): string
    {
        if (Application::hasInstance()) {
            return Application::getInstance()->publicPath('assets/admin/icons/sprite.svg');
        }

        return dirname(__DIR__, 2) . '/public/assets/admin/icons/sprite.svg';
    }

    private static function spriteUrl(): string
    {
        if (function_exists('asset')) {
            return asset('admin/icons/sprite.svg');
        }

        return '/assets/admin/icons/sprite.svg';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
