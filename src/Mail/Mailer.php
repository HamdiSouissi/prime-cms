<?php

declare(strict_types=1);

namespace Prime\Mail;

use Prime\Hooks\Hooks;
use Prime\Jobs\JobQueue;
use Prime\Log\Logger;
use Prime\View\View;
use Throwable;

/**
 * The mailer every caller uses: `PasswordResetService`, the contact form, `mail:test`, plugins.
 *
 * A transport only knows how to push bytes; the mailer is where the decisions live — queue or deliver
 * now, which filters run, what lands in `pc_mail_log`, and what the administrator sees when nothing
 * works. Those decisions used to be spread over four call sites, which is how a password-reset mail
 * ended up bypassing the log once.
 */
final class Mailer implements MailerInterface
{
    public function __construct(
        private readonly MailConfig $config,
        private readonly MailerInterface $transport,
        private readonly ?MailLogRepository $logs = null,
        private readonly ?Hooks $hooks = null,
        private readonly ?JobQueue $queue = null,
        private readonly ?View $view = null,
        private readonly ?Logger $logger = null,
    ) {
    }

    public function config(): MailConfig
    {
        return $this->config;
    }

    public function transport(): MailerInterface
    {
        return $this->transport;
    }

    /**
     * Name of the active transport, as it appears in the log and on the settings screen.
     */
    public function transportName(): string
    {
        return match (true) {
            $this->transport instanceof SmtpTransport => 'smtp',
            $this->transport instanceof NativeMailer => 'native',
            $this->transport instanceof LogMailer => 'log',
            default => 'mail',
        };
    }

    public function send(Message $message): void
    {
        $this->dispatch($message);
    }

    /**
     * Render a `mail::` template and send it, queueing when the driver says so.
     *
     * @param array<string, mixed> $data
     */
    public function sendTemplate(string $template, string $to, string $subject, array $data = []): void
    {
        $this->dispatch($this->render($template, $to, $subject, $data), $template, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, string $to, string $subject, array $data = []): Message
    {
        $view = 'mail::' . $template;
        $siteName = $this->config->fromName();
        // The caller wins: a plugin rendering the same template for another brand must be able to say so.
        $payload = $data + ['site_name' => $siteName, 'subject' => $subject];

        if ($this->view !== null && $this->view->exists($view)) {
            $html = $this->view->render($view, $payload);
            $text = MimeBuilder::plainTextFromHtml($html);

            return new Message(
                to: $to,
                subject: $subject,
                html: $html,
                text: $text,
            );
        }

        return new Message(to: $to, subject: $subject, text: is_string($data['body'] ?? null) ? $data['body'] : $subject);
    }

    public function hasTemplate(string $template): bool
    {
        return $this->view !== null && $this->view->exists('mail::' . $template);
    }

    /**
     * Hand the message to the queue when the driver is `queue`, deliver it otherwise.
     *
     * @param array<string, mixed> $data
     */
    public function dispatch(Message $message, ?string $template = null, array $data = [], ?int $jobId = null): void
    {
        $message = $this->applyFilters($message, $template);

        if ($this->config->usesQueue() && $this->queue !== null && $jobId === null) {
            try {
                $id = $this->queue->dispatch(SendMailJob::NAME, self::payload($message, $template, $data));
                $this->log($message, MailLogRepository::STATUS_QUEUED, 'queue', $template, $data, null, $id);

                return;
            } catch (Throwable) {
                // No handler registered (the queue is off, or a plugin unregistered it): send now
                // rather than lose the message. A silent failure here would be invisible forever.
            }
        }

        $this->deliver($message, $template, $data, $jobId);
    }

    /**
     * Deliver without consulting the queue — the path a worker takes, and what `mail:test` uses.
     *
     * @param array<string, mixed> $data
     */
    public function deliver(Message $message, ?string $template = null, array $data = [], ?int $jobId = null): void
    {
        $started = microtime(true);

        try {
            $this->transport->send($message);
        } catch (Throwable $exception) {
            $this->log($message, MailLogRepository::STATUS_FAILED, $this->transportName(), $template, $data, $exception->getMessage(), $jobId);
            $this->hooks?->emit('mail.failed', $message, $exception);

            throw $exception;
        }

        $logId = $this->log($message, MailLogRepository::STATUS_SENT, $this->transportName(), $template, $data, null, $jobId);
        $this->hooks?->emit('mail.sent', $message, $this->transportName(), $logId, (int) round((microtime(true) - $started) * 1000));
    }

    /**
     * Send a test message through the configured transport, reporting the failure instead of throwing:
     * the settings screen and `mail:test` both need the message, not a 500.
     *
     * @return array{ok: bool, message: string, log_id: ?int}
     */
    public function test(string $to, ?string $subject = null): array
    {
        $subject = $subject ?? sprintf('[%s] Test d’envoi', $this->config->fromName());
        $message = new Message(
            to: $to,
            subject: $subject,
            html: $this->testHtml($subject),
            text: "Ceci est un e-mail de test.\n\nSi vous le lisez, l’envoi d’e-mails de Prime CMS fonctionne.\n",
        );

        try {
            $this->deliver($message, 'test');
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => $exception->getMessage(), 'log_id' => null];
        }

        return [
            'ok' => true,
            'message' => sprintf('E-mail envoyé à %s via %s.', $to, $this->transportName()),
            'log_id' => $this->logs !== null ? $this->logs->count() : null,
        ];
    }

    /**
     * Configuration verdict for the admin screen and the health notice.
     *
     * @return array{driver: string, configured: bool, problem: ?string, transport: string, log_enabled: bool, stats: array<string, mixed>}
     */
    public function diagnose(): array
    {
        $problem = null;

        if (!$this->config->isSmtpConfigured()) {
            $problem = 'Le serveur SMTP est incomplet : hôte, port et identifiants sont nécessaires.';
        } elseif ($this->config->driver() === MailConfig::DRIVER_NATIVE && !function_exists('mail')) {
            $problem = 'La fonction mail() de PHP est désactivée sur ce serveur : choisissez une autre méthode d\'envoi.';
        } elseif ($this->config->usesSmtp() && !SmtpTransport::isAvailable()) {
            $problem = 'Les fonctions réseau de PHP sont désactivées : SMTP est indisponible sur ce serveur.';
        }

        return [
            'driver' => $this->config->driver(),
            'configured' => $problem === null,
            'problem' => $problem,
            'transport' => $this->transportName(),
            'log_enabled' => $this->config->logEnabled() && $this->logs !== null,
            'stats' => $this->logs?->stats() ?? ['total' => 0, 'sent' => 0, 'failed' => 0, 'queued' => 0, 'last_error' => null],
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function payload(Message $message, ?string $template = null, array $data = []): array
    {
        $payload = [
            'to' => $message->to,
            'subject' => $message->subject,
            'html' => $message->html,
            'text' => $message->text,
            'reply_to' => $message->replyTo,
            'cc' => $message->cc,
            'bcc' => $message->bcc,
            'from_address' => $message->fromAddress,
            'from_name' => $message->fromName,
        ];

        if ($template !== null) {
            $payload['template'] = $template;
            $payload['data'] = $data;
        }

        return $payload;
    }

    /**
     * Rebuild a message from a queued payload.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): Message
    {
        return new Message(
            to: (string) ($payload['to'] ?? ''),
            subject: (string) ($payload['subject'] ?? ''),
            html: (string) ($payload['html'] ?? ''),
            text: (string) ($payload['text'] ?? ''),
            fromAddress: isset($payload['from_address']) ? (string) $payload['from_address'] : null,
            fromName: isset($payload['from_name']) ? (string) $payload['from_name'] : null,
            replyTo: isset($payload['reply_to']) ? (string) $payload['reply_to'] : null,
            cc: array_values(array_filter((array) ($payload['cc'] ?? []), 'is_string')),
            bcc: array_values(array_filter((array) ($payload['bcc'] ?? []), 'is_string')),
        );
    }

    /**
     * `mail.message` lets a plugin rewrite recipients, inject a footer, or attach a signature — the
     * single extension point every message passes through, queue or not.
     *
     */
    private function applyFilters(Message $message, ?string $template): Message
    {
        if ($this->hooks === null) {
            return $message;
        }

        $payload = self::payload($message, $template, []);
        unset($payload['template'], $payload['data']);

        // A filter may hand back anything; anything but an array means the message is left alone.
        $filtered = $this->hooks->apply('mail.message', $payload, $template);
        if (!is_array($filtered)) {
            return $message;
        }

        $rebuilt = self::fromPayload($filtered + $payload);

        if ($rebuilt->to === '') {
            return $message;
        }

        return $rebuilt;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function log(
        Message $message,
        string $status,
        string $transport,
        ?string $template,
        array $data,
        ?string $error = null,
        ?int $jobId = null,
    ): ?int {
        if ($this->logs === null || !$this->config->logEnabled()) {
            return null;
        }

        try {
            return $this->logs->record(
                $message,
                $status,
                $transport,
                ['data' => $template !== null ? $data : [], 'message_id' => $message->messageId],
                $error,
                $template,
                $jobId,
            );
        } catch (Throwable $exception) {
            // A missing `pc_mail_log` table (a site mid-install) must not cost the message itself.
            $this->logger?->warning('Journal des e-mails indisponible', ['error' => $exception->getMessage()]);

            return null;
        }
    }

    private function testHtml(string $subject): string
    {
        return '<h1 style="font-size:20px;margin:0 0 12px;">' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</h1>'
            . '<p style="margin:0;">Ceci est un e-mail de test envoyé par Prime CMS. Si vous le lisez, la configuration d’envoi fonctionne.</p>';
    }
}
