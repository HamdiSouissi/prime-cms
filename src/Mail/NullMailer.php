<?php

declare(strict_types=1);

namespace Prime\Mail;

/**
 * `null` transport: accepts every message and sends nothing (staging copies, load tests).
 *
 * Unlike `log`, nothing is written to disk; the e-mail log still records the attempt.
 */
final class NullMailer implements MailerInterface
{
    public function send(Message $message): void
    {
    }
}
