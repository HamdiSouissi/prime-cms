<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Plugins\PluginManager;

/**
 * Lists discovered plugins along with their version, status, dependencies, and errors.
 */
final class PluginListCommand implements CommandInterface
{
    public function __construct(private readonly PluginManager $manager)
    {
    }

    public function name(): string
    {
        return 'plugin:list';
    }

    public function description(): string
    {
        return 'Liste les plugins installés, leur version, statut et erreurs éventuelles';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args);
        $statusFilter = isset($parsed['options']['status']) && is_string($parsed['options']['status'])
            ? strtolower(trim($parsed['options']['status']))
            : null;
        $asJson = isset($parsed['options']['json']) && $parsed['options']['json'] !== false;

        $plugins = $this->manager->discover(fresh: true);

        if ($statusFilter !== null && $statusFilter !== '' && $statusFilter !== 'all') {
            $plugins = array_filter(
                $plugins,
                static fn ($info): bool => $info->status === $statusFilter,
            );
        }

        if ($asJson) {
            $payload = [];
            foreach ($plugins as $info) {
                $payload[] = [
                    'slug' => $info->slug,
                    'name' => $info->name(),
                    'version' => $info->version(),
                    'status' => $info->status,
                    'valid' => $info->isValid(),
                    'errors' => $info->allIssues(),
                    'last_error' => $info->lastError,
                ];
            }
            $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $output->writeln(is_string($encoded) ? $encoded : '[]');

            return self::SUCCESS;
        }

        if ($this->manager->isSafeMode()) {
            $output->writeln('[ATTENTION] PRIME_SAFE_MODE est actif : aucun plugin n\'est chargé.');
        }

        if ($plugins === []) {
            $output->writeln('Aucun plugin trouvé.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($plugins as $info) {
            $issuesCount = count($info->allIssues());
            $note = '';
            if ($info->isErrored() && $info->lastError !== null) {
                $note = mb_substr($info->lastError, 0, 60);
            } elseif ($issuesCount > 0) {
                $note = sprintf('%d problème(s)', $issuesCount);
            } elseif ($info->needsUpdate && $info->installedVersion !== null) {
                $note = sprintf('Maj %s -> %s', $info->installedVersion, $info->version());
            }

            $rows[] = [
                $info->slug,
                $info->name(),
                $info->version(),
                $info->status,
                $note,
            ];
        }

        $output->table(['Slug', 'Nom', 'Version', 'Statut', 'Remarques'], $rows);

        return self::SUCCESS;
    }
}
