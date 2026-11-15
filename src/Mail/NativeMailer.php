<?php

declare(strict_types=1);

namespace Prime\Mail;

use RuntimeException;

/**
 * Native PHP `mail()` transport sending UTF-8 `multipart/alternative` messages
 * (plain text + HTML) with header injection protection.
 */
final class NativeMailer implements MailerInterface
{
    public function __construct(
        private readonly string $defaultFromAddress = 'no-reply@prime-cms.local',
        private readonly string $defaultFromName = 'Prime CMS',
    ) {
    }

    public function send(Message $message): void
    {
        $to = $this->sanitizeHeader($message->to);
        $subject = $this->encodeHeader($this->sanitizeHeader($message->subject));
        $fromAddress = $this->sanitizeHeader($message->fromAddress ?? $this->defaultFromAddress);
        $fromName = $this->encodeHeader($this->sanitizeHeader($message->fromName ?? $this->defaultFromName));

        $boundary = 'prime_' . bin2hex(random_bytes(16));

        $headers = [
            'MIME-Version: 1.0',
            sprintf('From: %s <%s>', $fromName, $fromAddress),
            sprintf('Content-Type: multipart/alternative; boundary="%s"', $boundary),
        ];

        if ($message->replyTo !== null && $message->replyTo !== '') {
            $headers[] = 'Reply-To: ' . $this->sanitizeHeader($message->replyTo);
        }

        $body = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $message->text . "\r\n\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $message->html . "\r\n\r\n"
            . "--{$boundary}--\r\n";

        $sent = @mail($to, $subject, $body, implode("\r\n", $headers));
        if (!$sent) {
            throw new RuntimeException('Échec de l\'envoi de l\'e-mail via la fonction PHP mail().');
        }
    }

    private function sanitizeHeader(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\0"], '', $value));
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value) !== 1) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
