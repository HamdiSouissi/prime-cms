<?php

declare(strict_types=1);

namespace Prime\Mail;

/**
 * Minimal mail transport contract implemented by `LogMailer` and `NativeMailer`
 * (SMTP transport will be added in Phase 9).
 */
interface MailerInterface
{
    public function send(Message $message): void;
}
