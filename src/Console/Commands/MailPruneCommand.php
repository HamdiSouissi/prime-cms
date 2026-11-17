<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Mail\MailLogRepository;

/**
 * `bin/prime mail:prune` — apply the retention rule of `mail.log_days`.
 *
 * `pc_mail_log` holds a row per attempt, so on a busy site it is the table that grows fastest. The
 * scheduler does this on its own; the command exists for the day an administrator wants the answer now.
 */
final class MailPruneCommand implements CommandInterface
{
    public function __construct(private readonly MailLogRepository $logs, private readonly int $defaultDays = 30)
    {
    }

    public function name(): string
    {
        return 'mail:prune';
    }

    public function description(): string
    {
        return 'Supprime les e-mails enregistrés trop anciens (`mail:prune [--days=30] [--dry-run]`)';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args, ['days', 'dry-run']);
        $options = $parsed['options'];
        $days = max(1, min(365, (int) ($options['days'] ?? $this->defaultDays)));
        $total = $this->logs->count();

        if (ArgParser::hasFlag($options, 'dry-run')) {
            $output->writeln(sprintf('%d e-mail(s) au total ; la purge conservée à %d jour(s) en supprimerait les plus anciens.', $total, $days));

            return self::SUCCESS;
        }

        $deleted = $this->logs->prune($days);
        $output->writeln($deleted === 0
            ? sprintf('Rien à supprimer : %d e-mail(s) dans la limite de %d jour(s).', $total, $days)
            : sprintf('%d e-mail(s) supprimé(s) sur %d.', $deleted, $total));

        return self::SUCCESS;
    }
}
