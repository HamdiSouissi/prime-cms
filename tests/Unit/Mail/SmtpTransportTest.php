<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Mail;

use PHPUnit\Framework\TestCase;
use Prime\Mail\MailConfig;
use Prime\Mail\Message;
use Prime\Mail\SmtpException;
use Prime\Mail\SmtpResponse;
use Prime\Mail\SmtpTransport;

/**
 * The conversation, not the socket: what gets said, in which order, and what a refusal costs.
 */
final class SmtpTransportTest extends TestCase
{
    private ScriptedSmtpSession $session;

    /**
     * @param list<SmtpResponse> $replies
     */
    private function transport(array $replies = [], array $config = [], bool $tlsRefused = false): SmtpTransport
    {
        $this->session = new ScriptedSmtpSession(null, $replies);
        $this->session->tlsRefused = $tlsRefused;

        return new SmtpTransport(
            new MailConfig($config + [
                'host' => 'smtp.test',
                'port' => 587,
                'encryption' => 'tls',
                'from_address' => 'studio@prime.test',
                'from_name' => 'Studio',
            ]),
            fn (): ScriptedSmtpSession => $this->session,
        );
    }

    /**
     * @param list<string> $capabilityLines
     */
    private static function ehlo(array $capabilityLines = ['250-SIZE 10485760', '250-STARTTLS', '250-AUTH PLAIN LOGIN', '250 HELP']): SmtpResponse
    {
        return ScriptedSmtpSession::ok(250, array_merge(['250-smtp.test'], $capabilityLines));
    }

    public function testAFullSendWalksTheConversationInOrder(): void
    {
        $transport = $this->transport([
            self::ehlo(),
            ScriptedSmtpSession::ok(220, '220 ready to start TLS'),
            self::ehlo(),
            ScriptedSmtpSession::ok(235, '235 2.7.0 Authentication successful'),
            ScriptedSmtpSession::ok(250, '250 Ok'),
            ScriptedSmtpSession::ok(250, '250 Ok'),
            ScriptedSmtpSession::ok(250, '250 2.0.0 Ok: queued as 4A2F1'),
            ScriptedSmtpSession::ok(221, '221 Bye'),
        ], ['username' => 'studio@prime.test', 'password' => 'mot de passe']);

        $transport->send(new Message(
            to: 'alice@example.fr',
            subject: 'Bienvenue à bord',
            html: '<p>Salut</p>',
            text: ".\nSalut",
        ));

        $this->assertSame(
            ['(GREETING)', 'EHLO', 'STARTTLS', 'EHLO', 'AUTH', 'MAIL', 'RCPT', 'DATA', 'QUIT'],
            $this->session->verbs(),
        );
        $this->assertContains('MAIL FROM:<studio@prime.test>', $this->session->commands);
        $this->assertContains('RCPT TO:<alice@example.fr>', $this->session->commands);
        $this->assertStringStartsWith('AUTH PLAIN ', (string) $this->session->commands[4]);
        $this->assertSame(1, $this->session->tlsCalls);
        $this->assertTrue($this->session->closed);

        $payload = $this->session->payloads[0];

        $this->assertStringStartsWith('Date: ', $payload);
        $this->assertStringContainsString('Subject: =?UTF-8?Q?Bienvenue_', $payload);
        $this->assertStringContainsString('multipart/alternative', $payload);
        $this->assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $payload);
        // The DATA terminator must be escaped, or the message ends after its first line.
        $this->assertStringContainsString("\r\n..\r\nSalut", $payload);
    }

    public function testCredentialsAreSentBase64EncodedAndNeverInTheClear(): void
    {
        $transport = $this->transport([
            self::ehlo(),
            ScriptedSmtpSession::ok(220, '220 ready'),
            self::ehlo(),
            ScriptedSmtpSession::ok(235, '235 ok'),
            ScriptedSmtpSession::ok(250, '250 Ok'),
            ScriptedSmtpSession::ok(250, '250 Ok'),
            ScriptedSmtpSession::ok(250, '250 Ok'),
            ScriptedSmtpSession::ok(221, '221 Bye'),
        ], ['username' => 'studio', 'password' => 's3cret !']);

        $transport->send(new Message(to: 'a@b.fr', subject: 's', text: 'x'));

        $auth = null;
        foreach ($this->session->commands as $command) {
            if (str_starts_with($command, 'AUTH PLAIN ')) {
                $auth = $command;
            }
        }

        $this->assertIsString($auth);
        $this->assertStringNotContainsString('s3cret', $auth);
        $this->assertSame("studio\0studio\0s3cret !", base64_decode(substr((string) $auth, strlen('AUTH PLAIN ')), true));
    }

    public function testAServerWithoutStartTlsStopsTheSendInsteadOfDegrading(): void
    {
        $transport = $this->transport([
            self::ehlo(['250-SIZE 10485760', '250 HELP']),
        ], ['username' => 'studio', 'password' => 'x']);

        try {
            $transport->send(new Message(to: 'a@b.fr', subject: 's', text: 'x'));
            $this->fail('un serveur sans STARTTLS doit refuser la remise');
        } catch (SmtpException $exception) {
            $this->assertSame('handshake', $exception->context);
            $this->assertStringContainsString('STARTTLS', $exception->getMessage());
        }

        $this->assertSame([], $this->session->payloads, 'rien ne doit partir en clair');
        $this->assertTrue($this->session->closed);
    }

    public function testARefusedTlsHandshakeIsAFailureToo(): void
    {
        $transport = $this->transport([
            self::ehlo(),
            ScriptedSmtpSession::ok(220, '220 ready'),
        ], ['username' => 'studio', 'password' => 'x'], true);

        $this->expectException(SmtpException::class);
        $this->expectExceptionMessage('montée en TLS');

        try {
            $transport->send(new Message(to: 'a@b.fr', subject: 's', text: 'x'));
        } finally {
            $this->assertTrue($this->session->closed);
        }
    }

    public function testATransientDataRefusalIsWorthAnotherTry(): void
    {
        $transport = $this->transport([
            self::ehlo(),
            ScriptedSmtpSession::ok(220, '220 ready'),
            self::ehlo(),
            ScriptedSmtpSession::ok(235, '235 ok'),
            ScriptedSmtpSession::ok(250, '250 Ok'),
            ScriptedSmtpSession::ok(250, '250 Ok'),
            ScriptedSmtpSession::ok(451, '451 4.3.0 Please try again'),
        ], ['username' => 'studio', 'password' => 'x']);

        try {
            $transport->send(new Message(to: 'a@b.fr', subject: 's', text: 'x'));
            $this->fail('un 451 doit remonter');
        } catch (SmtpException $exception) {
            $this->assertTrue($exception->isRetryable());
            $this->assertSame('DATA', $exception->context);
        }
    }

    public function testAPermanentRecipientRefusalEndsTheJob(): void
    {
        $transport = $this->transport([
            self::ehlo(),
            ScriptedSmtpSession::ok(220, '220 ready'),
            self::ehlo(),
            ScriptedSmtpSession::ok(235, '235 ok'),
            ScriptedSmtpSession::ok(250, '250 Ok'),
            ScriptedSmtpSession::ok(550, '550 5.1.1 Recipient address rejected: User unknown'),
        ], ['username' => 'studio', 'password' => 'x']);

        try {
            $transport->send(new Message(to: 'ghost@example.fr', subject: 's', text: 'x'));
            $this->fail('un 550 doit remonter');
        } catch (SmtpException $exception) {
            $this->assertFalse($exception->isRetryable(), 'relayer pour un destinataire inexistant ne sert à rien');
            $this->assertSame('RCPT TO', $exception->context);
            $this->assertSame([], $this->session->payloads);
        }
    }

    public function testAuthenticationIsSkippedForAnOpenRelayAndRefusedWhenRequired(): void
    {
        $open = $this->transport([
            self::ehlo(['250-STARTTLS', '250 HELP']),
            ScriptedSmtpSession::ok(220, '220 ready'),
            self::ehlo(['250 HELP']),
            ScriptedSmtpSession::ok(250, '250 Ok'),
            ScriptedSmtpSession::ok(250, '250 Ok'),
            ScriptedSmtpSession::ok(250, '250 Ok'),
            ScriptedSmtpSession::ok(221, '221 Bye'),
        ]);
        $open->send(new Message(to: 'a@b.fr', subject: 's', text: 'x'));
        $this->assertNotContains('AUTH', array_map(static fn (string $v): string => strtok($v, ' ') ?: $v, $this->session->commands));

        $required = $this->transport([
            self::ehlo(['250-STARTTLS', '250 HELP']),
            ScriptedSmtpSession::ok(220, '220 ready'),
            self::ehlo(['250 HELP']),
        ], ['username' => 'studio', 'password' => 'x']);

        $this->expectException(SmtpException::class);
        $this->expectExceptionMessage('ni AUTH PLAIN ni AUTH LOGIN');

        $required->send(new Message(to: 'a@b.fr', subject: 's', text: 'x'));
    }

    public function testLoginMechanismIsUsedWhenPlainIsNotOffered(): void
    {
        $transport = $this->transport([
            self::ehlo(['250-STARTTLS', '250-AUTH LOGIN PLAIN', '250 HELP']),
            ScriptedSmtpSession::ok(220, '220 ready'),
            self::ehlo(['250-AUTH LOGIN', '250 HELP']),
            ScriptedSmtpSession::ok(334, '334 VXNlcm5hbWU6'),
            ScriptedSmtpSession::ok(334, '334 UGFzc3dvcmQ6'),
            ScriptedSmtpSession::ok(235, '235 ok'),
            ScriptedSmtpSession::ok(250, '250 Ok'),
            ScriptedSmtpSession::ok(250, '250 Ok'),
            ScriptedSmtpSession::ok(250, '250 Ok'),
            ScriptedSmtpSession::ok(221, '221 Bye'),
        ], ['username' => 'studio', 'password' => 'x']);

        // Only LOGIN is advertised on the second EHLO, so the transport has to follow the server.
        $transport->send(new Message(to: 'a@b.fr', subject: 's', text: 'x'));

        $commands = $this->session->commands;
        $this->assertContains('AUTH LOGIN', $commands);
        $this->assertContains(base64_encode('studio'), $commands);
        $this->assertContains(base64_encode('x'), $commands);
    }

    public function testVerifyReportsCapabilitiesWithoutTouchingTheEnvelope(): void
    {
        $transport = $this->transport([
            self::ehlo(),
            ScriptedSmtpSession::ok(220, '220 ready'),
            self::ehlo(),
            ScriptedSmtpSession::ok(235, '235 ok'),
            ScriptedSmtpSession::ok(250, '250 Ok'),
            ScriptedSmtpSession::ok(221, '221 Bye'),
        ], ['username' => 'studio', 'password' => 'x']);

        $report = $transport->verify();

        $this->assertTrue($report['ok'], $report['message']);
        $this->assertSame('PLAIN', $report['auth']);
        $this->assertTrue($report['tls']);
        $this->assertContains('SIZE 10485760', $report['capabilities']);
        $this->assertSame([], $this->session->payloads);
        $this->assertContains('RSET', $this->session->commands);
    }

    public function testVerifyTurnsARefusalIntoAnAnswerRatherThanAnException(): void
    {
        $transport = $this->transport([
            self::ehlo(),
            ScriptedSmtpSession::ok(454, '454 TLS not available'),
        ], ['username' => 'studio', 'password' => 'x']);

        $report = $transport->verify();

        $this->assertFalse($report['ok']);
        $this->assertStringContainsString('STARTTLS', $report['message']);
    }

    public function testAMissingHostIsReportedAsAFinalConfigurationProblem(): void
    {
        $this->session = new ScriptedSmtpSession();
        $transport = new SmtpTransport(new MailConfig(['host' => '', 'driver' => 'smtp']));

        try {
            $transport->send(new Message(to: 'a@b.fr', subject: 's', text: 'x'));
            $this->fail('aucun hôte : rien à tenter');
        } catch (SmtpException $exception) {
            $this->assertFalse($exception->isRetryable());
            $this->assertStringContainsString('aucun hôte SMTP configuré', $exception->getMessage());
        }
    }

    public function testARejectedListOfRecipientsIsRefusedBeforeConnecting(): void
    {
        $this->session = new ScriptedSmtpSession();
        $transport = new SmtpTransport(new MailConfig(['host' => 'smtp.test']), static fn (): ScriptedSmtpSession => $this->session);

        $this->expectException(SmtpException::class);
        $this->expectExceptionMessage('aucun destinataire valide');

        $transport->send(new Message(to: 'not-an-email', subject: 's', text: 'x'));
    }

    public function testImplicitTlsConnectsWithoutAStarttlsRoundTrip(): void
    {
        $transport = $this->transport([
            self::ehlo(['250-AUTH PLAIN', '250 HELP']),
            ScriptedSmtpSession::ok(235, '235 ok'),
            ScriptedSmtpSession::ok(250, '250 Ok'),
            ScriptedSmtpSession::ok(250, '250 Ok'),
            ScriptedSmtpSession::ok(250, '250 Ok'),
            ScriptedSmtpSession::ok(221, '221 Bye'),
        ], ['encryption' => 'ssl', 'port' => 465, 'username' => 'studio', 'password' => 'x']);

        $transport->send(new Message(to: 'a@b.fr', subject: 's', text: 'x'));

        $this->assertNotContains('STARTTLS', $this->session->commands);
        $this->assertSame(0, $this->session->tlsCalls);
    }
}
