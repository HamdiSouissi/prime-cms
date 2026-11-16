<?php

declare(strict_types=1);

namespace Prime\Themes;

use Prime\Media\MediaRepository;
use Prime\Settings\Settings;
use Prime\Themes\Exception\ThemeValidationException;
use Throwable;

/**
 * Typed validation, persistence (`theme_options.{slug}`), static CSS custom property
 * file generation (`public/assets/themes/{slug}/options-{hash}.css`), and site favicon
 * generation (32, 180, 192, 512 px) for theme customization options.
 */
final class ThemeOptions
{
    public const FAVICON_SIZES = [
        32 => 'favicon-32.png',
        180 => 'apple-touch-icon.png',
        192 => 'icon-192.png',
        512 => 'icon-512.png',
    ];

    /**
     * In-request override map used ONLY during authenticated customizer live previews.
     *
     * @var array<string, mixed>|null
     */
    private ?array $previewOverrides = null;

    public function __construct(
        private readonly ?Settings $settings,
        private readonly ThemeAssetPublisher $publisher,
        private readonly string $publicRoot,
        private readonly ?MediaRepository $media = null,
    ) {
    }

    /**
     * Build the merged option schema (parent defaults + child overrides when active theme is a child).
     *
     * @return array{
     *     sections: array<string, array{label: string, description: string}>,
     *     fields: array<string, array<string, mixed>>
     * }
     */
    public function schema(ThemeManifest $theme, ?ThemeManifest $parent = null): array
    {
        $sections = $parent !== null ? $parent->options['sections'] : [];
        $fields = $parent !== null ? $parent->options['fields'] : [];

        foreach ($theme->options['sections'] as $key => $sec) {
            $sections[$key] = $sec;
        }
        foreach ($theme->options['fields'] as $key => $field) {
            $fields[$key] = $field;
        }

        return [
            'sections' => $sections,
            'fields' => $fields,
        ];
    }

    /**
     * Return all effective option values for `$theme` (schema defaults overlaid with saved values
     * and optional in-request customizer preview overrides).
     *
     * @return array<string, mixed>
     */
    public function all(ThemeManifest $theme, ?ThemeManifest $parent = null): array
    {
        $schema = $this->schema($theme, $parent);
        $saved = $this->readSaved($theme->slug);

        $values = [];
        foreach ($schema['fields'] as $key => $field) {
            $raw = array_key_exists($key, $saved) ? $saved[$key] : ($field['default'] ?? null);
            $values[$key] = $this->coerceValue($field, $raw);
        }

        if ($this->previewOverrides !== null) {
            foreach ($this->previewOverrides as $key => $val) {
                if (isset($schema['fields'][$key])) {
                    $values[$key] = $this->coerceValue($schema['fields'][$key], $val);
                }
            }
        }

        return $values;
    }

    /**
     * Return a single effective option value or its default.
     */
    public function get(ThemeManifest $theme, string $key, mixed $fallback = null, ?ThemeManifest $parent = null): mixed
    {
        $all = $this->all($theme, $parent);
        if (array_key_exists($key, $all)) {
            return $all[$key];
        }

        return $fallback;
    }

    /**
     * Set in-request preview overrides (never persisted; never visible to public visitors).
     *
     * @param array<string, mixed>|null $overrides
     */
    public function setPreviewOverrides(?array $overrides): void
    {
        $this->previewOverrides = $overrides;
    }

    /**
     * Validate `$input` strictly against the theme's declared option schema.
     *
     * @param array<string, mixed> $input
     * @return array{valid: bool, values: array<string, mixed>, errors: array<string, list<string>>}
     */
    public function validate(ThemeManifest $theme, array $input, ?ThemeManifest $parent = null): array
    {
        $schema = $this->schema($theme, $parent);
        $values = [];
        $errors = [];

        foreach ($schema['fields'] as $key => $field) {
            $type = (string) ($field['type'] ?? 'text');
            $hasInput = array_key_exists($key, $input);

            if (!$hasInput) {
                if ($type === 'toggle') {
                    $values[$key] = false;
                } else {
                    $values[$key] = $this->coerceValue($field, $field['default'] ?? null);
                }
                continue;
            }

            $raw = $input[$key];
            $result = $this->validateField($key, $field, $raw);
            if ($result['error'] !== null) {
                $errors[$key][] = $result['error'];
            } else {
                $values[$key] = $result['value'];
            }
        }

        return [
            'valid' => $errors === [],
            'values' => $values,
            'errors' => $errors,
        ];
    }

    /**
     * Validate, persist into `pc_settings`, and generate the static CSS custom properties file.
     *
     * @param array<string, mixed> $input
     * @return array{values: array<string, mixed>, css_url: ?string}
     */
    public function save(ThemeManifest $theme, array $input, ?ThemeManifest $parent = null): array
    {
        $validated = $this->validate($theme, $input, $parent);
        if (!$validated['valid']) {
            throw new ThemeValidationException(
                'Certaines options du thème sont invalides.',
                $validated['errors'],
            );
        }

        $values = $validated['values'];

        if ($this->settings !== null) {
            $this->settings->set($this->settingKey($theme->slug), $values, autoload: true);
        }

        $cssUrl = $this->writeOptionsCss($theme, $values, $parent);

        return [
            'values' => $values,
            'css_url' => $cssUrl,
        ];
    }

    /**
     * Reset all saved options for `$theme` back to schema defaults and regenerate CSS.
     *
     * @return array{values: array<string, mixed>, css_url: ?string}
     */
    public function reset(ThemeManifest $theme, ?ThemeManifest $parent = null): array
    {
        $schema = $this->schema($theme, $parent);
        $defaults = [];
        foreach ($schema['fields'] as $key => $field) {
            $defaults[$key] = $this->coerceValue($field, $field['default'] ?? null);
        }

        if ($this->settings !== null) {
            $this->settings->delete($this->settingKey($theme->slug));
        }

        $cssUrl = $this->writeOptionsCss($theme, $defaults, $parent);

        return [
            'values' => $defaults,
            'css_url' => $cssUrl,
        ];
    }

    /**
     * Build the `:root { --var: value; }` CSS string from validated option values.
     *
     * @param array<string, mixed> $values
     */
    public function compileCss(ThemeManifest $theme, array $values, ?ThemeManifest $parent = null): string
    {
        $schema = $this->schema($theme, $parent);
        $declarations = [];

        foreach ($schema['fields'] as $key => $field) {
            $cssVar = is_string($field['css_var'] ?? null) ? $field['css_var'] : null;
            if ($cssVar === null || $cssVar === '' || preg_match('/^--[a-zA-Z0-9_-]{1,64}$/', $cssVar) !== 1) {
                continue;
            }

            if (!array_key_exists($key, $values)) {
                continue;
            }

            $val = $values[$key];
            $cssVal = $this->formatCssValue($field, $val);
            if ($cssVal !== null) {
                $declarations[] = sprintf('  %s: %s;', $cssVar, $cssVal);
            }
        }

        if ($declarations === []) {
            return '';
        }

        return ":root {\n" . implode("\n", $declarations) . "\n}\n";
    }

    /**
     * Write `public/assets/themes/{slug}/options-{hash}.css`, prune previous hashed option CSS files,
     * and store the active CSS filename in `pc_settings`.
     *
     * @param array<string, mixed> $values
     */
    public function writeOptionsCss(ThemeManifest $theme, array $values, ?ThemeManifest $parent = null): ?string
    {
        $css = $this->compileCss($theme, $values, $parent);
        $targetDir = $this->publisher->targetDir($theme->slug);

        // Remove stale options-*.css files for this theme
        if (is_dir($targetDir)) {
            $oldFiles = glob($targetDir . DIRECTORY_SEPARATOR . 'options-*.css');
            if ($oldFiles !== false) {
                foreach ($oldFiles as $old) {
                    if (is_file($old)) {
                        @unlink($old);
                    }
                }
            }
        }

        if ($css === '') {
            if ($this->settings !== null) {
                $this->settings->delete($this->cssSettingKey($theme->slug));
            }

            return null;
        }

        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        $hash = substr(sha1($css), 0, 10);
        $filename = 'options-' . $hash . '.css';
        $fullPath = $targetDir . DIRECTORY_SEPARATOR . $filename;

        if (@file_put_contents($fullPath, $css, LOCK_EX) === false) {
            return null;
        }

        if ($this->settings !== null) {
            $this->settings->set($this->cssSettingKey($theme->slug), $filename, autoload: true);
        }

        return $this->publisher->url($theme->slug, $filename);
    }

    /**
     * Return the public URL of the published `options-{hash}.css` file if present on disk.
     */
    public function publishedCssUrl(ThemeManifest $theme, ?ThemeManifest $parent = null): ?string
    {
        $targetDir = $this->publisher->targetDir($theme->slug);

        if ($this->settings !== null) {
            try {
                $filename = $this->settings->get($this->cssSettingKey($theme->slug));
                if (is_string($filename) && preg_match('/^options-[a-f0-9]{10}\.css$/', $filename) === 1) {
                    if (is_file($targetDir . DIRECTORY_SEPARATOR . $filename)) {
                        return $this->publisher->url($theme->slug, $filename);
                    }
                }
            } catch (Throwable) {
                // Fall back to filesystem scan or regeneration.
            }
        }

        if (is_dir($targetDir)) {
            $matches = glob($targetDir . DIRECTORY_SEPARATOR . 'options-*.css');
            if ($matches !== false && $matches !== []) {
                sort($matches);
                $latest = basename((string) end($matches));

                return $this->publisher->url($theme->slug, $latest);
            }
        }

        return $this->writeOptionsCss($theme, $this->all($theme, $parent), $parent);
    }

    /**
     * Generate square PNG favicons (32, 180, 192, 512 px) from a site icon media ID
     * into `public/assets/site/favicons/`.
     *
     * @return array<int, string> Map of pixel size => public URL
     */
    public function generateFaviconsFromMedia(int $mediaId): array
    {
        if ($this->media === null || $mediaId <= 0 || !extension_loaded('gd')) {
            return [];
        }

        $item = $this->media->find($mediaId);
        if ($item === null || !$item->isImage()) {
            return [];
        }

        $sourcePath = $this->resolveMediaDiskPath($item->diskPath);
        if ($sourcePath === null || !is_file($sourcePath)) {
            return [];
        }

        $raw = @file_get_contents($sourcePath);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $sourceImage = @imagecreatefromstring($raw);
        if ($sourceImage === false) {
            return [];
        }

        $srcW = imagesx($sourceImage);
        $srcH = imagesy($sourceImage);
        if ($srcW <= 0 || $srcH <= 0) {
            imagedestroy($sourceImage);

            return [];
        }

        $faviconDir = rtrim($this->publicRoot, DIRECTORY_SEPARATOR . '/') . '/assets/site/favicons';
        if (!is_dir($faviconDir)) {
            @mkdir($faviconDir, 0755, true);
        }

        // Center-crop square from source image
        $side = min($srcW, $srcH);
        $srcX = (int) floor(($srcW - $side) / 2);
        $srcY = (int) floor(($srcH - $side) / 2);

        $generated = [];
        foreach (self::FAVICON_SIZES as $size => $filename) {
            $canvas = imagecreatetruecolor($size, $size);
            if ($canvas === false) {
                continue;
            }
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            if ($transparent !== false) {
                imagefilledrectangle($canvas, 0, 0, $size, $size, $transparent);
            }
            imagecopyresampled($canvas, $sourceImage, 0, 0, $srcX, $srcY, $size, $size, $side, $side);

            $dest = $faviconDir . DIRECTORY_SEPARATOR . $filename;
            if (@imagepng($canvas, $dest, 6)) {
                $rawHash = sha1_file($dest);
                $hash = is_string($rawHash) ? substr($rawHash, 0, 8) : '';
                $generated[$size] = '/assets/site/favicons/' . $filename . ($hash !== '' ? '?v=' . $hash : '');
            }
            imagedestroy($canvas);
        }

        imagedestroy($sourceImage);

        if ($this->settings !== null && $generated !== []) {
            $this->settings->set('site.favicons', $generated, autoload: true);
        }

        return $generated;
    }

    /**
     * Return active favicon URLs (generated from site icon if set, otherwise Prime brand favicons).
     *
     * @return array<int, string>
     */
    public function favicons(): array
    {
        if ($this->settings !== null) {
            try {
                $saved = $this->settings->get('site.favicons');
                if (is_array($saved) && $saved !== []) {
                    $result = [];
                    foreach ($saved as $size => $url) {
                        if (is_numeric($size) && is_string($url) && str_starts_with($url, '/')) {
                            $result[(int) $size] = $url;
                        }
                    }
                    if ($result !== []) {
                        return $result;
                    }
                }
            } catch (Throwable) {
                // Fall back to default brand icons.
            }
        }

        return [
            32 => '/assets/brand/favicon-32.png',
            180 => '/assets/brand/apple-touch-icon.png',
            192 => '/assets/brand/icon-192.png',
        ];
    }

    /**
     * @param array<string, mixed> $field
     * @return array{value: mixed, error: ?string}
     */
    private function validateField(string $key, array $field, mixed $raw): array
    {
        $type = (string) ($field['type'] ?? 'text');
        $label = (string) ($field['label'] ?? $key);

        switch ($type) {
            case 'color':
                if (!is_string($raw)) {
                    return ['value' => null, 'error' => sprintf('« %s » doit être une couleur hexadécimale.', $label)];
                }
                $hex = strtoupper(trim($raw));
                if (preg_match('/^#([0-9A-F]{3}|[0-9A-F]{6})$/', $hex) !== 1) {
                    return ['value' => null, 'error' => sprintf('« %s » doit être une couleur hexadécimale valide (ex. #C9441F).', $label)];
                }

                return ['value' => $hex, 'error' => null];

            case 'text':
                if ($raw === null) {
                    return ['value' => '', 'error' => null];
                }
                if (!is_scalar($raw)) {
                    return ['value' => null, 'error' => sprintf('« %s » doit être un texte.', $label)];
                }
                $text = trim(strip_tags((string) $raw));
                if (mb_strlen($text, 'UTF-8') > 255) {
                    return ['value' => null, 'error' => sprintf('« %s » ne peut pas dépasser 255 caractères.', $label)];
                }

                return ['value' => $text, 'error' => null];

            case 'textarea':
                if ($raw === null) {
                    return ['value' => '', 'error' => null];
                }
                if (!is_scalar($raw)) {
                    return ['value' => null, 'error' => sprintf('« %s » doit être un texte.', $label)];
                }
                $text = trim(strip_tags((string) $raw));
                if (mb_strlen($text, 'UTF-8') > 2000) {
                    return ['value' => null, 'error' => sprintf('« %s » ne peut pas dépasser 2 000 caractères.', $label)];
                }

                return ['value' => $text, 'error' => null];

            case 'toggle':
                return ['value' => $this->toBool($raw), 'error' => null];

            case 'select':
            case 'font':
                if (!is_string($raw) && !is_int($raw)) {
                    return ['value' => null, 'error' => sprintf('« %s » contient une valeur invalide.', $label)];
                }
                $val = (string) $raw;
                $choices = is_array($field['choices'] ?? null) ? $field['choices'] : [];
                if (!array_key_exists($val, $choices)) {
                    return ['value' => null, 'error' => sprintf('« %s » : choix « %s » non autorisé.', $label, $val)];
                }

                return ['value' => $val, 'error' => null];

            case 'range':
                if (!is_numeric($raw)) {
                    return ['value' => null, 'error' => sprintf('« %s » doit être un nombre.', $label)];
                }
                $num = (float) $raw;
                $min = isset($field['min']) && is_numeric($field['min']) ? (float) $field['min'] : 0.0;
                $max = isset($field['max']) && is_numeric($field['max']) ? (float) $field['max'] : 100.0;
                if ($num < $min || $num > $max) {
                    return [
                        'value' => null,
                        'error' => sprintf('« %s » doit être compris entre %s et %s.', $label, (string) $min, (string) $max),
                    ];
                }
                $step = isset($field['step']) && is_numeric($field['step']) && (float) $field['step'] > 0
                    ? (float) $field['step']
                    : 1.0;
                $isIntStep = abs($step - round($step)) < 0.0001 && abs($min - round($min)) < 0.0001;

                return ['value' => $isIntStep ? (int) round($num) : round($num, 4), 'error' => null];

            case 'media':
                if ($raw === null || $raw === '' || $raw === 0 || $raw === '0') {
                    return ['value' => null, 'error' => null];
                }
                if (!is_numeric($raw) || (int) $raw <= 0) {
                    return ['value' => null, 'error' => sprintf('« %s » doit référencer un média valide.', $label)];
                }
                $mediaId = (int) $raw;
                if ($this->media !== null && $this->media->find($mediaId) === null) {
                    return ['value' => null, 'error' => sprintf('« %s » : le média #%d est introuvable.', $label, $mediaId)];
                }

                return ['value' => $mediaId, 'error' => null];
        }

        return ['value' => null, 'error' => sprintf('Type d\'option inconnu pour « %s ».', $label)];
    }

    /**
     * @param array<string, mixed> $field
     */
    private function coerceValue(array $field, mixed $raw): mixed
    {
        $type = (string) ($field['type'] ?? 'text');

        return match ($type) {
            'toggle' => $this->toBool($raw),
            'range' => is_numeric($raw) ? ((float) $raw == (int) $raw ? (int) $raw : (float) $raw) : ($field['min'] ?? 0),
            'media' => is_numeric($raw) && (int) $raw > 0 ? (int) $raw : null,
            default => is_scalar($raw) ? (string) $raw : '',
        };
    }

    /**
     * Format a validated option value into a strictly safe CSS value for `:root`.
     *
     * @param array<string, mixed> $field
     */
    private function formatCssValue(array $field, mixed $val): ?string
    {
        $type = (string) ($field['type'] ?? 'text');

        if ($type === 'color' && is_string($val) && preg_match('/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/', $val) === 1) {
            return strtoupper($val);
        }

        if ($type === 'range' && is_numeric($val)) {
            $unit = is_string($field['css_unit'] ?? null) ? trim($field['css_unit']) : '';
            if ($unit !== '' && !in_array($unit, ['px', 'rem', 'em', '%', 'ch', 'vw', 'vh'], true)) {
                $unit = '';
            }

            return (string) $val . $unit;
        }

        if ($type === 'font' && is_string($val)) {
            $cssMap = is_array($field['css_map'] ?? null) ? $field['css_map'] : [];
            if (isset($cssMap[$val]) && is_string($cssMap[$val])) {
                $stack = trim($cssMap[$val]);
                // Strictly allow safe font-family stacks (letters, digits, spaces, commas, hyphens, single quotes)
                if (preg_match("/^[a-zA-Z0-9 ,'\\-]+$/", $stack) === 1) {
                    return $stack;
                }
            }
        }

        if ($type === 'select' && is_string($val)) {
            $cssMap = is_array($field['css_map'] ?? null) ? $field['css_map'] : [];
            if (isset($cssMap[$val]) && is_string($cssMap[$val])) {
                $mapped = trim($cssMap[$val]);
                if (preg_match('/^[a-zA-Z0-9_.%(), \-]+$/', $mapped) === 1) {
                    return $mapped;
                }
            }
        }

        return null;
    }

    private function toBool(mixed $val): bool
    {
        if (is_bool($val)) {
            return $val;
        }
        if (is_int($val)) {
            return $val !== 0;
        }
        if (is_string($val)) {
            return in_array(strtolower(trim($val)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function readSaved(string $slug): array
    {
        if ($this->settings === null) {
            return [];
        }

        try {
            $raw = $this->settings->get($this->settingKey($slug), []);

            /** @var array<string, mixed> $raw */
            return is_array($raw) ? $raw : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function resolveMediaDiskPath(string $relativePath): ?string
    {
        $clean = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($clean === '' || str_contains($clean, '..') || str_contains($clean, "\0")) {
            return null;
        }

        $root = dirname(rtrim($this->publicRoot, DIRECTORY_SEPARATOR . '/'));
        $candidate = $root . '/content/uploads/' . $clean;

        return is_file($candidate) ? $candidate : null;
    }

    private function settingKey(string $slug): string
    {
        return 'theme_options.' . $slug;
    }

    private function cssSettingKey(string $slug): string
    {
        return 'theme_options_css.' . $slug;
    }
}
