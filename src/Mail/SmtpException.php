<?php

declare(strict_types=1);

namespace Prime\Mail;

use RuntimeException;

/**
 * An SMTP conversation that did not end well.
 *
 * `$retryable` is the whole point of the class: a greylisted 450 deserves another try in a minute, a
 * 550 "relay denied" never will, and only the transport knows the difference. The queue uses it to
 * decide between a backoff and parking the job as failed.
 */
class SmtpException extends RuntimeException
{
    public readonly bool $retryable;

    public readonly ?string $context;

    public function __construct(
        string $message,
        int $code = 0,
        bool $retryable = false,
        ?string $context = null,
    ) {
        parent::__construct($message, $code);

        $this->retryable = $retryable;
        $this->context = $context;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public static function connectionFailed(string $reason, bool $retryable = true): self
    {
        return new self(sprintf('Connexion SMTP impossible : %s', $reason), 0, $retryable, 'connection');
    }

    public static function unexpected(int $code, string $expected, string $context): self
    {
        return new self(
            sprintf('Réponse inattendue du serveur SMTP (%d) pendant %s : %s', $code, $context, $expected),
            $code,
            $code >= 400 && $code < 500,
            $context,
        );
    }

    public static function handshakeFailed(string $detail): self
    {
        return new self(sprintf('Échange SMTP impossible : %s', $detail), 0, true, 'handshake');
    }

    public static function authenticationFailed(string $detail, bool $retryable = false): self
    {
        return new self(sprintf('Authentification SMTP refusée : %s', $detail), 535, $retryable, 'auth');
    }

    public static function relayRefused(string $detail): self
    {
        return new self(sprintf('Envoi refusé par le serveur SMTP : %s', $detail), 554, false, 'relay');
    }
}
