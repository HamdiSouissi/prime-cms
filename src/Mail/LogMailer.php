<?php

declare(strict_types=1);

namespace Prime\Mail;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Development/default mail driver writing rendered messages into `storage/logs/mail-YYYY-MM-DD.log`.
 *
 * Note: This driver intentionally records complete e-mail bodies (including reset links)
 * in the dedicated `mail-*.log` file so local developers can test flows without SMTP.
 * Production deployments should switch `MAIL_DRIVER=native` (or SMTP in Phase 9).
 */
final class LogMailer implements MailerInterface
{
    public function __construct(
        private readonly string $logDirectory,
        private readonly string $defaultFromAddress = 'no-reply@prime-cms.local',
        private readonly string $defaultFromName = 'Prime CMS',
    ) {
    }

    public function send(Message $message): void
    {
        if (!is_dir($this->logDirectory) && !@mkdir($this->logDirectory, 0750, true) && !is_dir($this->logDirectory)) {
            throw new RuntimeException(sprintf('Impossible de créer le dossier de logs mail : %s', $this->logDirectory));
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $file = rtrim($this->logDirectory, '/') . '/mail-' . $now->format('Y-m-d') . '.log';

        $fromAddress = $message->fromAddress ?? $this->defaultFromAddress;
        $fromName = $message->fromName ?? $this->defaultFromName;

        $entry = str_repeat('=', 72) . "\n"
            . 'Date: ' . $now->format(DateTimeImmutable::ATOM) . "\n"
            . 'From: ' . $fromName . ' <' . $fromAddress . ">\n"
            . 'To: ' . $message->to . "\n"
            . 'Subject: ' . $message->subject . "\n"
            . str_repeat('-', 72) . "\n"
            . "[TEXT]\n" . trim($message->text) . "\n\n"
            . "[HTML]\n" . trim($message->html) . "\n"
            . str_repeat('=', 72) . "\n\n";

        @file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
    }
}
