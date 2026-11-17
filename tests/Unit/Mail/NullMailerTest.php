<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Mail;

use PHPUnit\Framework\TestCase;
use Prime\Mail\Message;
use Prime\Mail\NullMailer;

final class NullMailerTest extends TestCase
{
    public function testTheNullTransportAcceptsMessagesWithoutSendingOrWritingAnything(): void
    {
        $this->expectNotToPerformAssertions();
        (new NullMailer())->send(new Message(to: 'a@example.test', subject: 'Test', text: 'Corps'));
    }
}
