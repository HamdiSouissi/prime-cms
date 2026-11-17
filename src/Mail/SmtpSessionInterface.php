<?php

declare(strict_types=1);

namespace Prime\Mail;

/**
 * The wire side of SMTP, isolated so the protocol logic can be tested without a server.
 *
 * `greeting()` returns the banner pushed on connect, `command()` sends one line (the CRLF is added
 * here) and returns the full reply, `data()` sends an already dot-stuffed payload, and `startTls()`
 * upgrades the very same connection — which is what makes STARTTLS possible at all.
 */
interface SmtpSessionInterface
{
    /**
     * The `220` banner the server sends before any command.
     */
    public function greeting(): SmtpResponse;

    public function command(string $command): SmtpResponse;

    public function data(string $payload): SmtpResponse;

    public function startTls(): bool;

    public function close(): void;
}
