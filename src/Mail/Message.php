<?php

declare(strict_types=1);

namespace Prime\Mail;

/**
 * Immutable e-mail message value object carrying both HTML and plain-text bodies.
 */
final class Message
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $html,
        public readonly string $text,
        public readonly ?string $fromAddress = null,
        public readonly ?string $fromName = null,
        public readonly ?string $replyTo = null,
    ) {
    }
}
