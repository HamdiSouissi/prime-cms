<?php

declare(strict_types=1);

namespace Prime\Install;

use Prime\Kernel\Application;

/**
 * Evaluates PHP version, required/optional extensions, and writable paths for Step 2.
 */
final class RequirementsChecker
{
    public const MIN_PHP_VERSION = '8.2.0';

    /**
     * Required PHP extensions from `docs/CONTEXT.md` §5.
     *
     * @var list<string>
     */
    private const REQUIRED_EXTENSIONS = [
        'pdo_mysql',
        'mbstring',
        'json',
        'gd',
        'fileinfo',
        'openssl',
        'zip',
    ];

    /**
     * Optional PHP extensions (non-blocking warnings if absent).
     *
     * @var list<string>
     */
    private const OPTIONAL_EXTENSIONS = [
        'intl',
    ];

    /**
     * @var list<string>
     */
    private const REQUIRED_WRITABLE_DIRS = [
        'storage',
        'storage/cache',
        'storage/logs',
        'storage/sessions',
        'storage/tmp',
        'content/uploads',
    ];

    public function __construct(
        private readonly Application $app,
        private readonly EnvWriter $envWriter = new EnvWriter(),
    ) {
    }

    /**
     * Run all requirement checks and return a structured report.
     *
     * @param ?callable(string): bool $extensionLoader Optional override for unit testing
     * @return array{
     *   passed: bool,
     *   blocking_errors: int,
     *   warnings: int,
     *   checks: list<array{id: string, group: string, label: string, current: string, required: string, status: string, blocking: bool, fix: ?string}>
     * }
     */
    public function check(
        ?string $phpVersion = null,
        ?callable $extensionLoader = null,
    ): array {
        $version = $phpVersion ?? PHP_VERSION;
        $hasExtension = $extensionLoader ?? static fn (string $ext): bool => extension_loaded($ext);

        $checks = [];

        // 1. PHP version
        $phpOk = version_compare($version, self::MIN_PHP_VERSION, '>=');
        $checks[] = [
            'id' => 'php.version',
            'group' => 'php',
            'label' => 'Version de PHP',
            'current' => $version,
            'required' => '≥ ' . self::MIN_PHP_VERSION,
            'status' => $phpOk ? 'ok' : 'error',
            'blocking' => true,
            'fix' => $phpOk ? null : sprintf(
                'Mettez à jour PHP vers la version %s ou supérieure sur votre serveur (version actuelle : %s).',
                self::MIN_PHP_VERSION,
                $version,
            ),
        ];

        // 2. Required extensions
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $loaded = $hasExtension($ext);
            $checks[] = [
                'id' => 'ext.' . $ext,
                'group' => 'extensions',
                'label' => 'Extension PHP ' . $ext,
                'current' => $loaded ? 'Activée' : 'Absente',
                'required' => 'Requise',
                'status' => $loaded ? 'ok' : 'error',
                'blocking' => true,
                'fix' => $loaded ? null : sprintf(
                    'Installez et activez l\'extension PHP `%s` (par ex. `apt install php8.3-%s` ou via `php.ini`), puis redémarrez le serveur web.',
                    $ext,
                    $ext === 'pdo_mysql' ? 'mysql' : $ext,
                ),
            ];
        }

        // 3. Optional extensions
        foreach (self::OPTIONAL_EXTENSIONS as $ext) {
            $loaded = $hasExtension($ext);
            $checks[] = [
                'id' => 'ext.' . $ext,
                'group' => 'extensions',
                'label' => 'Extension PHP ' . $ext . ' (optionnelle)',
                'current' => $loaded ? 'Activée' : 'Absente',
                'required' => 'Recommandée',
                'status' => $loaded ? 'ok' : 'warning',
                'blocking' => false,
                'fix' => $loaded ? null : 'Activez `php-intl` pour améliorer le formatage localisé des dates et nombres (non bloquant).',
            ];
        }

        // 4. Writable directories
        foreach (self::REQUIRED_WRITABLE_DIRS as $relativePath) {
            $fullPath = $this->app->basePath($relativePath);
            $exists = is_dir($fullPath);
            $writable = $exists && is_writable($fullPath);

            $checks[] = [
                'id' => 'path.' . str_replace('/', '_', $relativePath),
                'group' => 'paths',
                'label' => 'Dossier ' . $relativePath . '/',
                'current' => $writable ? 'Accessible en écriture' : ($exists ? 'Lecture seule' : 'Dossier introuvable'),
                'required' => 'Écriture requise',
                'status' => $writable ? 'ok' : 'error',
                'blocking' => true,
                'fix' => $writable ? null : sprintf(
                    'Créez le dossier et donnez les droits d\'écriture au serveur web : `mkdir -p %s && chmod -R 775 %s`.',
                    $relativePath,
                    $relativePath,
                ),
            ];
        }

        // 5. `.env` / root directory writability (warning only because manual copy fallback exists)
        $envPath = $this->app->basePath('.env');
        $envWritable = $this->envWriter->isWritable($envPath);
        $checks[] = [
            'id' => 'path.env',
            'group' => 'paths',
            'label' => 'Configuration (.env / racine du projet)',
            'current' => $envWritable ? 'Accessible en écriture' : 'Lecture seule (création manuelle possible)',
            'required' => 'Recommandé',
            'status' => $envWritable ? 'ok' : 'warning',
            'blocking' => false,
            'fix' => $envWritable ? null : 'Donnez les droits d\'écriture au fichier `.env` ou au dossier racine ; sinon, l\'assistant vous affichera le contenu exact à copier manuellement.',
        ];

        $blockingErrors = 0;
        $warnings = 0;
        foreach ($checks as $check) {
            if ($check['status'] === 'error') {
                $blockingErrors++;
            } elseif ($check['status'] === 'warning') {
                $warnings++;
            }
        }

        return [
            'passed' => $blockingErrors === 0,
            'blocking_errors' => $blockingErrors,
            'warnings' => $warnings,
            'checks' => $checks,
        ];
    }
}
