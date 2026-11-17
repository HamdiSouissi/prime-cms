<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Kernel\Container;
use Prime\Mail\LogMailer;
use Prime\Mail\Mailer;
use Prime\Mail\NativeMailer;
use Prime\Mail\SmtpTransport;

/**
 * `bin/prime mail:test` — checks what the mail configuration would do, without guessing.
 *
 * Without `--to` nothing is delivered: an SMTP transport opens the socket, shakes hands, authenticates
 * and hangs up, which answers "are these settings usable?" without needing an inbox. With `--to` a real
 * message goes out, because a server that accepts AUTH but refuses to relay is a different problem.
 */
final class MailTestCommand implements CommandInterface
{
    public function __construct(private readonly Container $container)
    {
    }

    public function name(): string
    {
        return 'mail:test';
    }

    public function description(): string
    {
        return 'Vérifie la configuration d’envoi (`mail:test [--to=adresse] [--json]`)';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args, ['json']);
        $options = $parsed['options'];
        $asJson = ArgParser::hasFlag($options, 'json');
        $mailer = $this->container->get(Mailer::class);

        if (!$mailer instanceof Mailer) {
            $output->error('Service d\'envoi indisponible : le conteneur ne répond pas.');

            return self::FAILURE;
        }

        $config = $mailer->config();
        $recipient = (string) ($options['to'] ?? '');

        $report = ['driver' => $config->driver(), 'transport' => $mailer->transportName()];

        if ($recipient !== '') {
            $result = $mailer->test($recipient);
            $report['sent_to'] = $recipient;
            $report['ok'] = $result['ok'];
            $report['message'] = $result['message'];
        } else {
            $report += $this->probe($mailer);
        }

        if ($asJson) {
            $output->writeln((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $report['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $rows = [
            ['Pilote', $config->driver()],
            ['Expéditeur', sprintf('%s <%s>', $config->fromName(), $config->fromAddress())],
            ['Serveur', $config->usesSmtp() ? sprintf('%s:%d (%s)', $config->host(), $config->port(), $config->encryption() !== '' ? $config->encryption() : 'clair') : '—'],
            ['Identifiant', $config->username() !== '' ? $config->username() : 'aucun'],
            ['Transport', $mailer->transportName()],
            ['Journal', $config->logEnabled() ? sprintf('activé, %d jours', $config->logDays()) : 'désactivé'],
        ];

        foreach ($rows as $row) {
            $output->writeln(sprintf('  %-12s %s', $row[0], (string) $row[1]));
        }

        $output->writeln('');
        $output->writeln(($report['ok'] ? '[OK] ' : '[ÉCHEC] ') . $report['message']);

        $capabilities = $report['capabilities'] ?? [];

        if ($capabilities !== []) {
            $output->writeln('  Capacités : ' . implode(', ', array_map('strval', $capabilities)));
        }

        if (!$report['ok'] && $recipient === '') {
            $output->writeln('  (aucun message envoyé — cette vérification ne contacte que le serveur)');
        }

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{ok: bool, message: string, capabilities?: list<string>, auth?: string, tls?: bool}
     */
    private function probe(Mailer $mailer): array
    {
        $transport = $mailer->transport();

        if ($transport instanceof SmtpTransport) {
            $report = $transport->verify();

            return [
                'ok' => $report['ok'],
                'message' => $report['message'],
                'capabilities' => $report['capabilities'],
                'auth' => $report['auth'],
                'tls' => $report['tls'],
            ];
        }

        if ($transport instanceof NativeMailer) {
            return [
                'ok' => function_exists('mail'),
                'message' => function_exists('mail')
                    ? 'La fonction mail() est disponible ; le transfert dépend du MTA local.'
                    : 'La fonction mail() de PHP est désactivée sur ce serveur.',
            ];
        }

        if ($transport instanceof LogMailer) {
            return ['ok' => true, 'message' => 'Le pilote « log » écrit dans storage/logs/mail-*.log — aucun envoi réel.'];
        }

        return ['ok' => true, 'message' => 'Transport prêt (' . $mailer->transportName() . ').'];
    }
}
