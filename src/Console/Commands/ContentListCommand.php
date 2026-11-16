<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Content\EntryQuery;
use Throwable;

/**
 * Lists content entries in a formatted CLI table (`bin/prime content:list`).
 */
final class ContentListCommand implements CommandInterface
{
    public function __construct(private readonly EntryQuery $query)
    {
    }

    public function name(): string
    {
        return 'content:list';
    }

    public function description(): string
    {
        return 'Liste les contenus (--type, --status, --search, --limit)';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args);
        $opts = $parsed['options'];

        $type = ArgParser::stringOption($opts, 'type');
        $status = ArgParser::stringOption($opts, 'status');
        $search = ArgParser::stringOption($opts, 'search');
        $limitRaw = ArgParser::stringOption($opts, 'limit');
        $limit = $limitRaw !== '' && ctype_digit($limitRaw) && (int) $limitRaw > 0 ? min(500, (int) $limitRaw) : 50;

        try {
            $q = $this->query->newQuery()->includeFuture(true)->orderBy('updated_at', 'DESC')->limit($limit);

            if ($type !== '') {
                $q = $q->type($type);
            }
            if ($status !== '') {
                $q = $q->status($status);
            } else {
                $q = $q->anyStatus(includeTrash: true);
            }
            if ($search !== '') {
                $q = $q->search($search);
            }

            $entries = $q->get();
        } catch (Throwable $exception) {
            $output->error('Impossible de lister les contenus : ' . $exception->getMessage());

            return self::FAILURE;
        }

        if ($entries === []) {
            $output->writeln('Aucun contenu trouvé.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = [
                (string) $entry->id,
                $entry->type,
                $entry->status,
                $entry->title,
                $entry->slug,
                $entry->publishedAt ?? '-',
                $entry->updatedAt,
            ];
        }

        $output->table(['ID', 'Type', 'Statut', 'Titre', 'Slug', 'Publication (UTC)', 'Mise à jour (UTC)'], $rows);
        $output->writeln(sprintf('Total affiché : %d contenu(s).', count($entries)));

        return self::SUCCESS;
    }
}
