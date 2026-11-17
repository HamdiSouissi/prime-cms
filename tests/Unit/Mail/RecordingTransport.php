<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Mail;

use Prime\Mail\MailerInterface;
use Prime\Mail\Message;
use Throwable;

/**
 * A transport that remembers what it was asked to send, and can be told to refuse.
 */
final class RecordingTransport implements MailerInterface
{
    /**
     * @var list<Message>
     */
    public array $sent = [];

    public ?Throwable $fail = null;

    public function send(Message $message): void
    {
        if ($this->fail !== null) {
            throw $this->fail;
        }

        $this->sent[] = $message;
    }
}
