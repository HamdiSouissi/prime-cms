<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Mail;

use Prime\Mail\SmtpResponse;
use Prime\Mail\SmtpSessionInterface;
use RuntimeException;

/**
 * A scripted SMTP peer: the transport believes it is talking to a server, and the test reads back
 * exactly what was sent.
 */
final class ScriptedSmtpSession implements SmtpSessionInterface
{
    /**
     * @var list<SmtpResponse>
     */
    private array $replies;

    /**
     * @var list<string>
     */
    public array $commands = [];

    /**
     * @var list<string>
     */
    public array $payloads = [];

    public int $tlsCalls = 0;

    public bool $closed = false;

    public bool $tlsRefused = false;

    /**
     * @param list<SmtpResponse> $replies
     */
    public function __construct(private readonly ?SmtpResponse $banner = null, array $replies = [])
    {
        $this->replies = $replies;
    }

    /**
     * @param string|list<string> $lines
     */
    public static function ok(int $code, string|array $lines = []): SmtpResponse
    {
        if (is_string($lines)) {
            $lines = $lines === '' ? [] : [$lines];
        }

        return new SmtpResponse($code, array_values($lines));
    }

    public function greeting(): SmtpResponse
    {
        $this->commands[] = '(greeting)';

        return $this->banner ?? self::ok(220, '220 smtp.test ESMTP');
    }

    public function command(string $command): SmtpResponse
    {
        $this->commands[] = $command;

        return $this->next();
    }

    public function data(string $payload): SmtpResponse
    {
        $this->commands[] = 'DATA';
        $this->payloads[] = $payload;

        return $this->next();
    }

    public function startTls(): bool
    {
        $this->tlsCalls++;

        return !$this->tlsRefused;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    /**
     * @return list<string>
     */
    public function verbs(): array
    {
        $verbs = [];

        foreach ($this->commands as $command) {
            $verbs[] = strtoupper(strtok($command, ' ') ?: $command);
        }

        return $verbs;
    }

    private function next(): SmtpResponse
    {
        if ($this->replies === []) {
            throw new RuntimeException('Le script SMTP n\'a plus de réponse pour cette commande.');
        }

        return array_shift($this->replies);
    }
}
