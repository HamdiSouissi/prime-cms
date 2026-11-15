<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Database\Connection;
use Throwable;

/**
 * Tests database connectivity and displays server version without exposing credentials.
 */
final class DbCheckCommand implements CommandInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function name(): string
    {
        return 'db:check';
    }

    public function description(): string
    {
        return 'Teste la connexion à la base de données et affiche la version du serveur';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        try {
            $version = $this->connection->serverVersion();
        } catch (Throwable $exception) {
            $output->error('Échec de la connexion à la base de données : ' . $exception->getMessage());

            return self::FAILURE;
        }

        $output->writeln('Connexion à la base de données réussie.');
        $output->writeln(sprintf('  Serveur   : %s:%d', $this->connection->host(), $this->connection->port()));
        $output->writeln(sprintf('  Base      : %s', $this->connection->database()));
        $output->writeln(sprintf('  Préfixe   : %s', $this->connection->prefix()));
        $output->writeln(sprintf('  Version   : %s', $version !== '' ? $version : 'inconnue'));
        $output->writeln(sprintf('  Encodage  : %s (%s)', $this->connection->charset(), $this->connection->collation()));

        return self::SUCCESS;
    }
}
