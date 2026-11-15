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
    public function __construct(
        private readonly Application $app,
        private readonly ?string $targetRoot = null,
    ) {
    }

    public function name(): string
    {
        return 'make:plugin';
    }

    public function description(): string
    {
        return 'Génère le squelette complet d\'un nouveau plugin dans content/plugins/<slug>/';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args);
        $slug = strtolower(trim((string) ($parsed['positionals'][0] ?? '')));

        if ($slug === '' || strlen($slug) < 3 || strlen($slug) > 64 || preg_match(ManifestValidator::SLUG_PATTERN, $slug) !== 1) {
            $output->error('Usage : php bin/prime make:plugin <slug> [--name="Mon Plugin"] [--author="Équipe"] [--namespace="MonPlugin\\"] [--license="proprietary"]');
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
                'prime' => '^0.5',
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
}
