<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Kernel\Application;
use Prime\Plugins\ManifestValidator;

/**
 * Scaffolds a ready-to-activate plugin skeleton inside `content/plugins/<slug>/`.
 */
final class MakePluginCommand implements CommandInterface
{
    /** The plugin copied by default: every extension point, already tested. */
    public const STARTER = 'prime-starter';

    public function __construct(
        private readonly Application $app,
        private readonly ?string $targetRoot = null,
        private readonly ?string $starterPath = null,
    ) {
    }

    public function name(): string
    {
        return 'make:plugin';
    }

    public function description(): string
    {
        return 'Crée un plugin dans content/plugins/<slug>/ à partir de prime-starter (--minimal : squelette vide)';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args);
        $slug = strtolower(trim((string) ($parsed['positionals'][0] ?? '')));

        if ($slug === '' || strlen($slug) < 3 || strlen($slug) > 64 || preg_match(ManifestValidator::SLUG_PATTERN, $slug) !== 1) {
            $output->error('Usage : php bin/prime make:plugin <slug> [--name="Mon Plugin"] [--author="Équipe"] [--namespace="MonPlugin\\"] [--license="proprietary"] [--minimal]');
            $output->error('Le slug doit contenir 3 à 64 caractères minuscules, chiffres et tirets (ex. "mon-plugin").');

            return self::FAILURE;
        }

        $root = $this->targetRoot ?? $this->app->basePath((string) $this->app->config()->get('plugins.path', 'content/plugins'));
        $pluginDir = rtrim($root, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . $slug;

        if (is_dir($pluginDir)) {
            $output->error(sprintf('Le dossier "%s" existe déjà.', $pluginDir));

            return self::FAILURE;
        }

        $defaultName = implode(' ', array_map('ucfirst', explode('-', $slug)));
        $displayName = isset($parsed['options']['name']) && is_string($parsed['options']['name']) && trim($parsed['options']['name']) !== ''
            ? trim($parsed['options']['name'])
            : $defaultName;

        $author = isset($parsed['options']['author']) && is_string($parsed['options']['author']) && trim($parsed['options']['author']) !== ''
            ? trim($parsed['options']['author'])
            : 'Prime CMS Developer';

        $license = isset($parsed['options']['license']) && is_string($parsed['options']['license']) && trim($parsed['options']['license']) !== ''
            ? trim($parsed['options']['license'])
            : 'proprietary';

        $defaultNamespace = implode('', array_map('ucfirst', explode('-', $slug))) . '\\';
        $namespace = isset($parsed['options']['namespace']) && is_string($parsed['options']['namespace']) && trim($parsed['options']['namespace']) !== ''
            ? rtrim(trim($parsed['options']['namespace']), '\\') . '\\'
            : $defaultNamespace;

        if (str_starts_with($namespace, 'Prime\\')) {
            $output->error('Le namespace ne peut pas commencer par "Prime\\".');

            return self::FAILURE;
        }

        $starter = $this->starterPath ?? $this->app->basePath((string) $this->app->config()->get('plugins.path', 'content/plugins')) . DIRECTORY_SEPARATOR . self::STARTER;
        $minimal = ($parsed['options']['minimal'] ?? false) !== false;
        if (!$minimal) {
            if (is_file($starter . DIRECTORY_SEPARATOR . 'plugin.json')) {
                return $this->copyStarter($starter, $pluginDir, $slug, $displayName, $author, $license, $namespace, $output);
            }
            $output->writeln(sprintf('Avertissement : le plugin de départ « %s » est introuvable, génération du squelette minimal.', self::STARTER));
        }

        $directories = [
            $pluginDir,
            $pluginDir . '/src',
            $pluginDir . '/migrations',
            $pluginDir . '/views',
            $pluginDir . '/assets',
        ];

        foreach ($directories as $dir) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                $output->error(sprintf('Impossible de créer le répertoire "%s".', $dir));

                return self::FAILURE;
            }
        }

        $manifest = [
            'slug' => $slug,
            'name' => $displayName,
            'description' => sprintf('Plugin %s pour Prime CMS.', $displayName),
            'version' => '1.0.0',
            'author' => [
                'name' => $author,
            ],
            'license' => $license,
            'namespace' => $namespace,
            'main' => $namespace . 'Plugin',
            'requires' => [
                'prime' => '>=' . \Prime\Kernel\Application::VERSION,
                'php' => '^8.2',
            ],
            'accent' => 'violet',
        ];

        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($manifestJson)) {
            $output->error('Impossible de sérialiser plugin.json.');

            return self::FAILURE;
        }

        $phpNamespace = rtrim($namespace, '\\');
        $pluginClass = <<<PHP
<?php

declare(strict_types=1);

namespace {$phpNamespace};

use Prime\Plugins\AbstractPlugin;
use Prime\Plugins\PluginContext;

final class Plugin extends AbstractPlugin
{
    public function register(PluginContext \$context): void
    {
        // Enregistrez vos routes, hooks, menus, capacités et réglages ici.
    }

    public function boot(PluginContext \$context): void
    {
        // Exécuté une fois tous les plugins actifs enregistrés.
    }

    public function activate(PluginContext \$context): void
    {
        \$context->logger()->info('Plugin {$slug} activé.');
    }

    public function deactivate(PluginContext \$context): void
    {
        \$context->logger()->info('Plugin {$slug} désactivé.');
    }

    public function uninstall(PluginContext \$context): void
    {
        \$context->logger()->info('Plugin {$slug} désinstallé.');
    }
}

PHP;

        $readme = <<<MD
# {$displayName}

Plugin généré par `php bin/prime make:plugin {$slug}`.

## Activation

```bash
php bin/prime plugin:activate {$slug}
```
MD;

        file_put_contents($pluginDir . '/plugin.json', $manifestJson . PHP_EOL);
        file_put_contents($pluginDir . '/src/Plugin.php', $pluginClass);
        file_put_contents($pluginDir . '/README.md', $readme . PHP_EOL);

        $output->writeln(sprintf('Plugin "%s" généré dans : %s', $slug, $pluginDir));
        $output->writeln(sprintf('Activez-le avec : php bin/prime plugin:activate %s', $slug));

        return self::SUCCESS;
    }

    /**
     * Copy `prime-starter` under a new identity: slug, snake-case prefix (capability, tables),
     * PHP namespace and display name are rewritten in every file and file name. The result is a
     * working plugin with its own tests, ready to be trimmed down.
     */
    private function copyStarter(string $starter, string $pluginDir, string $slug, string $displayName, string $author, string $license, string $namespace, ConsoleOutput $output): int
    {
        $phpNamespace = rtrim($namespace, '\\');
        $studly = str_replace('\\', '', $phpNamespace);
        $replacements = [
            'PrimeStarterTest' => $studly . 'Test',
            'PrimeStarter' => $phpNamespace,
            'Prime Starter' => $displayName,
            'prime-starter' => $slug,
            'prime_starter' => str_replace('-', '_', $slug),
        ];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($starter, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->isLink()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($starter) + 1);
            $target = $pluginDir . DIRECTORY_SEPARATOR . strtr($relative, $replacements);

            if ($file->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                    $output->error(sprintf('Impossible de créer le répertoire "%s".', $target));

                    return self::FAILURE;
                }
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            if ($relative === 'plugin.json') {
                $contents = $this->manifestFromStarter($contents, $slug, $displayName, $author, $license, $namespace);
            } else {
                $contents = strtr($contents, $replacements);
            }

            $dir = dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                $output->error(sprintf('Impossible de créer le répertoire "%s".', $dir));

                return self::FAILURE;
            }
            file_put_contents($target, $contents);
        }

        $output->writeln(sprintf('Plugin "%s" créé à partir de %s dans : %s', $slug, self::STARTER, $pluginDir));
        $output->writeln(sprintf('Activez-le avec : php bin/prime plugin:activate %s', $slug));
        $output->writeln('Ses tests : vendor/bin/phpunit ' . $pluginDir . '/tests');

        return self::SUCCESS;
    }

    private function manifestFromStarter(string $json, string $slug, string $displayName, string $author, string $license, string $namespace): string
    {
        $manifest = json_decode($json, true);
        $manifest = is_array($manifest) ? $manifest : [];
        $manifest['slug'] = $slug;
        $manifest['name'] = $displayName;
        $manifest['description'] = 'plugin::' . $slug . '.manifest.description';
        $manifest['version'] = '0.1.0';
        $manifest['author'] = ['name' => $author];
        $manifest['license'] = $license;
        $manifest['namespace'] = $namespace;
        $manifest['main'] = $namespace . 'Plugin';
        $manifest['requires'] = ['prime' => '>=' . \Prime\Kernel\Application::VERSION, 'php' => '^8.2'];
        $manifest['settings_url'] = '/admin/' . $slug;

        return json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
}
