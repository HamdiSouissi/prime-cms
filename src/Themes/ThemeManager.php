<?php

declare(strict_types=1);

namespace Prime\Themes;

use Prime\Admin\Notice;
use Prime\Admin\Notices;
use Prime\Hooks\Hooks;
use Prime\Kernel\Application;
use Prime\Kernel\Autoloader;
use Prime\Kernel\Env;
use Prime\Log\Logger;
use Prime\Plugins\FatalLoadGuard;
use Prime\Settings\Settings;
use Prime\Themes\Exception\ThemeException;
use Throwable;

/**
 * Discovers, validates, activates, boots, and isolates Prime CMS themes.
 *
 * Guarantees the public site NEVER crashes because of a broken theme: any exception
 * or fatal error during theme booting automatically falls back to Aurora and raises
 * an admin notice naming the failing theme and its error.
 */
final class ThemeManager
{
    public const DEFAULT_THEME = 'aurora';
    public const SETTING_ACTIVE = 'theme.active';
    public const SETTING_FALLBACK_ERROR = 'theme.fallback_error';
    public const FALLBACK_NOTICE_ID = 'theme.fallback';

    private bool $booted = false;

    private ?ThemeInfo $activeTheme = null;

    private ?ThemeInfo $parentTheme = null;

    private bool $fellBackToDefault = false;

    /** @var ?array{slug: string, message: string} */
    private ?array $fallbackError = null;

    /** @var array<string, ThemeInfo>|null */
    private ?array $discoveredCache = null;

    private readonly FatalLoadGuard $fatalGuard;

    public function __construct(
        private readonly Application $app,
        private readonly string $themesPath,
        private readonly ThemeManifestValidator $validator,
        private readonly ThemeAssetPublisher $publisher,
        private readonly ThemeAssetRegistry $assets,
        private readonly ThemeOptions $options,
        private readonly Hooks $hooks,
        private readonly ?Settings $settings = null,
        private readonly ?Autoloader $autoloader = null,
        private readonly ?Notices $notices = null,
        private readonly ?Logger $logger = null,
        ?FatalLoadGuard $fatalGuard = null,
    ) {
        $this->fatalGuard = $fatalGuard ?? new FatalLoadGuard(
            $this->app->storagePath('themes/loading.json'),
        );
    }

    public function themesPath(): string
    {
        return rtrim($this->themesPath, DIRECTORY_SEPARATOR . '/');
    }

    public function isSafeMode(): bool
    {
        return (bool) Env::get('PRIME_SAFE_MODE', false);
    }

    public function didFallBackToDefault(): bool
    {
        return $this->fellBackToDefault;
    }

    /**
     * @return ?array{slug: string, message: string}
     */
    public function fallbackError(): ?array
    {
        return $this->fallbackError;
    }

    /**
     * Discover and validate all themes under `content/themes/{slug}/`.
     *
     * @return array<string, ThemeInfo>
     */
    public function discover(bool $fresh = false): array
    {
        if (!$fresh && $this->discoveredCache !== null) {
            return $this->discoveredCache;
        }

        $root = $this->themesPath();
        $activeSlug = $this->configuredActiveSlug();
        $rawManifests = [];
        $rawErrors = [];
        $rawPaths = [];

        if (is_dir($root)) {
            $entries = scandir($root);
            if ($entries !== false) {
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                        continue;
                    }
                    $themeDir = $root . DIRECTORY_SEPARATOR . $entry;
                    if (!is_dir($themeDir)) {
                        continue;
                    }
                    $rawPaths[$entry] = $themeDir;
                    $validation = $this->validator->validateFile($themeDir . DIRECTORY_SEPARATOR . 'theme.json', $entry);
                    $rawManifests[$entry] = $validation['manifest'];
                    $rawErrors[$entry] = $validation['errors'];
                }
            }
        }

        // Determine parent of the configured active theme (for deletion protection)
        $activeParentSlug = isset($rawManifests[$activeSlug]) ? $rawManifests[$activeSlug]->parent : null;

        $lastError = $this->readStoredFallbackError();

        $result = [];
        foreach ($rawPaths as $slug => $themeDir) {
            $manifest = $rawManifests[$slug];
            $errors = $rawErrors[$slug];
            $valid = $manifest !== null && $errors === [];
            $compatible = $valid;

            if ($manifest !== null) {
                $compatIssues = $this->validator->checkCompatibility($manifest, $this->app->version(), PHP_VERSION);
                if ($compatIssues !== []) {
                    $compatible = false;
                    $errors = array_merge($errors, $compatIssues);
                }

                // Single-level parent theme validation
                if ($manifest->parent !== null) {
                    $pSlug = $manifest->parent;
                    if (!isset($rawManifests[$pSlug])) {
                        $compatible = false;
                        $errors[] = sprintf('Le thème parent « %s » est manquant ou invalide.', $pSlug);
                    } elseif ($rawManifests[$pSlug]->parent !== null) {
                        $compatible = false;
                        $errors[] = sprintf(
                            'Héritage limité à un niveau : le parent « %s » est déjà un thème enfant de « %s ».',
                            $pSlug,
                            $rawManifests[$pSlug]->parent,
                        );
                    } else {
                        $parentCompat = $this->validator->checkCompatibility($rawManifests[$pSlug], $this->app->version(), PHP_VERSION);
                        if ($parentCompat !== []) {
                            $compatible = false;
                            $errors[] = sprintf('Le thème parent « %s » est incompatible : %s', $pSlug, implode(' ', $parentCompat));
                        }
                    }
                }
            }

            $runtimeError = ($lastError !== null && $lastError['slug'] === $slug) ? $lastError['message'] : null;

            $result[$slug] = new ThemeInfo(
                slug: $slug,
                path: $themeDir,
                manifest: $manifest,
                valid: $valid,
                compatible: $compatible,
                active: $slug === $activeSlug,
                isParentOfActive: $activeParentSlug !== null && $slug === $activeParentSlug,
                screenshotUrl: $this->publisher->screenshotUrl($slug, $themeDir),
                errors: $errors,
                runtimeError: $runtimeError,
            );
        }

        // Order active theme first, then alphabetical by display name
        uasort($result, static function (ThemeInfo $a, ThemeInfo $b): int {
            if ($a->active !== $b->active) {
                return $a->active ? -1 : 1;
            }

            return strcasecmp($a->name(), $b->name());
        });

        $this->discoveredCache = $result;

        return $result;
    }

    /**
     * Return a single discovered theme by slug.
     */
    public function get(string $slug, bool $fresh = false): ?ThemeInfo
    {
        $all = $this->discover($fresh);

        return $all[$slug] ?? null;
    }

    /**
     * Return the configured active theme slug (defaults to `aurora`).
     */
    public function configuredActiveSlug(): string
    {
        if ($this->isSafeMode()) {
            return self::DEFAULT_THEME;
        }

        if ($this->settings !== null) {
            try {
                $val = $this->settings->get(self::SETTING_ACTIVE);
                if (is_string($val) && trim($val) !== '') {
                    return trim($val);
                }
            } catch (Throwable) {
                // Pre-install / no DB connection: gracefully use Aurora.
            }
        }

        return self::DEFAULT_THEME;
    }

    /**
     * Return the currently running active theme (falls back to Aurora if active theme is invalid or crashed).
     */
    public function active(): ThemeInfo
    {
        if ($this->activeTheme !== null) {
            return $this->activeTheme;
        }

        $this->bootActive();

        if ($this->activeTheme !== null) {
            return $this->activeTheme;
        }

        $aurora = $this->get(self::DEFAULT_THEME, fresh: true);
        if ($aurora !== null) {
            return $aurora;
        }

        throw new ThemeException('Le thème par défaut « aurora » est introuvable.');
    }

    /**
     * Return the parent theme of the currently active theme, or null if standalone.
     */
    public function activeParent(): ?ThemeInfo
    {
        if (!$this->booted) {
            $this->bootActive();
        }

        return $this->parentTheme;
    }

    /**
     * Boot the active theme (and parent if child) with fatal-error & exception isolation.
     */
    public function bootActive(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        // Recover from a prior uncatchable fatal error inside a theme
        $pendingCrash = $this->fatalGuard->consumePendingCrash();
        if ($pendingCrash !== null && $pendingCrash['slug'] !== self::DEFAULT_THEME) {
            $this->recordFallback(
                $pendingCrash['slug'],
                sprintf('Erreur fatale détectée lors du chargement (%s).', $pendingCrash['phase']),
            );
        }

        $this->fatalGuard->registerShutdownHandler(function (string $slug, string $phase, string $message): void {
            if ($slug !== self::DEFAULT_THEME) {
                $this->recordFallback($slug, sprintf('Erreur fatale (%s) : %s', $phase, $message));
            }
        });

        if ($this->isSafeMode()) {
            $this->bootSpecificTheme(self::DEFAULT_THEME, allowFallback: false);
            $this->pushNotice(
                'theme.safe_mode',
                Notice::TYPE_INFO,
                'Mode sans échec actif',
                'PRIME_SAFE_MODE est activé : le thème par défaut Aurora est forcé.',
            );

            return;
        }

        $desiredSlug = $this->configuredActiveSlug();
        $this->bootSpecificTheme($desiredSlug, allowFallback: true);
    }

    /**
     * Activate a theme by slug, publishing its assets and emitting `theme.deactivated` / `theme.activated`.
     */
    public function activate(string $slug): ThemeInfo
    {
        $theme = $this->get($slug, fresh: true);
        if ($theme === null) {
            throw new ThemeException(sprintf('Le thème « %s » est introuvable.', $slug));
        }
        if (!$theme->valid || !$theme->compatible || $theme->manifest === null) {
            throw new ThemeException(
                sprintf('Impossible d\'activer le thème « %s » : %s', $slug, implode(' ', $theme->errors)),
            );
        }

        $previousSlug = $this->configuredActiveSlug();

        // Publish parent assets first if child theme, then theme assets
        $parent = null;
        if ($theme->manifest->parent !== null) {
            $parent = $this->get($theme->manifest->parent, fresh: false);
            if ($parent === null || !$parent->valid || !$parent->compatible || $parent->manifest === null) {
                throw new ThemeException(sprintf('Le thème parent « %s » est indisponible ou incompatible.', $theme->manifest->parent));
            }
            $this->publisher->publish($parent->slug, $parent->path);
        }
        $this->publisher->publish($theme->slug, $theme->path);

        // Ensure options CSS file exists
        $this->options->writeOptionsCss($theme->manifest, $this->options->all($theme->manifest, $parent?->manifest), $parent?->manifest);

        if ($this->settings !== null) {
            $this->settings->set(self::SETTING_ACTIVE, $theme->slug, autoload: true);
        }

        $this->clearFallbackError();
        $this->discoveredCache = null;
        $this->booted = false;
        $this->activeTheme = null;
        $this->parentTheme = null;

        if ($previousSlug !== $theme->slug) {
            $this->hooks->emit('theme.deactivated', $previousSlug, $theme->slug);
        }
        $this->hooks->emit('theme.activated', $theme->slug, $theme->manifest);

        $activated = $this->get($theme->slug, fresh: true);
        if ($activated === null) {
            throw new ThemeException(sprintf('Le thème « %s » n\'a pas pu être rechargé après activation.', $slug));
        }

        return $activated;
    }

    /**
     * Permanently delete an inactive, non-parent, non-default theme from disk.
     */
    public function delete(string $slug): void
    {
        $theme = $this->get($slug, fresh: true);
        if ($theme === null) {
            throw new ThemeException(sprintf('Le thème « %s » est introuvable.', $slug));
        }
        if ($theme->slug === self::DEFAULT_THEME) {
            throw new ThemeException('Le thème par défaut « Aurora » ne peut pas être supprimé.');
        }
        if ($theme->active) {
            throw new ThemeException(sprintf('Impossible de supprimer le thème actif « %s ».', $theme->name()));
        }
        if ($theme->isParentOfActive) {
            throw new ThemeException(sprintf(
                'Impossible de supprimer « %s » car il est le thème parent du thème actuellement actif.',
                $theme->name(),
            ));
        }

        $this->publisher->unpublish($theme->slug);
        ThemeAssetPublisher::removeDirectory($theme->path);

        if ($this->settings !== null) {
            $this->settings->delete('theme_options.' . $theme->slug);
            $this->settings->delete('theme_options_css.' . $theme->slug);
        }

        $storedError = $this->readStoredFallbackError();
        if ($storedError !== null && $storedError['slug'] === $theme->slug) {
            $this->clearFallbackError();
        }

        $this->discoveredCache = null;
    }

    /**
     * Publish static assets for one theme slug (or all valid discovered themes when null).
     *
     * @return array<string, int> Map of slug => published file count
     */
    public function publishAssets(?string $slug = null): array
    {
        $counts = [];
        $themes = $this->discover(fresh: true);

        if ($slug !== null) {
            $theme = $themes[$slug] ?? null;
            if ($theme === null) {
                throw new ThemeException(sprintf('Le thème « %s » est introuvable.', $slug));
            }
            $counts[$slug] = $this->publisher->publish($slug, $theme->path);

            return $counts;
        }

        foreach ($themes as $tSlug => $info) {
            if ($info->valid) {
                $counts[$tSlug] = $this->publisher->publish($tSlug, $info->path);
            }
        }

        return $counts;
    }

    /**
     * Return the active theme's effective declared menu locations (parent merged with child).
     *
     * @return array<string, string>
     */
    public function menuLocations(): array
    {
        $active = $this->active();
        $parent = $this->activeParent();
        $locations = $parent?->manifest !== null ? $parent->manifest->menus : [];
        if ($active->manifest !== null) {
            $locations = array_merge($locations, $active->manifest->menus);
        }

        return $locations;
    }

    /**
     * Return the active theme's effective declared block zones (parent merged with child).
     *
     * @return array<string, array{label: string, description: string}>
     */
    public function zones(): array
    {
        $active = $this->active();
        $parent = $this->activeParent();
        $zones = $parent?->manifest !== null ? $parent->manifest->zones : [];
        if ($active->manifest !== null) {
            $zones = array_merge($zones, $active->manifest->zones);
        }

        return $zones;
    }

    /**
     * Return page/content templates declared by the active theme (and parent) for `$contentType`.
     *
     * @return array<string, string>
     */
    public function templatesFor(string $contentType): array
    {
        $active = $this->active();
        $parent = $this->activeParent();
        $templates = $parent?->manifest !== null ? $parent->manifest->templatesFor($contentType) : [];
        if ($active->manifest !== null) {
            $templates = array_merge($templates, $active->manifest->templatesFor($contentType));
        }

        return $templates;
    }

    /**
     * Reset cached runtime state (useful in tests after modifying files or settings).
     */
    public function resetCache(): void
    {
        $this->discoveredCache = null;
        $this->booted = false;
        $this->activeTheme = null;
        $this->parentTheme = null;
        $this->fellBackToDefault = false;
        $this->fallbackError = null;
    }

    private function bootSpecificTheme(string $slug, bool $allowFallback): void
    {
        $theme = $this->get($slug, fresh: true);

        if ($theme === null || !$theme->valid || !$theme->compatible || $theme->manifest === null) {
            $reason = $theme === null
                ? sprintf('Le thème « %s » est introuvable.', $slug)
                : implode(' ', $theme->errors);

            if ($allowFallback && $slug !== self::DEFAULT_THEME) {
                $this->recordFallback($slug, $reason);
                $this->bootSpecificTheme(self::DEFAULT_THEME, allowFallback: false);

                return;
            }

            $this->logger?->error('Default theme failed validation.', ['slug' => $slug, 'reason' => $reason]);

            return;
        }

        $parent = null;
        if ($theme->manifest->parent !== null) {
            $parent = $this->get($theme->manifest->parent, fresh: false);
            if ($parent === null || !$parent->valid || !$parent->compatible || $parent->manifest === null) {
                $reason = sprintf('Le thème parent « %s » est invalide ou introuvable.', $theme->manifest->parent);
                if ($allowFallback && $slug !== self::DEFAULT_THEME) {
                    $this->recordFallback($slug, $reason);
                    $this->bootSpecificTheme(self::DEFAULT_THEME, allowFallback: false);

                    return;
                }

                return;
            }
        }

        try {
            $this->fatalGuard->guard($theme->slug, 'boot', function () use ($theme, $parent): void {
                // Boot parent theme first (if child theme), then active theme
                if ($parent !== null && $parent->manifest !== null) {
                    $this->registerAndInvokeTheme($parent);
                }

                $this->registerAndInvokeTheme($theme);
            });

            $this->activeTheme = $theme;
            $this->parentTheme = $parent;

            // Ensure static assets exist in `public/assets/themes/{slug}` on fresh deployments
            if ($parent !== null && !is_dir($this->publisher->targetDir($parent->slug))) {
                $this->publisher->publish($parent->slug, $parent->path);
            }
            if (!is_dir($this->publisher->targetDir($theme->slug))) {
                $this->publisher->publish($theme->slug, $theme->path);
            }

            // Enqueue compiled theme options CSS if present
            $optionsCssUrl = $this->options->publishedCssUrl($theme->manifest, $parent?->manifest);
            if ($optionsCssUrl !== null) {
                $this->assets->style('theme-options', $optionsCssUrl);
            }

            // If this theme previously failed and now boots cleanly, clear the stale fallback error & notice
            if ($allowFallback) {
                $stored = $this->readStoredFallbackError();
                if ($stored !== null && $stored['slug'] === $theme->slug) {
                    $this->clearFallbackError();
                }
            }

            $this->hooks->emit('theme.booted', $theme->slug, $theme->manifest, $parent?->manifest);
        } catch (Throwable $e) {
            $this->logger?->error('Theme boot threw an exception.', [
                'theme' => $theme->slug,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            if ($allowFallback && $theme->slug !== self::DEFAULT_THEME) {
                $this->assets->clear();
                $this->recordFallback($theme->slug, $e->getMessage());
                $this->bootSpecificTheme(self::DEFAULT_THEME, allowFallback: false);
            } elseif ($theme->slug === self::DEFAULT_THEME) {
                $this->recordDefaultThemeFailure($theme->slug, $e->getMessage());
            }
        }
    }

    private function registerAndInvokeTheme(ThemeInfo $theme): void
    {
        $manifest = $theme->manifest;
        if ($manifest === null) {
            return;
        }

        // Register theme image sizes into MediaConfig via the `media.image_sizes` filter
        if ($manifest->imageSizes !== []) {
            $themeSizes = $manifest->imageSizes;
            $this->hooks->filter('media.image_sizes', static function (mixed $sizes) use ($themeSizes): mixed {
                if (!is_array($sizes)) {
                    return $sizes;
                }
                foreach ($themeSizes as $name => $dims) {
                    $sizes[$name] = [
                        'width' => $dims['width'],
                        'height' => $dims['height'] > 0 ? $dims['height'] : null,
                        'crop' => $dims['crop'],
                    ];
                }

                return $sizes;
            });
        }

        // Register theme PSR-4 autoload prefix for `src/`
        $srcDir = rtrim($theme->path, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . 'src';
        if (is_dir($srcDir) && $this->autoloader !== null) {
            $this->autoloader->addNamespace($manifest->namespace, $srcDir);
        }

        $themeFile = $srcDir . DIRECTORY_SEPARATOR . 'Theme.php';
        if (is_file($themeFile) && !class_exists($manifest->main, autoload: true)) {
            require_once $themeFile;
        }

        if (!class_exists($manifest->main)) {
            throw new ThemeException(sprintf(
                'La classe principale "%s" du thème "%s" est introuvable.',
                $manifest->main,
                $manifest->slug,
            ));
        }

        $instance = new ($manifest->main)();
        if (!$instance instanceof ThemeInterface) {
            throw new ThemeException(sprintf(
                'La classe "%s" du thème "%s" doit implémenter %s.',
                $manifest->main,
                $manifest->slug,
                ThemeInterface::class,
            ));
        }

        $context = new ThemeContext(
            manifest: $manifest,
            themePath: $theme->path,
            app: $this->app,
            hooks: $this->hooks,
            assets: $this->assets,
            publisher: $this->publisher,
            settings: $this->settings,
        );

        $instance->boot($context);
    }

    /**
     * Clear any stored fallback error and remove all theme fallback notices.
     */
    public function clearFallbackError(): void
    {
        $this->fellBackToDefault = false;
        $this->fallbackError = null;

        if ($this->settings !== null) {
            try {
                $this->settings->delete(self::SETTING_FALLBACK_ERROR);
            } catch (Throwable) {
                // Ignore if DB unavailable.
            }
        }

        $this->clearThemeFallbackNotices();
    }

    private function recordFallback(string $failingSlug, string $rawError): void
    {
        $safeError = $this->sanitizeErrorMessage($rawError);
        $this->fellBackToDefault = true;
        $this->fallbackError = [
            'slug' => $failingSlug,
            'message' => $safeError,
        ];

        $this->logger?->error('Theme error isolated; falling back to Aurora.', [
            'failing_theme' => $failingSlug,
            'error' => $safeError,
        ]);

        if ($this->settings !== null) {
            try {
                $this->settings->set(self::SETTING_ACTIVE, self::DEFAULT_THEME, autoload: true);
                $this->settings->set(self::SETTING_FALLBACK_ERROR, $this->fallbackError, autoload: false);
            } catch (Throwable) {
                // Ignore if DB unavailable.
            }
        }

        $this->clearThemeFallbackNotices();
        $this->pushNotice(
            self::FALLBACK_NOTICE_ID,
            Notice::TYPE_DANGER,
            sprintf('Erreur dans le thème « %s »', $failingSlug),
            sprintf(
                'Le thème « %s » a provoqué une erreur (%s). Prime CMS a automatiquement basculé sur le thème Aurora.',
                $failingSlug,
                $safeError,
            ),
        );
    }

    private function recordDefaultThemeFailure(string $slug, string $rawError): void
    {
        $safeError = $this->sanitizeErrorMessage($rawError);
        $this->fallbackError = [
            'slug' => $slug,
            'message' => $safeError,
        ];

        $this->logger?->critical('Default theme boot failed; no fallback theme is available.', [
            'theme' => $slug,
            'error' => $safeError,
        ]);

        if ($this->settings !== null) {
            try {
                $this->settings->set(self::SETTING_FALLBACK_ERROR, $this->fallbackError, autoload: false);
            } catch (Throwable) {
                // Ignore if DB unavailable.
            }
        }

        $this->clearThemeFallbackNotices();
        $this->pushNotice(
            self::FALLBACK_NOTICE_ID,
            Notice::TYPE_DANGER,
            sprintf('Erreur critique dans le thème par défaut « %s »', $slug),
            sprintf(
                'Le thème par défaut « %s » n’a pas pu démarrer (%s). Aucune bascule automatique n’est possible : corrigez le thème ou vérifiez les journaux.',
                $slug,
                $safeError,
            ),
        );
    }

    private function clearThemeFallbackNotices(): void
    {
        if ($this->notices === null) {
            return;
        }

        try {
            $this->notices->remove(self::FALLBACK_NOTICE_ID);
            foreach ($this->notices->all() as $notice) {
                if ($notice->source === 'themes' && str_starts_with($notice->id, 'theme.fallback')) {
                    $this->notices->remove($notice->id);
                }
            }
        } catch (Throwable) {
            // Never crash on notice cleanup.
        }
    }

    private function pushNotice(string $id, string $type, string $title, string $message): void
    {
        if ($this->notices === null) {
            return;
        }

        try {
            $this->notices->add(new Notice(
                id: $id,
                type: $type,
                title: $title,
                message: $message,
                capability: 'themes.manage',
                dismissible: true,
                source: 'themes',
            ));
        } catch (Throwable) {
            // Never crash on notice persistence.
        }
    }

    /**
     * @return ?array{slug: string, message: string}
     */
    private function readStoredFallbackError(): ?array
    {
        if ($this->fallbackError !== null) {
            return $this->fallbackError;
        }

        if ($this->settings === null) {
            return null;
        }

        try {
            $raw = $this->settings->get(self::SETTING_FALLBACK_ERROR);
            if (is_array($raw) && is_string($raw['slug'] ?? null) && is_string($raw['message'] ?? null)) {
                return [
                    'slug' => $raw['slug'],
                    'message' => $raw['message'],
                ];
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function sanitizeErrorMessage(string $raw): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $raw) ?? $raw);
        // Strip absolute filesystem paths to avoid leaking server layout in notices
        $clean = str_replace($this->app->basePath() . '/', '', $clean);
        if (mb_strlen($clean, 'UTF-8') > 240) {
            $clean = mb_substr($clean, 0, 237, 'UTF-8') . '…';
        }

        return $clean !== '' ? $clean : 'erreur interne inconnue';
    }
}
