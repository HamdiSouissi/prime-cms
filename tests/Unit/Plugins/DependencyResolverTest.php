<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Plugins;

use PHPUnit\Framework\TestCase;
use Prime\Plugins\DependencyResolver;
use Prime\Plugins\PluginInfo;
use Prime\Plugins\PluginManifest;

final class DependencyResolverTest extends TestCase
{
    public function testChecksPrimePhpAndExtensionRequirements(): void
    {
        $resolver = new DependencyResolver(primeVersion: '0.5.0', phpVersion: '8.3.14-1ubuntu1');

        $okManifest = $this->manifest('ok-plugin', requiresPrime: '^0.5', requiresPhp: '^8.2', extensions: ['json']);
        $this->assertSame([], $resolver->checkRequirements($okManifest, static fn (string $ext): bool => $ext === 'json'));

        $failManifest = $this->manifest('fail-plugin', requiresPrime: '>=9.0', requiresPhp: '>=8.4', extensions: ['missing_ext']);
        $issues = $resolver->checkRequirements($failManifest, static fn (string $ext): bool => false);
        $this->assertCount(3, $issues);
    }

    public function testChecksDependencyExistenceVersionAndActiveState(): void
    {
        $resolver = new DependencyResolver('0.5.0', '8.3.0');
        $base = $this->manifest('base-lib', version: '1.2.0');
        $consumer = $this->manifest('dep-consumer', dependencies: ['base-lib' => '^1.0']);
        $manifests = ['base-lib' => $base, 'dep-consumer' => $consumer];

        // Inactive dependency -> blocked
        $inactiveIssues = $resolver->checkDependencies($consumer, $manifests, ['base-lib' => PluginInfo::STATUS_INACTIVE]);
        $this->assertCount(1, $inactiveIssues);

        // Active dependency -> satisfied
        $activeIssues = $resolver->checkDependencies($consumer, $manifests, ['base-lib' => PluginInfo::STATUS_ACTIVE]);
        $this->assertSame([], $activeIssues);

        // Missing dependency -> blocked
        $missingIssues = $resolver->checkDependencies($consumer, ['dep-consumer' => $consumer], []);
        $this->assertCount(1, $missingIssues);

        // Incompatible version -> blocked
        $oldBase = $this->manifest('base-lib', version: '0.9.0');
        $versionIssues = $resolver->checkDependencies($consumer, ['base-lib' => $oldBase, 'dep-consumer' => $consumer], ['base-lib' => PluginInfo::STATUS_ACTIVE]);
        $this->assertCount(1, $versionIssues);
    }

    public function testTopologicalSortOrdersDependenciesBeforeDependentsAndDetectsCycles(): void
    {
        $resolver = new DependencyResolver('0.5.0', '8.3.0');

        $manifests = [
            'plugin-c' => $this->manifest('plugin-c', dependencies: ['plugin-b' => '^1.0']),
            'plugin-a' => $this->manifest('plugin-a'),
            'plugin-b' => $this->manifest('plugin-b', dependencies: ['plugin-a' => '^1.0']),
            'cycle-1' => $this->manifest('cycle-1', dependencies: ['cycle-2' => '^1.0']),
            'cycle-2' => $this->manifest('cycle-2', dependencies: ['cycle-1' => '^1.0']),
        ];

        $sorted = $resolver->topologicalSort($manifests);

        $this->assertSame(['plugin-a', 'plugin-b', 'plugin-c'], $sorted['ordered']);
        $this->assertSame(['cycle-1', 'cycle-2'], $sorted['cycle']);

        $cyclePath = $resolver->findCycleFor('cycle-1', $manifests);
        $this->assertSame(['cycle-1', 'cycle-2', 'cycle-1'], $cyclePath);
        $this->assertNull($resolver->findCycleFor('plugin-c', $manifests));

        $reverse = $resolver->reverseDependencies($manifests);
        $this->assertSame(['plugin-b'], $reverse['plugin-a']);
        $this->assertSame(['plugin-c'], $reverse['plugin-b']);
    }

    /**
     * @param list<string> $extensions
     * @param array<string, string> $dependencies
     */
    private function manifest(
        string $slug,
        string $version = '1.0.0',
        ?string $requiresPrime = null,
        ?string $requiresPhp = null,
        array $extensions = [],
        array $dependencies = [],
    ): PluginManifest {
        return new PluginManifest(
            slug: $slug,
            name: ucfirst($slug),
            description: 'Test',
            version: $version,
            authorName: 'Prime',
            authorUrl: null,
            license: 'MIT',
            namespace: 'Test\\',
            main: 'Test\\Plugin',
            requiresPrime: $requiresPrime,
            requiresPhp: $requiresPhp,
            requiredExtensions: $extensions,
            dependencies: $dependencies,
            icon: null,
            accent: 'violet',
            settingsUrl: null,
            hash: sha1($slug),
        );
    }
}
