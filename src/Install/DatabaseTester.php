<?php

declare(strict_types=1);

namespace Prime\Install;

use Prime\Database\Connection;
use Prime\Database\Exception\ConnectionException;
use Prime\Database\Identifier;
use Prime\Database\Schema\Schema;

/**
 * Tests database connectivity, diagnoses failures safely without exposing secrets,
 * detects existing tables with a conflicting prefix, and optionally creates a missing DB.
 */
final class DatabaseTester
{
    /**
     * Test a database configuration and return a safe French diagnostic result.
     *
     * @param array{host: string, port: int, database: string, username: string, password: string, prefix: string, charset?: string, collation?: string} $config
     * @return array{ok: bool, status: string, message: string, server_version: ?string, can_create_database: bool, existing_tables: list<string>}
     */
    public function test(array $config): array
    {
        $prefix = $config['prefix'];
        $database = $config['database'];

        $connection = new Connection($config, debug: false);

        try {
            $version = $connection->serverVersion();
        } catch (ConnectionException $exception) {
            return $this->diagnoseFailure($config, $exception);
        }

        $schema = new Schema($connection);
        $existingTables = $schema->tablesWithPrefix($prefix);

        if ($existingTables !== []) {
            $count = count($existingTables);

            return [
                'ok' => false,
                'status' => 'prefix_conflict',
                'message' => sprintf(
                    'Des tables avec le préfixe « %s » existent déjà dans la base « %s » (%d table%s). Changez le préfixe des tables pour éviter d\'écraser une installation existante.',
                    $prefix,
                    $database,
                    $count,
                    $count > 1 ? 's' : '',
                ),
                'server_version' => $version !== '' ? $version : null,
                'can_create_database' => false,
                'existing_tables' => $existingTables,
            ];
        }

        return [
            'ok' => true,
            'status' => 'ok',
            'message' => sprintf(
                'Connexion réussie à la base « %s »%s.',
                $database,
                $version !== '' ? ' (serveur ' . $version . ')' : '',
            ),
            'server_version' => $version !== '' ? $version : null,
            'can_create_database' => false,
            'existing_tables' => [],
        ];
    }

    /**
     * Create the requested database using utf8mb4 / utf8mb4_unicode_ci.
     *
     * @param array{host: string, port: int, database: string, username: string, password: string, prefix?: string, charset?: string, collation?: string} $config
     */
    public function createDatabase(array $config): void
    {
        $dbName = Identifier::validateDatabaseName($config['database']);
        $charset = Identifier::validateCharsetOrCollation((string) ($config['charset'] ?? 'utf8mb4'));
        $collation = Identifier::validateCharsetOrCollation((string) ($config['collation'] ?? 'utf8mb4_unicode_ci'));

        $serverConfig = $config;
        $serverConfig['database'] = '';

        $connection = new Connection($serverConfig, debug: false);
        $connection->statement(sprintf(
            'CREATE DATABASE IF NOT EXISTS %s CHARACTER SET %s COLLATE %s',
            Identifier::quoteSimple($dbName),
            $charset,
            $collation,
        ));
    }

    /**
     * @param array{host: string, port: int, database: string, username: string, password: string, prefix: string, charset?: string, collation?: string} $config
     * @return array{ok: bool, status: string, message: string, server_version: ?string, can_create_database: bool, existing_tables: list<string>}
     */
    private function diagnoseFailure(array $config, ConnectionException $exception): array
    {
        $code = $exception->driverCode();
        $sqlState = $exception->sqlState();

        // Unknown database (1049)
        if ($code === 1049) {
            $canCreate = $this->canCreateDatabase($config);

            return [
                'ok' => false,
                'status' => 'database_missing',
                'message' => $canCreate
                    ? sprintf('La base de données « %s » n\'existe pas encore. Vous pouvez la créer directement ou en choisir une autre.', $config['database'])
                    : sprintf('La base de données « %s » n\'existe pas encore et cet utilisateur ne semble pas autorisé à la créer. Créez-la d\'abord depuis votre panneau d\'hébergement.', $config['database']),
                'server_version' => null,
                'can_create_database' => $canCreate,
                'existing_tables' => [],
            ];
        }

        // Access denied (1045 / 1044)
        if ($code === 1045 || $code === 1044 || $sqlState === '28000') {
            return [
                'ok' => false,
                'status' => 'access_denied',
                'message' => 'Accès refusé par le serveur MySQL/MariaDB. Vérifiez le nom d\'utilisateur, le mot de passe et ses droits sur cette base.',
                'server_version' => null,
                'can_create_database' => false,
                'existing_tables' => [],
            ];
        }

        // Host unreachable / connection refused (2002, 2003, 2005, 2006)
        if (in_array($code, [2002, 2003, 2005, 2006], true)) {
            return [
                'ok' => false,
                'status' => 'host_unreachable',
                'message' => 'Serveur de base de données injoignable. Vérifiez l\'hôte, le port et assurez-vous que MySQL/MariaDB est bien démarré.',
                'server_version' => null,
                'can_create_database' => false,
                'existing_tables' => [],
            ];
        }

        return [
            'ok' => false,
            'status' => 'error',
            'message' => 'Impossible de se connecter à la base de données. Vérifiez les paramètres saisis puis réessayez.',
            'server_version' => null,
            'can_create_database' => false,
            'existing_tables' => [],
        ];
    }

    /**
     * Probe whether connecting without a database succeeds and whether the user holds CREATE privileges.
     *
     * @param array{host: string, port: int, database: string, username: string, password: string, prefix: string, charset?: string, collation?: string} $config
     */
    private function canCreateDatabase(array $config): bool
    {
        $serverConfig = $config;
        $serverConfig['database'] = '';

        try {
            $serverConnection = new Connection($serverConfig, debug: false);
            $grants = $serverConnection->select('SHOW GRANTS FOR CURRENT_USER()');

            foreach ($grants as $row) {
                $grantText = strtoupper((string) reset($row));
                if (str_contains($grantText, 'ALL PRIVILEGES') || preg_match('/\bCREATE\b/', $grantText) === 1) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable) {
            return false;
        }
    }
}
