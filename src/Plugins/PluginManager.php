<?php

declare(strict_types=1);

namespace Prime\Plugins;

use Prime\Admin\Menu\AdminMenu;
use Prime\Admin\Notice;
use Prime\Admin\Notices;
use Prime\Auth\Capabilities;
use Prime\Database\Connection;
use Prime\Database\Migrations\Migrator;
use Prime\Hooks\Hooks;
use Prime\Http\Router;
use Prime\Kernel\Application;
use Prime\Kernel\Autoloader;
use Prime\Log\Logger;
use Prime\Plugins\Exception\PluginException;
use Prime\Settings\Settings;
use Prime\Support\Version;
use Prime\View\View;
use Throwable;

/**
 * Central orchestrator of the Prime CMS plugin ecosystem:
 * discovery, validation, dependency resolution, lifecycle transitions
 * (activate / deactivate / uninstall / auto-upgrade), fault isolation,
 * safe mode, and boot ordering.
 */
final class PluginManager
{
    /** @var ?array<string, PluginInfo> Request-cached discovery result */
    private ?array $discoveredCache = null;

    /** @var array<string, PluginInterface> Instantiated plugin objects */
    private array $instances = [];

    /** @var array<string, PluginContext> Created contexts keyed by slug */
    private array $contexts = [];

    private bool $booted = false;

    public function __construct(
        private readonly Application $app,
        private readonly string $pluginsPath,
        private readonly Autoloader $autoloader,
        private readonly PluginRepository $repository,
        private readonly ManifestValidator $validator,
        private readonly DependencyResolver $resolver,
        private readonly AssetPublisher $assetPublisher,
        private readonly FatalLoadGuard $fatalGuard,
        private readonly Migrator $migrator,
        private readonly Hooks $hooks,
        private readonly Router $router,
        private readonly View $views,
        private readonly AdminMenu $adminMenu,
        private readonly Capabilities $capabilities,
        private readonly Settings $settings,
        private readonly Connection $db,
        private readonly Notices $notices,
        private readonly Logger $logger,
        private readonly bool $safeMode = false,
    ) {
    }

    public function isSafeMode(): bool
    {
        return $this->safeMode;
    }

    public function pluginsPath(): string
    {
        return $this->pluginsPath;
    }

    public function assetPublisher(): AssetPublisher
    {
        return $this->assetPublisher;
    }

    public function invalidateCache(): void
    {
        $this->discoveredCache = null;
    }

    /**
     * Discover all plugins installed inside `content/plugins/*` and merge their
     * manifest validation, requirements, dependency status, and database state.
     *
     * Result is cached per request unless `$fresh = true`.
     *
     * @return array<string, PluginInfo>
     */
    public function discover(bool $fresh = false): array
    {
        if (!$fresh && $this->discoveredCache !== null) {
            return $this->discoveredCache;
        }

        $dbRows = $this->repository->all();

        /** @var array<string, array{dir: string, manifest: ?PluginManifest, errors: list<string>, warnings: list<string>}> $raw */
        $raw = [];

        if (is_dir($this->pluginsPath) && is_readable($this->pluginsPath)) {
            $entries = scandir($this->pluginsPath);
            if ($entries !== false) {
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                        continue;
                    }

                    $dir = rtrim($this->pluginsPath, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . $entry;
                    if (!is_dir($dir)) {
                        continue;
                    }

                    $result = $this->validator->validateFile($dir . DIRECTORY_SEPARATOR . 'plugin.json', $entry);
                    $raw[$entry] = [
                        'dir' => $dir,
                        'manifest' => $result['manifest'],
                        'errors' => $result['errors'],
                        'warnings' => $result['warnings'],
                    ];
                }
            }
        }

        /** @var array<string, PluginManifest> $validManifests */
        $validManifests = [];
        /** @var array<string, string> $statuses */
        $statuses = [];

        foreach ($raw as $slug => $item) {
            $dbRow = $dbRows[$slug] ?? null;
            $status = $dbRow !== null ? $dbRow['status'] : PluginInfo::STATUS_INACTIVE;
            $statuses[$slug] = $status;

            if ($item['manifest'] !== null) {
                $validManifests[$slug] = $item['manifest'];
            }
        }

        $reverseDeps = $this->resolver->reverseDependencies($validManifests);

        $discovered = [];
        foreach ($raw as $slug => $item) {
            $manifest = $item['manifest'];
            $dbRow = $dbRows[$slug] ?? null;
            $status = $statuses[$slug];
            $installedVersion = $dbRow !== null ? $dbRow['version'] : null;

            $reqIssues = [];
            $depIssues = [];
            $needsUpdate = false;
            $isDowngrade = false;
            $iconUrl = null;
            $settingsUrl = null;

            if ($manifest !== null) {
                $reqIssues = $this->resolver->checkRequirements($manifest);
                $depIssues = $this->resolver->checkDependencies($manifest, $validManifests, $statuses);

                if ($installedVersion !== null && Version::isValid($installedVersion)) {
                    $cmp = Version::compare($manifest->version, $installedVersion);
                    $needsUpdate = $cmp > 0;
                    $isDowngrade = $cmp < 0;
                }

                if (isset($this->contexts[$slug])) {
                    $settingsUrl = $this->contexts[$slug]->settingsUrl();
                } else {
                    $settingsUrl = $manifest->settingsUrl;
                }

                if ($manifest->icon !== null) {
                    try {
                        $publishedFile = $this->assetPublisher->targetDirectory($slug)
                            . DIRECTORY_SEPARATOR
                            . ltrim(preg_replace('#^assets/#', '', $manifest->icon) ?? $manifest->icon, '/');
                        if (is_file($publishedFile)) {
                            $iconUrl = $this->assetPublisher->publicUrl($slug, $manifest->version, $manifest->icon);
                        }
                    } catch (Throwable) {
                        $iconUrl = null;
                    }
                }
            }

            $requiredBy = $reverseDeps[$slug] ?? [];
            $activeDependents = [];
            foreach ($requiredBy as $depSlug) {
                if (($statuses[$depSlug] ?? PluginInfo::STATUS_INACTIVE) === PluginInfo::STATUS_ACTIVE) {
                    $activeDependents[] = $depSlug;
                }
            }

            $discovered[$slug] = new PluginInfo(
                slug: $slug,
                directory: $item['dir'],
                manifest: $manifest,
                status: $status,
                installedVersion: $installedVersion,
                lastError: $dbRow !== null ? $dbRow['last_error'] : null,
                erroredAt: $dbRow !== null ? $dbRow['errored_at'] : null,
                installedAt: $dbRow !== null ? $dbRow['installed_at'] : null,
                activatedAt: $dbRow !== null ? $dbRow['activated_at'] : null,
                errors: $item['errors'],
                warnings: $item['warnings'],
                requirementIssues: $reqIssues,
                dependencyIssues: $depIssues,
                needsUpdate: $needsUpdate,
                isDowngrade: $isDowngrade,
                settingsUrl: $settingsUrl,
                iconUrl: $iconUrl,
                requiredBy: $requiredBy,
                activeDependents: $activeDependents,
            );
        }

        uasort($discovered, static fn (PluginInfo $a, PluginInfo $b): int => strcasecmp($a->name(), $b->name()));

        $this->discoveredCache = $discovered;

        return $discovered;
    }

    public function get(string $slug, bool $fresh = false): ?PluginInfo
    {
        $all = $this->discover($fresh);

        return $all[$slug] ?? null;
    }

    /**
     * Return all currently loaded (registered & booted) plugin contexts keyed by slug.
     *
     * @return array<string, PluginContext>
     */
    public function loaded(): array
    {
        return $this->contexts;
    }

    /**
     * Boot all active plugins in topological dependency order with full fault isolation (L5).
     */
    public function bootActivePlugins(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        if ($this->safeMode) {
            $this->logger->warning('PRIME_SAFE_MODE est actif : aucun plugin n\'a été chargé.');

            return;
        }

        if (!$this->repository->tableExists()) {
            return;
        }

        // 1. Wire shutdown fatal guard & recover any crash left behind from a prior request
        $this->fatalGuard->registerShutdownHandler(function (string $slug, string $phase, string $message): void {
            $this->isolatePluginFailure($slug, $phase, $message);
        });

        $pendingCrash = $this->fatalGuard->consumePendingCrash();
        if ($pendingCrash !== null) {
            $this->isolatePluginFailure(
                $pendingCrash['slug'],
                $pendingCrash['phase'],
                sprintf(
                    'Crash fatal détecté lors de la requête précédente pendant la phase "%s" (started_at: %s).',
                    $pendingCrash['phase'],
                    $pendingCrash['started_at'],
                ),
            );
        }

        // 2. Wire Hooks owner error handler so any plugin listener/filter crash is isolated cleanly
        $this->hooks->setOwnerErrorHandler(function (string $owner, string $hookName, Throwable $exception): void {
            $this->isolatePluginFailure(
                $owner,
                'hook:' . $hookName,
                sprintf('%s: %s (%s:%d)', $exception::class, $exception->getMessage(), $exception->getFile(), $exception->getLine()),
                $exception,
            );
        });

        $discovered = $this->discover(fresh: true);

        /** @var array<string, PluginManifest> $activeManifests */
        $activeManifests = [];
        /** @var array<string, PluginInfo> $activeInfos */
        $activeInfos = [];

        foreach ($discovered as $slug => $info) {
            if (!$info->isActive()) {
                continue;
            }

            if (!$info->isValid() || $info->manifest === null) {
                $this->isolatePluginFailure(
                    $slug,
                    'manifest',
                    'Manifeste plugin.json invalide : ' . implode(' ; ', $info->errors),
                );
                continue;
            }

            if ($info->requirementIssues !== []) {
                $this->isolatePluginFailure(
                    $slug,
                    'requirements',
                    implode(' ; ', $info->requirementIssues),
                    version: $info->manifest->version,
                    manifestHash: $info->manifest->hash,
                );
                continue;
            }

            $activeManifests[$slug] = $info->manifest;
            $activeInfos[$slug] = $info;
        }

        // 3. Topological sort & circular dependency detection
        $sorted = $this->resolver->topologicalSort($activeManifests);

        foreach ($sorted['cycle'] as $cycleSlug) {
            $manifest = $activeManifests[$cycleSlug];
            $cyclePath = $this->resolver->findCycleFor($cycleSlug, $activeManifests);
            $cycleLabel = $cyclePath !== null ? implode(' → ', $cyclePath) : implode(', ', $sorted['cycle']);
            $this->isolatePluginFailure(
                $cycleSlug,
                'dependencies',
                sprintf('Dépendance circulaire détectée : %s.', $cycleLabel),
                version: $manifest->version,
                manifestHash: $manifest->hash,
            );
        }

        // Verify all dependencies of ordered plugins are actually active & acyclic
        $loadable = [];
        foreach ($sorted['ordered'] as $slug) {
            $manifest = $activeManifests[$slug];
            $missing = [];
            foreach ($manifest->dependencies as $depSlug => $constraint) {
                if (!isset($loadable[$depSlug])) {
                    $missing[] = sprintf('"%s" (%s)', $depSlug, $constraint);
                    continue;
                }
                if (!Version::satisfies($activeManifests[$depSlug]->version, $constraint)) {
                    $missing[] = sprintf('"%s" (%s requis, %s installé)', $depSlug, $constraint, $activeManifests[$depSlug]->version);
                }
            }

            if ($missing !== []) {
                $this->isolatePluginFailure(
                    $slug,
                    'dependencies',
                    'Dépendances non satisfaites : ' . implode(', ', $missing),
                    version: $manifest->version,
                    manifestHash: $manifest->hash,
                );
                continue;
            }

            $loadable[$slug] = $activeInfos[$slug];
        }

        // 4. Handle version upgrades/downgrades, then register() all loadable plugins in topological order
        /** @var array<string, array{instance: PluginInterface, context: PluginContext, manifest: PluginManifest}> $registered */
        $registered = [];

        foreach ($loadable as $slug => $info) {
            $manifest = $info->manifest;
            if ($manifest === null) {
                continue;
            }

            if ($info->needsUpdate && $info->installedVersion !== null) {
                try {
                    $this->performUpgrade($info, $manifest, $info->installedVersion);
                } catch (Throwable $exception) {
                    $this->isolatePluginFailure(
                        $slug,
                        'upgrade',
                        sprintf('Échec de la mise à jour %s → %s : %s', $info->installedVersion, $manifest->version, $exception->getMessage()),
                        $exception,
                        $manifest->version,
                        $manifest->hash,
                    );
                    continue;
                }
            } elseif ($info->isDowngrade && $info->installedVersion !== null) {
                $this->pluginLogger($slug)->warning(sprintf(
                    'Version rétrogradée détectée sur disque (%s < %s installée). Les migrations ne sont pas rétrogradées automatiquement.',
                    $manifest->version,
                    $info->installedVersion,
                ));
            }

            try {
                $context = $this->createContext($manifest, $info->directory);
                $instance = $this->fatalGuard->guard($slug, 'register', function () use ($manifest, $info, $context): PluginInterface {
                    $this->registerPluginRuntime($manifest, $info->directory);
                    $plugin = $this->instantiateMainClass($manifest);
                    $this->hooks->withOwner($manifest->slug, static function () use ($plugin, $context): void {
                        $plugin->register($context);
                    });

                    return $plugin;
                });

                $this->instances[$slug] = $instance;
                $this->contexts[$slug] = $context;
                $registered[$slug] = [
                    'instance' => $instance,
                    'context' => $context,
                    'manifest' => $manifest,
                ];
            } catch (Throwable $exception) {
                $this->hooks->removeByOwner($slug);
                unset($this->instances[$slug], $this->contexts[$slug]);
                $this->isolatePluginFailure(
                    $slug,
                    'register',
                    sprintf('%s: %s (%s:%d)', $exception::class, $exception->getMessage(), $exception->getFile(), $exception->getLine()),
                    $exception,
                    $manifest->version,
                    $manifest->hash,
                );
            }
        }

        // 5. Call boot() on every successfully registered plugin in topological order
        foreach ($registered as $slug => $entry) {
            try {
                $this->fatalGuard->guard($slug, 'boot', function () use ($entry): void {
                    $this->hooks->withOwner($entry['manifest']->slug, static function () use ($entry): void {
                        $entry['instance']->boot($entry['context']);
                    });
                });
            } catch (Throwable $exception) {
                $this->hooks->removeByOwner($slug);
                unset($this->instances[$slug], $this->contexts[$slug]);
                $this->isolatePluginFailure(
                    $slug,
                    'boot',
                    sprintf('%s: %s (%s:%d)', $exception::class, $exception->getMessage(), $exception->getFile(), $exception->getLine()),
                    $exception,
                    $entry['manifest']->version,
                    $entry['manifest']->hash,
                );
            }
        }

        // Refresh discovery cache so settingsUrl/errored states reflect boot results
        $this->invalidateCache();

        // 6. Notify ecosystem that all active plugins have loaded
        $this->hooks->emit('plugins.loaded', $this->contexts);
    }

    /**
     * Activate a plugin following the exact 7-step atomic lifecycle (L4).
     */
    public function activate(string $slug): PluginInfo
    {
        $info = $this->get($slug, fresh: true);
        if ($info === null) {
            throw new PluginException(sprintf('Plugin "%s" introuvable dans %s.', $slug, $this->pluginsPath));
        }

        if ($info->isActive()) {
            return $info;
        }

        // Step 1: Validate plugin.json
        if (!$info->isValid() || $info->manifest === null) {
            throw new PluginException(
                sprintf('Impossible d\'activer "%s" : %s', $slug, implode(' ; ', $info->errors)),
            );
        }

        $manifest = $info->manifest;

        if ($info->isDowngrade && $info->installedVersion !== null) {
            throw new PluginException(sprintf(
                'Rétrogradation refusée pour "%s" (%s < %s installée).',
                $manifest->name,
                $manifest->version,
                $info->installedVersion,
            ));
        }

        // Step 2: Verify system requirements (`requires`)
        if ($info->requirementIssues !== []) {
            throw new PluginException(
                sprintf('Prérequis non satisfaits pour "%s" : %s', $manifest->name, implode(' ; ', $info->requirementIssues)),
            );
        }

        // Step 3: Verify dependencies are installed, active, compatible, and acyclic
        if ($info->dependencyIssues !== []) {
            throw new PluginException(
                sprintf('Dépendances non satisfaites pour "%s" : %s', $manifest->name, implode(' ; ', $info->dependencyIssues)),
            );
        }

        $migrationsDir = $info->directory . DIRECTORY_SEPARATOR . 'migrations';
        $migrationBatch = null;
        $assetsPublished = false;

        try {
            // Step 4: Execute pending plugin migrations
            if (is_dir($migrationsDir)) {
                $migResult = $this->migrator->runForSource($slug, $migrationsDir);
                $migrationBatch = $migResult['batch'];
            }

            // Step 5: Publish whitelisted static assets
            $this->assetPublisher->publish($slug, $info->directory);
            $assetsPublished = true;

            // Step 6: register() + boot() + activate() inside fault-isolation guard
            $context = $this->createContext($manifest, $info->directory);
            $instance = $this->fatalGuard->guard($slug, 'activate', function () use ($manifest, $info, $context): PluginInterface {
                $this->registerPluginRuntime($manifest, $info->directory);
                $plugin = $this->instantiateMainClass($manifest);

                $this->hooks->withOwner($manifest->slug, static function () use ($plugin, $context): void {
                    $plugin->register($context);
                    $plugin->boot($context);
                    $plugin->activate($context);
                });

                return $plugin;
            });

            $this->instances[$slug] = $instance;
            $this->contexts[$slug] = $context;
        } catch (Throwable $exception) {
            // Rollback newly executed migration batch + assets + hooks, mark errored
            if ($migrationBatch !== null && is_dir($migrationsDir)) {
                try {
                    $this->migrator->rollbackBatchForSource($slug, $migrationBatch, $migrationsDir);
                } catch (Throwable $rollbackError) {
                    $this->pluginLogger($slug)->error('Échec du rollback de migration après erreur d\'activation : ' . $rollbackError->getMessage());
                }
            }

            if ($assetsPublished) {
                try {
                    $this->assetPublisher->remove($slug);
                } catch (Throwable) {
                    // Best-effort cleanup
                }
            }

            $this->hooks->removeByOwner($slug);
            unset($this->instances[$slug], $this->contexts[$slug]);

            $this->isolatePluginFailure(
                $slug,
                'activate',
                sprintf('%s: %s (%s:%d)', $exception::class, $exception->getMessage(), $exception->getFile(), $exception->getLine()),
                $exception,
                $manifest->version,
                $manifest->hash,
            );
            $this->invalidateCache();

            throw new PluginException(
                sprintf('Échec de l\'activation du plugin "%s" : %s', $manifest->name, $exception->getMessage()),
                0,
                $exception,
            );
        }

        // Step 7: Mark active in DB, clear prior error notice, emit `plugin.activated`
        $this->repository->markActive($slug, $manifest->version, $manifest->hash);
        $this->notices->remove($this->errorNoticeId($slug));
        $this->invalidateCache();

        $this->hooks->emit('plugin.activated', $manifest, $context);

        return $this->get($slug, fresh: true) ?? $info;
    }

    /**
     * Deactivate an active plugin (blocked if another active plugin depends on it).
     * Preserves database tables, settings, and plugin files.
     */
    public function deactivate(string $slug): PluginInfo
    {
        $info = $this->get($slug, fresh: true);
        if ($info === null) {
            throw new PluginException(sprintf('Plugin "%s" introuvable.', $slug));
        }

        if ($info->activeDependents !== []) {
            throw new PluginException(sprintf(
                'Impossible de désactiver "%s" : requis par %s. Désactivez d\'abord ces plugins.',
                $info->name(),
                implode(', ', $info->activeDependents),
            ));
        }

        if ($info->isValid() && $info->manifest !== null) {
            try {
                $context = $this->contexts[$slug] ?? $this->createContext($info->manifest, $info->directory);
                $instance = $this->instances[$slug] ?? null;

                if ($instance === null) {
                    $this->registerPluginRuntime($info->manifest, $info->directory);
                    $instance = $this->instantiateMainClass($info->manifest);
                }

                $this->fatalGuard->guard($slug, 'deactivate', function () use ($instance, $context, $slug): void {
                    $this->hooks->withOwner($slug, static function () use ($instance, $context): void {
                        $instance->deactivate($context);
                    });
                });
            } catch (Throwable $exception) {
                // Log deactivation error but never trap an administrator from deactivating a broken plugin
                $this->pluginLogger($slug)->error('Erreur dans deactivate() : ' . $exception->getMessage());
            }
        }

        $this->hooks->removeByOwner($slug);
        unset($this->instances[$slug], $this->contexts[$slug]);

        $this->repository->markInactive(
            $slug,
            $info->manifest?->version,
            $info->manifest?->hash,
        );
        $this->notices->remove($this->errorNoticeId($slug));
        $this->invalidateCache();

        $this->hooks->emit('plugin.deactivated', $info->manifest, $slug);

        return $this->get($slug, fresh: true) ?? $info;
    }

    /**
     * Completely uninstall an inactive plugin:
     * invokes `uninstall()`, rolls back ALL plugin migrations, deletes `plugin.{slug}.*`
     * settings and notices, removes published assets, removes `pc_plugins` row,
     * and deletes `content/plugins/{slug}/` unless `$deleteFiles === false`.
     */
    public function uninstall(string $slug, bool $deleteFiles = true): void
    {
        $info = $this->get($slug, fresh: true);
        if ($info === null) {
            // Still allow cleaning up orphan DB rows if folder was already deleted manually
            $dbRow = $this->repository->find($slug);
            if ($dbRow === null) {
                throw new PluginException(sprintf('Plugin "%s" introuvable.', $slug));
            }
            if ($dbRow['status'] === PluginInfo::STATUS_ACTIVE) {
                throw new PluginException(sprintf('Le plugin "%s" doit être désactivé avant d\'être supprimé.', $slug));
            }

            $this->settings->deleteByPrefix('plugin.' . $slug . '.');
            $this->notices->removeBySource($slug);
            $this->notices->remove($this->errorNoticeId($slug));
            $this->assetPublisher->remove($slug);
            $this->repository->delete($slug);
            $this->invalidateCache();
            $this->hooks->emit('plugin.uninstalled', $slug, $deleteFiles);

            return;
        }

        if ($info->isActive()) {
            throw new PluginException(sprintf(
                'Impossible de supprimer "%s" : le plugin est actuellement actif. Désactivez-le d\'abord.',
                $info->name(),
            ));
        }

        if ($info->requiredBy !== []) {
            throw new PluginException(sprintf(
                'Impossible de supprimer "%s" : d\'autres plugins installés en dépendent (%s).',
                $info->name(),
                implode(', ', $info->requiredBy),
            ));
        }

        // 1. Call plugin's own uninstall() hook if loadable
        if ($info->isValid() && $info->manifest !== null) {
            try {
                $context = $this->createContext($info->manifest, $info->directory);
                $this->registerPluginRuntime($info->manifest, $info->directory);
                $instance = $this->instantiateMainClass($info->manifest);

                $this->fatalGuard->guard($slug, 'uninstall', function () use ($instance, $context, $slug): void {
                    $this->hooks->withOwner($slug, static function () use ($instance, $context): void {
                        $instance->uninstall($context);
                    });
                });
            } catch (Throwable $exception) {
                $this->pluginLogger($slug)->error('Erreur dans uninstall() : ' . $exception->getMessage());
            }
        }

        // 2. Roll back ALL plugin migrations
        $migrationsDir = $info->directory . DIRECTORY_SEPARATOR . 'migrations';
        if (is_dir($migrationsDir)) {
            $this->migrator->rollbackAllForSource($slug, $migrationsDir);
        }

        // 3. Delete all `plugin.{slug}.*` settings & notices
        $this->settings->deleteByPrefix('plugin.' . $slug . '.');
        $this->notices->removeBySource($slug);
        $this->notices->remove($this->errorNoticeId($slug));

        // 4. Remove published public assets
        $this->assetPublisher->remove($slug);

        // 5. Delete database row from `pc_plugins`
        $this->repository->delete($slug);

        // 6. Remove plugin directory on disk unless `--keep-files`
        if ($deleteFiles) {
            $realRoot = realpath($this->pluginsPath);
            $realDir = realpath($info->directory);
            if ($realRoot !== false && $realDir !== false && str_starts_with($realDir, $realRoot . DIRECTORY_SEPARATOR)) {
                AssetPublisher::deleteDirectory($realDir);
            }
        }

        $this->hooks->removeByOwner($slug);
        unset($this->instances[$slug], $this->contexts[$slug]);
        $this->invalidateCache();

        $this->hooks->emit('plugin.uninstalled', $slug, $deleteFiles);
    }

    /**
     * Publish assets for a single plugin or all active plugins (`plugin:publish-assets`).
     *
     * @return array<string, int> Map of slug => copied asset file count
     */
    public function publishAssets(?string $slug = null): array
    {
        $discovered = $this->discover(fresh: true);
        $results = [];

        if ($slug !== null) {
            if (!isset($discovered[$slug])) {
                throw new PluginException(sprintf('Plugin "%s" introuvable.', $slug));
            }
            $results[$slug] = $this->assetPublisher->publish($slug, $discovered[$slug]->directory);

            return $results;
        }

        foreach ($discovered as $pluginSlug => $info) {
            if ($info->isActive()) {
                $results[$pluginSlug] = $this->assetPublisher->publish($pluginSlug, $info->directory);
            }
        }

        return $results;
    }

    /**
     * Synchronize an upgraded active plugin immediately (used after ZIP replacement or on boot).
     */
    public function syncUpgradedPlugin(string $slug, string $previousVersion): void
    {
        $info = $this->get($slug, fresh: true);
        if ($info === null || !$info->isValid() || $info->manifest === null) {
            return;
        }

        $this->performUpgrade($info, $info->manifest, $previousVersion);
        $this->invalidateCache();
    }

    private function performUpgrade(PluginInfo $info, PluginManifest $manifest, string $previousVersion): void
    {
        $migrationsDir = $info->directory . DIRECTORY_SEPARATOR . 'migrations';
        if (is_dir($migrationsDir)) {
            $this->migrator->runForSource($manifest->slug, $migrationsDir);
        }

        $this->assetPublisher->publish($manifest->slug, $info->directory);
        $this->repository->updateVersion($manifest->slug, $manifest->version, $manifest->hash);

        $this->hooks->emit('plugin.updated', $manifest, $previousVersion);
    }

    private function registerPluginRuntime(PluginManifest $manifest, string $directory): void
    {
        $srcDir = $directory . DIRECTORY_SEPARATOR . 'src';
        if (is_dir($srcDir)) {
            $this->autoloader->addNamespace($manifest->namespace, $srcDir);
        }

        $viewsDir = $directory . DIRECTORY_SEPARATOR . 'views';
        if (is_dir($viewsDir)) {
            $this->views->addNamespace($manifest->slug, $viewsDir);
        }
    }

    private function instantiateMainClass(PluginManifest $manifest): PluginInterface
    {
        $class = $manifest->main;
        if (!class_exists($class)) {
            throw new PluginException(sprintf(
                'La classe principale "%s" est introuvable dans le dossier src/ du plugin "%s".',
                $class,
                $manifest->slug,
            ));
        }

        $instance = new $class();
        if (!$instance instanceof PluginInterface) {
            throw new PluginException(sprintf(
                'La classe "%s" doit implémenter %s.',
                $class,
                PluginInterface::class,
            ));
        }

        return $instance;
    }

    private function createContext(PluginManifest $manifest, string $directory): PluginContext
    {
        return new PluginContext(
            manifest: $manifest,
            directory: $directory,
            app: $this->app,
            hooks: $this->hooks,
            router: $this->router,
            views: $this->views,
            adminMenu: $this->adminMenu,
            capabilities: $this->capabilities,
            settings: $this->settings,
            db: $this->db,
            assetPublisher: $this->assetPublisher,
            notices: $this->notices,
            pluginLogger: $this->pluginLogger($manifest->slug),
        );
    }

    private function isolatePluginFailure(
        string $slug,
        string $phase,
        string $errorMessage,
        ?Throwable $exception = null,
        ?string $version = null,
        ?string $manifestHash = null,
    ): void {
        $this->repository->markErrored($slug, $errorMessage, $version, $manifestHash);

        $contextData = [
            'plugin' => $slug,
            'phase' => $phase,
        ];
        if ($exception !== null) {
            $contextData['exception'] = $exception::class;
            $contextData['file'] = $exception->getFile();
            $contextData['line'] = $exception->getLine();
        }

        $this->pluginLogger($slug)->error(
            sprintf('Plugin "%s" isolé en erreur pendant [%s] : %s', $slug, $phase, $errorMessage),
            $contextData,
        );

        $this->notices->add(new Notice(
            id: $this->errorNoticeId($slug),
            type: Notice::TYPE_DANGER,
            title: sprintf('Le plugin « %s » a été désactivé suite à une erreur', $slug),
            message: mb_substr($errorMessage, 0, 280),
            actionLabel: 'Réessayer l\'activation',
            actionUrl: '/admin/plugins/' . rawurlencode($slug) . '/activate',
            actionMethod: 'POST',
            capability: 'plugins.manage',
            dismissible: false,
            source: 'core.plugins',
        ));

        $this->hooks->emit('plugin.failed', $slug, $phase, $errorMessage, $exception);
    }

    private function errorNoticeId(string $slug): string
    {
        return 'plugin.errored.' . $slug;
    }

    private function pluginLogger(string $slug): Logger
    {
        return $this->logger->withChannel('plugin.' . $slug);
    }
}
