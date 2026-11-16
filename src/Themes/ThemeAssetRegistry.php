<?php

declare(strict_types=1);

namespace Prime\Themes;

use InvalidArgumentException;

/**
 * Dependency-ordered front-end stylesheet and script registry for the active theme
 * and plugins (`ThemeContext::style()`, `ThemeContext::script()`, `site.assets`).
 */
final class ThemeAssetRegistry
{
    /**
     * @var array<string, array{handle: string, url: string, deps: list<string>, media: string}>
     */
    private array $styles = [];

    /**
     * @var array<string, array{handle: string, url: string, deps: list<string>, defer: bool, module: bool}>
     */
    private array $scripts = [];

    public function __construct(
        private readonly string $publicRoot = '',
    ) {
    }

    /**
     * Register a stylesheet with optional dependency handles.
     *
     * @param list<string> $deps
     */
    public function style(string $handle, string $url, array $deps = [], string $media = 'all'): self
    {
        $cleanHandle = trim($handle);
        if ($cleanHandle === '') {
            throw new InvalidArgumentException('Asset handle cannot be empty.');
        }

        $safeUrl = $this->normalizeAndVersionUrl($url);
        if ($safeUrl === null) {
            return $this;
        }

        $this->styles[$cleanHandle] = [
            'handle' => $cleanHandle,
            'url' => $safeUrl,
            'deps' => array_values(array_unique(array_filter(array_map('trim', $deps), static fn (string $d): bool => $d !== ''))),
            'media' => trim($media) !== '' ? trim($media) : 'all',
        ];

        return $this;
    }

    /**
     * Register a script with optional dependency handles and `defer` / `module` flags.
     *
     * @param list<string> $deps
     * @param array{defer?: bool, module?: bool} $options
     */
    public function script(string $handle, string $url, array $deps = [], array $options = []): self
    {
        $cleanHandle = trim($handle);
        if ($cleanHandle === '') {
            throw new InvalidArgumentException('Asset handle cannot be empty.');
        }

        $safeUrl = $this->normalizeAndVersionUrl($url);
        if ($safeUrl === null) {
            return $this;
        }

        $this->scripts[$cleanHandle] = [
            'handle' => $cleanHandle,
            'url' => $safeUrl,
            'deps' => array_values(array_unique(array_filter(array_map('trim', $deps), static fn (string $d): bool => $d !== ''))),
            'defer' => array_key_exists('defer', $options) ? (bool) $options['defer'] : true,
            'module' => array_key_exists('module', $options) ? (bool) $options['module'] : false,
        ];

        return $this;
    }

    /**
     * Return all registered styles in resolved dependency order without duplicates.
     *
     * @return list<array{handle: string, url: string, deps: list<string>, media: string}>
     */
    public function resolvedStyles(): array
    {
        $ordered = [];
        foreach ($this->resolveOrder($this->styles) as $handle) {
            $ordered[] = $this->styles[$handle];
        }

        return $ordered;
    }

    /**
     * Return all registered scripts in resolved dependency order without duplicates.
     *
     * @return list<array{handle: string, url: string, deps: list<string>, defer: bool, module: bool}>
     */
    public function resolvedScripts(): array
    {
        $ordered = [];
        foreach ($this->resolveOrder($this->scripts) as $handle) {
            $ordered[] = $this->scripts[$handle];
        }

        return $ordered;
    }

    /**
     * Render `<link rel="stylesheet">` tags in dependency order.
     */
    public function renderStyles(): string
    {
        $tags = [];
        foreach ($this->resolvedStyles() as $style) {
            $mediaAttr = $style['media'] !== 'all'
                ? ' media="' . self::esc($style['media']) . '"'
                : '';
            $tags[] = sprintf(
                '<link rel="stylesheet" href="%s"%s>',
                self::esc($style['url']),
                $mediaAttr,
            );
        }

        return implode("\n", $tags);
    }

    /**
     * Render `<script>` tags in dependency order.
     */
    public function renderScripts(): string
    {
        $tags = [];
        foreach ($this->resolvedScripts() as $script) {
            $attrs = ['src="' . self::esc($script['url']) . '"'];
            if ($script['module']) {
                $attrs[] = 'type="module"';
            } elseif ($script['defer']) {
                $attrs[] = 'defer';
            }
            $tags[] = '<script ' . implode(' ', $attrs) . '></script>';
        }

        return implode("\n", $tags);
    }

    /**
     * Remove all registered assets (useful for per-request resets in tests).
     */
    public function clear(): void
    {
        $this->styles = [];
        $this->scripts = [];
    }

    /**
     * Compute a content-hash versioned public URL for a local asset path.
     */
    public function versionUrl(string $url): string
    {
        return $this->normalizeAndVersionUrl($url) ?? '';
    }

    /**
     * Topological sort with cycle guard preserving insertion order for peers.
     *
     * @param array<string, array{deps: list<string>}> $items
     * @return list<string>
     */
    private function resolveOrder(array $items): array
    {
        $visited = [];
        $visiting = [];
        $order = [];

        $visit = static function (string $handle) use (&$visit, &$visited, &$visiting, &$order, $items): void {
            if (isset($visited[$handle]) || isset($visiting[$handle]) || !isset($items[$handle])) {
                return;
            }
            $visiting[$handle] = true;
            foreach ($items[$handle]['deps'] as $dep) {
                if (isset($items[$dep])) {
                    $visit($dep);
                }
            }
            unset($visiting[$handle]);
            $visited[$handle] = true;
            $order[] = $handle;
        };

        foreach (array_keys($items) as $handle) {
            $visit($handle);
        }

        return $order;
    }

    private function normalizeAndVersionUrl(string $url): ?string
    {
        $trimmed = trim($url);
        if ($trimmed === '' || str_contains($trimmed, "\0")) {
            return null;
        }

        // Only allow local root-relative paths (`/assets/...`) — no `javascript:`, `data:`, or external hosts by default
        if (!str_starts_with($trimmed, '/') || str_starts_with($trimmed, '//')) {
            return null;
        }

        // Already versioned
        if (str_contains($trimmed, '?v=')) {
            return $trimmed;
        }

        $pathOnly = explode('?', $trimmed, 2)[0];
        if ($this->publicRoot !== '' && !str_contains($pathOnly, '..')) {
            $diskFile = rtrim($this->publicRoot, DIRECTORY_SEPARATOR . '/') . $pathOnly;
            if (is_file($diskFile) && is_readable($diskFile)) {
                $rawHash = sha1_file($diskFile);
                if (is_string($rawHash) && $rawHash !== '') {
                    $hash = substr($rawHash, 0, 10);
                    $sep = str_contains($trimmed, '?') ? '&' : '?';

                    return $trimmed . $sep . 'v=' . $hash;
                }
            }
        }

        return $trimmed;
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
