<?php

declare(strict_types=1);

namespace Prime\Mail;

/**
 * SMTP client: EHLO, STARTTLS, AUTH, MAIL FROM / RCPT TO / DATA, QUIT — no library, no extension.
 *
 * Three decisions worth defending:
 *
 * - TLS is never silently downgraded. A server that does not answer `STARTTLS` when the setting says
 *   "STARTTLS" aborts the send, because the alternative is credentials in clear text.
 * - Failures carry a retry verdict. 4xx and socket errors are retryable (the queue backs off), 5xx
 *   are final (the job is parked, not retried three times against a relay that will never accept it).
 * - The socket lives behind {@see SmtpSessionInterface}, so the whole conversation is unit-testable
 *   against a scripted session.
 */
final class SmtpTransport implements MailerInterface
{
    public const METHOD_PLAIN = 'PLAIN';
    public const METHOD_LOGIN = 'LOGIN';

    /**
     * @var callable(string, int, bool, int, bool): SmtpSessionInterface
     */
    private $connector;

    /**
     * @param (callable(string, int, bool, int, bool): SmtpSessionInterface)|null $connector
     */
    public function __construct(
        private readonly MailConfig $config,
        ?callable $connector = null,
    ) {
        $this->connector = $connector ?? static function (string $host, int $port, bool $implicitTls, int $timeout, bool $verifyPeer): SmtpSessionInterface {
            return StreamSmtpSession::connect($host, $port, $implicitTls, $timeout, $verifyPeer);
        };
    }

    public static function isAvailable(): bool
    {
        return StreamSmtpSession::isSupported();
    }

    public function config(): MailConfig
    {
        return $this->config;
    }

    public function send(Message $message): void
    {
        if ($this->config->host() === '') {
            throw SmtpException::connectionFailed('aucun hôte SMTP configuré', false);
        }

        $built = MimeBuilder::build($message, $this->config);
        $recipients = $built['recipients'];

        if ($recipients === []) {
            throw SmtpException::relayRefused('aucun destinataire valide');
        }

        $sender = MimeBuilder::splitAddresses($message->fromAddress ?? $this->config->fromAddress());
        $returnPath = $sender[0] ?? '';

        if ($returnPath === '') {
            throw SmtpException::relayRefused('aucune adresse d\'expéditeur valide');
        }

        $session = $this->open();

        try {
            $hello = $this->handshake($session);
            $hello = $this->upgrade($session, $hello);
            $this->authenticate($session, $hello);
            $this->envelope($session, $returnPath, $recipients);

            $payload = implode("\r\n", $built['headers']) . "\r\n\r\n" . MimeBuilder::stuffDots($built['body']);
            $response = $session->data($payload);

            if ($response->code !== 250) {
                throw SmtpException::unexpected($response->code, $response->text(), 'DATA');
            }

            $session->command('QUIT');
        } finally {
            $session->close();
        }
    }

    /**
     * Dial the server, shake hands, authenticate, and hang up without accepting a message: what
     * `mail:test` and the settings screen use to answer "is this configuration usable?" without
     * needing a recipient.
     *
     * @return array{ok: bool, message: string, capabilities: list<string>, auth: string, tls: bool}
     */
    public function verify(): array
    {
        $capabilities = [];
        $tls = false;
        $auth = 'aucun';

        try {
            $session = $this->open();

            try {
                $hello = $this->handshake($session);
                $capabilities = $this->capabilities($hello);
                $hello = $this->upgrade($session, $hello);
                $tls = $this->config->encryption() !== MailConfig::ENCRYPTION_NONE;
                $capabilities = array_values(array_unique(array_merge($capabilities, $this->capabilities($hello))));

                if ($this->config->username() !== '' && $this->config->password() !== '') {
                    $this->authenticate($session, $hello);
                    $auth = $this->authMethod($hello) ?? self::METHOD_PLAIN;
                }

                $session->command('RSET');
                $session->command('QUIT');
            } finally {
                $session->close();
            }
        } catch (SmtpException $exception) {
            return [
                'ok' => false,
                'message' => $exception->getMessage(),
                'capabilities' => $capabilities,
                'auth' => $auth,
                'tls' => $tls,
            ];
        }

        return [
            'ok' => true,
            'message' => 'Connexion et authentification réussies.',
            'capabilities' => $capabilities,
            'auth' => $auth,
            'tls' => $tls,
        ];
    }

    private function open(): SmtpSessionInterface
    {
        $host = $this->config->host();

        if ($host === '') {
            throw SmtpException::connectionFailed('aucun hôte SMTP configuré', false);
        }

        $connector = $this->connector;

        return $connector(
            $host,
            $this->config->port(),
            $this->config->encryption() === MailConfig::ENCRYPTION_SSL,
            $this->config->timeout(),
            $this->config->verifyPeer(),
        );
    }

    private function handshake(SmtpSessionInterface $session): SmtpResponse
    {
        $greeting = $session->greeting();

        if ($greeting->code !== 220) {
            throw SmtpException::unexpected($greeting->code, $greeting->text(), 'greeting');
        }

        $hello = $session->command('EHLO ' . $this->clientName());

        if ($hello->code === 250) {
            return $hello;
        }

        // An ancient server may only know HELO; the rest of the flow still works without capabilities.
        $helo = $session->command('HELO ' . $this->clientName());

        if ($helo->code !== 250) {
            throw SmtpException::handshakeFailed($helo->text() !== '' ? $helo->text() : $hello->text());
        }

        return $helo;
    }

    /**
     * The envelope, kept separate because "no recipient accepted" and "the message was refused" are
     * different diagnoses: the first is the administrator's problem, the second the server's.
     */
    /**
     * @param list<string> $recipients
     */
    private function envelope(SmtpSessionInterface $session, string $returnPath, array $recipients): void
    {
        $response = $session->command('MAIL FROM:<' . $returnPath . '>');

        if ($response->code !== 250) {
            throw SmtpException::unexpected($response->code, $response->text(), 'MAIL FROM');
        }

        $accepted = 0;
        $refused = null;

        foreach ($recipients as $recipient) {
            $response = $session->command('RCPT TO:<' . $recipient . '>');

            if (in_array($response->code, [250, 251, 252], true)) {
                $accepted++;
                continue;
            }

            $refused = $response;
        }

        if ($accepted === 0 && $refused !== null) {
            throw SmtpException::unexpected($refused->code, $refused->text(), 'RCPT TO');
        }
    }

    /**
     * The same policy whether we are sending or only verifying: a verification that forgave a missing
     * STARTTLS would report a configuration as healthy while every real send fails.
     */
    private function upgrade(SmtpSessionInterface $session, SmtpResponse $hello): SmtpResponse
    {
        if ($this->config->encryption() !== MailConfig::ENCRYPTION_STARTTLS) {
            return $hello;
        }

        if (!$hello->advertises('STARTTLS')) {
            throw SmtpException::handshakeFailed(
                'le serveur n\'annonce pas STARTTLS : la connexion resterait en clair, vérifiez le port (465 pour SSL, 587 pour STARTTLS)',
            );
        }

        $response = $session->command('STARTTLS');

        if ($response->code !== 220) {
            throw SmtpException::unexpected($response->code, $response->text(), 'STARTTLS');
        }

        if ($session->startTls() !== true) {
            throw SmtpException::handshakeFailed('le serveur a refusé la montée en TLS');
        }

        $replied = $session->command('EHLO ' . $this->clientName());

        return $replied->code === 250 ? $replied : $hello;
    }

    private function authenticate(SmtpSessionInterface $session, SmtpResponse $hello): void
    {
        $username = $this->config->username();
        $password = $this->config->password();

        if ($username === '' || $password === '') {
            return;
        }

        $method = $this->authMethod($hello);

        if ($method === null) {
            throw SmtpException::authenticationFailed(
                'le serveur n\'accepte ni AUTH PLAIN ni AUTH LOGIN, mais des identifiants sont configurés',
            );
        }

        if ($method === self::METHOD_PLAIN) {
            $response = $session->command('AUTH PLAIN ' . base64_encode($username . "\0" . $username . "\0" . $password));
        } else {
            $challenge = $session->command('AUTH LOGIN');

            if ($challenge->code !== 334) {
                throw SmtpException::authenticationFailed($challenge->text(), $challenge->isTransient());
            }

            $response = $session->command(base64_encode($username));

            if ($response->code !== 334) {
                throw SmtpException::unexpected($response->code, $response->text(), 'AUTH LOGIN (identifiant)');
            }

            $response = $session->command(base64_encode($password));
        }

        if ($response->code !== 235) {
            throw SmtpException::authenticationFailed($response->text(), $response->code >= 400 && $response->code < 500);
        }
    }

    /**
     * @return list<string>
     */
    private function capabilities(SmtpResponse $hello): array
    {
        $list = [];

        foreach ($hello->lines as $line) {
            $trimmed = trim((string) preg_replace('/^\d{3}[- ]/', '', $line));

            if ($trimmed !== '') {
                $list[] = $trimmed;
            }
        }

        return $list;
    }

    private function authMethod(SmtpResponse $hello): ?string
    {
        $advertised = [];

        foreach ($hello->lines as $line) {
            if (preg_match('/^\d{3}-?AUTH (.+)$/i', trim($line), $m) === 1) {
                foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $mechanism) {
                    $advertised[strtoupper($mechanism)] = true;
                }
            }
        }

        if (isset($advertised[self::METHOD_PLAIN])) {
            return self::METHOD_PLAIN;
        }

        if (isset($advertised[self::METHOD_LOGIN])) {
            return self::METHOD_LOGIN;
        }

        return $advertised === [] ? null : (isset($advertised['CRAM-MD5']) ? null : self::METHOD_PLAIN);
    }

    private function clientName(): string
    {
        $name = gethostname();

        if (!is_string($name) || $name === '') {
            $name = $this->config->domain() !== '' ? $this->config->domain() : 'localhost';
        }

        return MimeBuilder::sanitize($name) !== '' ? MimeBuilder::sanitize($name) : 'localhost';
    }
}
