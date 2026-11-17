<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Mail\MailLogRepository;

/**
 * `bin/prime mail:logs` — the delivery history, straight from `pc_mail_log`.
 *
 * A host without access to the mail queue needs this to answer "did the password e-mail go out?": the
 * row carries the server's own error message, which is the only diagnosis a shared host leaves behind.
 */
final class MailLogsCommand implements CommandInterface
{
    public function __construct(private readonly MailLogRepository $logs)
    {
    }

    public function name(): string
    {
        return 'mail:logs';
    }

    public function description(): string
    {
        return 'Affiche les derniers e-mails enregistrés (`mail:logs [--limit=25] [--status=failed] [--json]`)';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args, ['json']);
        $options = $parsed['options'];
        $status = isset($options['status']) ? (string) $options['status'] : null;
        $limit = max(1, min(200, (int) ($options['limit'] ?? 25)));
        $rows = $this->logs->recent($limit, 0, $status);

        if (ArgParser::hasFlag($options, 'json')) {
            $output->writeln((string) json_encode(['stats' => $this->logs->stats(), 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $output->writeln($status === null ? 'Aucun e-mail enregistré pour le moment.' : sprintf('Aucun e-mail avec le statut « %s ».', $status));

            return self::SUCCESS;
        }

        $table = [];

        foreach ($rows as $row) {
            $table[] = [
                (string) $row['id'],
                substr((string) $row['created_at'], 0, 16),
                (string) $row['recipient'],
                mb_strimwidth((string) $row['subject'], 0, 34, '…', 'UTF-8'),
                (string) $row['status'],
                (string) $row['transport'],
                (string) ($row['template'] ?? ''),
                (string) $row['attempts'],
                mb_strimwidth((string) ($row['error_message'] ?? ''), 0, 48, '…', 'UTF-8'),
            ];
        }

        $output->table(['#', 'Date (UTC)', 'Destinataire', 'Sujet', 'État', 'Transport', 'Gabarit', 'Essais', 'Erreur'], $table);

        $stats = $this->logs->stats();
        $output->writeln(sprintf(
            '%d e-mail(s) — %d envoyé(s), %d en file, %d en échec.',
            $stats['total'],
            $stats['sent'],
            $stats['queued'],
            $stats['failed'],
        ));

        return self::SUCCESS;
    }
}
