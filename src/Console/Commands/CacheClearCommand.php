<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Cache\CacheManager;
use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;

/**
 * Flush the caches from the command line (`bin/prime cache:clear`).
 *
 * Without flags both caches are cleared. `--pages` only drops the public page cache,
 * `--data` only the data cache (settings, menus, translation catalogues, …). Expired
 * entries can be removed alone with `--prune`.
 */
final class CacheClearCommand implements CommandInterface
{
    public function __construct(private readonly CacheManager $cache)
    {
    }

    public function name(): string
    {
        return 'cache:clear';
    }

    public function description(): string
    {
        return 'Vide le cache de données et/ou de pages (--pages, --data, --prune)';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args, ['pages', 'data', 'prune']);

        $pages = ArgParser::hasFlag($parsed['options'], 'pages');
        $data = ArgParser::hasFlag($parsed['options'], 'data');
        $prune = ArgParser::hasFlag($parsed['options'], 'prune');

        if ($prune) {
            $pruned = $this->cache->pruneExpired();
            $output->writeln(sprintf(
                'Entrées expirées supprimées : %d (données), %d (pages).',
                $pruned['data'],
                $pruned['pages'],
            ));

            return self::SUCCESS;
        }

        // No scope flag means "everything", which matches what the admin button does.
        $clearPages = $pages || !$data;
        $clearData = $data || !$pages;

        if ($clearData) {
            $this->cache->clearData();
            $output->writeln(sprintf('Cache de données vidé (pilote : %s).', $this->cache->driverName()));
        }

        if ($clearPages) {
            $purged = $this->cache->clearPages();
            $output->writeln(sprintf('Cache de pages vidé (%d page(s) supprimée(s)).', $purged));
        }

        return self::SUCCESS;
    }
}
