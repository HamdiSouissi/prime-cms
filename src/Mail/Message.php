<?php

declare(strict_types=1);

namespace Prime\Mail;

/**
 * Immutable e-mail message value object carrying both HTML and plain-text bodies.
 */
final class Message
{
    /**
     * @param string           $to          one address, or several separated by a comma
     * @param list<Attachment> $attachments
     * @param list<string>     $cc
     * @param list<string>     $bcc
     */
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $html = '',
        public readonly string $text = '',
        public readonly ?string $fromAddress = null,
        public readonly ?string $fromName = null,
        public readonly ?string $replyTo = null,
        public readonly array $attachments = [],
        public readonly array $cc = [],
        public readonly array $bcc = [],
        public readonly ?string $messageId = null,
        public readonly ?string $inReplyTo = null,
    ) {
    }

    /**
     * The text part to send: what the caller wrote, or a readable extraction of the HTML. Clients
     * that only show plain text (and spam filters that compare the two) deserve a real alternative.
     */
    public function textOrFallback(): string
    {
        return $this->text !== '' ? $this->text : MimeBuilder::plainTextFromHtml($this->html);
    }
}
