<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Install\Exception\InstallException;
use Prime\Install\InstallConfig;
use Prime\Install\Installer;
use Prime\Kernel\Config;
use Throwable;

/**
 * Non-interactive CLI installer delegating directly to `Prime\Install\Installer`.
 */
final class InstallCommand implements CommandInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Installer $installer,
    ) {
    }

    public function name(): string
    {
        return 'install';
    }

    public function description(): string
    {
        return 'Installe Prime CMS en ligne de commande (non interactif)';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args, ['create-db', 'skip-env', 'app-debug']);
        $opts = $parsed['options'];

        $dbHost = ArgParser::stringOption($opts, 'db-host', (string) $this->config->get('database.host', '127.0.0.1'));
        $dbPortRaw = ArgParser::stringOption($opts, 'db-port', (string) $this->config->get('database.port', 3306));
        $dbName = ArgParser::stringOption($opts, 'db-name', (string) $this->config->get('database.database', 'prime_cms'));
        $dbUser = ArgParser::stringOption($opts, 'db-user', (string) $this->config->get('database.username', 'prime'));
        $dbPassword = ArgParser::stringOption($opts, 'db-password', (string) $this->config->get('database.password', ''));
        $dbPrefix = ArgParser::stringOption($opts, 'db-prefix', (string) $this->config->get('database.prefix', 'pc_'));

        $siteName = ArgParser::stringOption($opts, 'site-name', (string) $this->config->get('app.name', 'Prime CMS'));
        $siteUrl = ArgParser::stringOption($opts, 'site-url', (string) $this->config->get('app.url', 'http://localhost:8080'));
        $siteLocale = ArgParser::stringOption($opts, 'site-locale', 'fr');
        $timezone = ArgParser::stringOption($opts, 'timezone', 'Europe/Paris');

        $adminName = ArgParser::stringOption($opts, 'admin-name', 'Administrateur');
        $adminEmail = ArgParser::stringOption($opts, 'admin-email', '');
        $adminPassword = ArgParser::stringOption($opts, 'admin-password', '');

        $appEnv = ArgParser::stringOption($opts, 'app-env', 'production');
        $appDebug = ArgParser::hasFlag($opts, 'app-debug');
        $createDb = ArgParser::hasFlag($opts, 'create-db');
        $skipEnv = ArgParser::hasFlag($opts, 'skip-env');

        if ($adminEmail === '' || $adminPassword === '') {
            $output->error('Les options --admin-email et --admin-password sont obligatoires.');
            $output->error('Usage : php bin/prime install --db-host=db --db-name=prime_cms --db-user=prime --db-password=... --site-name="Mon Site" --site-url=http://localhost:8080 --admin-email=admin@example.com --admin-password=...');

            return self::FAILURE;
        }

        if (!ctype_digit($dbPortRaw)) {
            $output->error('L\'option --db-port doit être un numéro de port valide.');

            return self::FAILURE;
        }

        $installConfig = new InstallConfig(
            dbHost: $dbHost,
            dbPort: (int) $dbPortRaw,
            dbDatabase: $dbName,
            dbUsername: $dbUser,
            dbPassword: $dbPassword,
            dbPrefix: $dbPrefix,
            siteName: $siteName,
            siteUrl: $siteUrl,
            siteLocale: $siteLocale,
            timezone: $timezone,
            adminDisplayName: $adminName,
            adminEmail: $adminEmail,
            adminPassword: $adminPassword,
            appEnv: $appEnv,
            appDebug: $appDebug,
            createDatabase: $createDb,
            skipEnvWrite: $skipEnv,
            allowManualEnv: false,
        );

        try {
            $result = $this->installer->install($installConfig);
        } catch (InstallException $exception) {
            $output->error(sprintf('Échec de l\'installation [étape : %s] : %s', $exception->step(), $exception->getMessage()));

            return self::FAILURE;
        } catch (Throwable $exception) {
            $output->error('Erreur inattendue lors de l\'installation : ' . $exception->getMessage());

            return self::FAILURE;
        }

        $output->writeln('Prime CMS installé avec succès.');
        $output->writeln(sprintf('  Site        : %s (%s)', $siteName, rtrim($siteUrl, '/')));
        $output->writeln(sprintf('  Admin       : %s <%s>', $adminName, strtolower($adminEmail)));
        $output->writeln(sprintf('  Migrations  : %d exécutée(s)', count($result->migrations)));
        $output->writeln(sprintf('  Config .env : %s', $result->envWritten ? 'écrite' : 'ignorée / existante'));
        $output->writeln(sprintf('  Installé le : %s', $result->installedAt));

        return self::SUCCESS;
    }
}
