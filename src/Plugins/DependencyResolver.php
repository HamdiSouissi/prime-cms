<?php

declare(strict_types=1);

namespace Prime\Plugins;

use Prime\Kernel\Application;
use Prime\Support\Version;

/**
 * Evaluates system requirements, plugin dependencies, reverse dependents,
 * and computes topological load order with circular dependency detection.
 */
final class DependencyResolver
{
    public function __construct(
        private readonly string $primeVersion = Application::VERSION,
        private readonly string $phpVersion = PHP_VERSION,
    ) {
    }

    /**
     * Evaluate `requires.prime`, `requires.php`, and `requires.extensions` for a manifest.
     *
     * @param ?callable(string): bool $extensionChecker Injectable extension check for tests
     * @return list<string> Human-readable French unmet requirement messages
     */
    public function checkRequirements(PluginManifest $manifest, ?callable $extensionChecker = null): array
    {
        $issues = [];
        $checkExt = $extensionChecker ?? static fn (string $ext): bool => extension_loaded($ext);

        if ($manifest->requiresPrime !== null) {
            if (!Version::satisfies($this->primeVersion, $manifest->requiresPrime)) {
                $issues[] = sprintf(
                    'Nécessite Prime CMS %s (version actuelle : %s).',
                    $manifest->requiresPrime,
                    $this->primeVersion,
                );
            }
        }

        if ($manifest->requiresPhp !== null) {
            $normalizedPhp = self::normalizePhpVersion($this->phpVersion);
            if (!Version::satisfies($normalizedPhp, $manifest->requiresPhp)) {
                $issues[] = sprintf(
                    'Nécessite PHP %s (version actuelle : %s).',
                    $manifest->requiresPhp,
                    $normalizedPhp,
                );
            }
        }

        foreach ($manifest->requiredExtensions as $ext) {
            if (!$checkExt($ext)) {
                $issues[] = sprintf('Extension PHP requise manquante : "%s".', $ext);
            }
        }

        return $issues;
    }

    /**
     * Check whether a plugin's `dependencies` are installed, active, and version-compatible.
     *
     * @param array<string, PluginManifest> $manifests All valid discovered manifests keyed by slug
     * @param array<string, string> $statuses Map of slug => status (`active`, `inactive`, `errored`)
     * @return list<string>
     */
    public function checkDependencies(PluginManifest $manifest, array $manifests, array $statuses): array
    {
        $issues = [];

        foreach ($manifest->dependencies as $depSlug => $constraint) {
            if (!isset($manifests[$depSlug])) {
                $issues[] = sprintf('Dépendance requise introuvable : "%s" (%s).', $depSlug, $constraint);
                continue;
            }

            $depManifest = $manifests[$depSlug];
            if (!Version::satisfies($depManifest->version, $constraint)) {
                $issues[] = sprintf(
                    'Dépendance "%s" incompatible : requiert %s (installé : %s).',
                    $depManifest->name,
                    $constraint,
                    $depManifest->version,
                );
                continue;
            }

            $depStatus = $statuses[$depSlug] ?? PluginInfo::STATUS_INACTIVE;
            if ($depStatus !== PluginInfo::STATUS_ACTIVE) {
                $issues[] = sprintf(
                    'Dépendance "%s" non activée (doit être active avant activation).',
                    $depManifest->name,
                );
            }
        }

        // Check for direct or transitive dependency cycle involving $manifest->slug
        $cycle = $this->findCycleFor($manifest->slug, $manifests);
        if ($cycle !== null) {
            $issues[] = sprintf('Dépendance circulaire détectée : %s.', implode(' → ', $cycle));
        }

        return $issues;
    }

    /**
     * Compute reverse dependency map: for each slug, which installed plugins depend on it.
     *
     * @param array<string, PluginManifest> $manifests
     * @return array<string, list<string>>
     */
    public function reverseDependencies(array $manifests): array
    {
        $reverse = [];
        foreach ($manifests as $slug => $manifest) {
            foreach (array_keys($manifest->dependencies) as $depSlug) {
                $reverse[$depSlug][] = $slug;
            }
        }

        foreach ($reverse as &$dependents) {
            sort($dependents, SORT_STRING);
        }
        unset($dependents);

        return $reverse;
    }

    /**
     * Topologically sort a subset of plugins (`$manifests`) so dependencies load before dependents.
     * Any plugins trapped in a dependency cycle (or depending on a cycle) are returned in `cycle`.
     *
     * @param array<string, PluginManifest> $manifests
     * @return array{ordered: list<string>, cycle: list<string>}
     */
    public function topologicalSort(array $manifests): array
    {
        $slugs = array_keys($manifests);
        sort($slugs, SORT_STRING);

        $inDegree = [];
        $dependents = [];

        foreach ($slugs as $slug) {
            $inDegree[$slug] = 0;
            $dependents[$slug] = [];
        }

        foreach ($slugs as $slug) {
            foreach (array_keys($manifests[$slug]->dependencies) as $depSlug) {
                if (!isset($manifests[$depSlug])) {
                    continue;
                }
                $inDegree[$slug]++;
                $dependents[$depSlug][] = $slug;
            }
        }

        // Deterministic Kahn's algorithm queue sorted alphabetically
        $queue = [];
        foreach ($slugs as $slug) {
            if ($inDegree[$slug] === 0) {
                $queue[] = $slug;
            }
        }

        $ordered = [];
        while ($queue !== []) {
            sort($queue, SORT_STRING);
            $current = array_shift($queue);
            $ordered[] = $current;

            foreach ($dependents[$current] as $dependent) {
                $inDegree[$dependent]--;
                if ($inDegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        $cycle = [];
        foreach ($slugs as $slug) {
            if ($inDegree[$slug] > 0) {
                $cycle[] = $slug;
            }
        }

        return [
            'ordered' => $ordered,
            'cycle' => $cycle,
        ];
    }

    /**
     * Detect if starting at `$startSlug` reaches a dependency cycle.
     *
     * @param array<string, PluginManifest> $manifests
     * @return ?list<string> Cycle path like `['a', 'b', 'a']`, or null if acyclic
     */
    public function findCycleFor(string $startSlug, array $manifests): ?array
    {
        if (!isset($manifests[$startSlug])) {
            return null;
        }

        $visiting = [];
        $visited = [];
        $stack = [];

        return $this->dfsCycle($startSlug, $manifests, $visiting, $visited, $stack);
    }

    /**
     * @param array<string, PluginManifest> $manifests
     * @param array<string, true> $visiting
     * @param array<string, true> $visited
     * @param list<string> $stack
     * @return ?list<string>
     */
    private function dfsCycle(
        string $slug,
        array $manifests,
        array &$visiting,
        array &$visited,
        array &$stack,
    ): ?array {
        $visiting[$slug] = true;
        $stack[] = $slug;

        if (isset($manifests[$slug])) {
            foreach (array_keys($manifests[$slug]->dependencies) as $dep) {
                if (!isset($manifests[$dep])) {
                    continue;
                }

                if (isset($visiting[$dep])) {
                    $idx = array_search($dep, $stack, true);
                    $cyclePath = $idx !== false ? array_slice($stack, (int) $idx) : [$dep];
                    $cyclePath[] = $dep;

                    return $cyclePath;
                }

                if (!isset($visited[$dep])) {
                    $found = $this->dfsCycle($dep, $manifests, $visiting, $visited, $stack);
                    if ($found !== null) {
                        return $found;
                    }
                }
            }
        }

        array_pop($stack);
        unset($visiting[$slug]);
        $visited[$slug] = true;

        return null;
    }

    /**
     * Strip distro suffixes like `8.3.14-1ubuntu1` into valid SemVer `8.3.14`.
     */
    private static function normalizePhpVersion(string $version): string
    {
        if (preg_match('/^(\d+\.\d+\.\d+)/', $version, $matches) === 1) {
            return $matches[1];
        }

        return '8.2.0';
    }
}
