<?php

declare(strict_types=1);

namespace Prime\Mail;

/**
 * Real sockets behind {@see SmtpSessionInterface}: connect, read a reply, write a command, upgrade to TLS.
 *
 * Every failure is converted into an {@see SmtpException} carrying what the server actually said. A
 * raw PHP warning from `fwrite()` on a dead TLS connection is worthless to an administrator; "SSL:
 * broken pipe" in the message is what makes the mail screen useful.
 */
final class StreamSmtpSession implements SmtpSessionInterface
{
    /**
     * @var resource
     */
    private $stream;

    private ?SmtpResponse $greeting = null;

    /**
     * @param resource $stream
     */
    private function __construct($stream, private readonly int $timeout)
    {
        $this->stream = $stream;
    }

    public static function isSupported(): bool
    {
        return function_exists('stream_socket_client');
    }

    public static function connect(
        string $host,
        int $port,
        bool $implicitTls,
        int $timeout,
        bool $verifyPeer,
        ?string $peerName = null,
    ): self {
        if (!self::isSupported()) {
            throw SmtpException::connectionFailed('stream_socket_client() est désactivé sur ce serveur.', false);
        }

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $verifyPeer,
                'verify_peer_name' => $verifyPeer,
                'allow_self_signed' => !$verifyPeer,
                'capture_peer_cert' => false,
                'SNI_enabled' => true,
                'peer_name' => $peerName ?? $host,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            ($implicitTls ? 'ssl://' : 'tcp://') . $host . ':' . $port,
            $errno,
            $errstr,
            (float) $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if (!is_resource($socket)) {
            throw SmtpException::connectionFailed(sprintf(
                '%s (%s:%d)',
                $errstr !== '' ? $errstr : sprintf('errno %d', $errno),
                $host,
                $port,
            ));
        }

        stream_set_timeout($socket, $timeout);

        return new self($socket, $timeout);
    }

    public function greeting(): SmtpResponse
    {
        return $this->greeting ??= $this->read();
    }

    public function command(string $command): SmtpResponse
    {
        $this->write($command . "\r\n");

        return $this->read();
    }

    public function data(string $payload): SmtpResponse
    {
        $response = $this->command('DATA');

        if ($response->code !== 354) {
            throw SmtpException::unexpected($response->code, $response->text(), 'DATA');
        }

        $this->write($payload . "\r\n.\r\n");

        return $this->read();
    }

    public function startTls(): bool
    {
        $method = defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')
            ? STREAM_CRYPTO_METHOD_TLS_CLIENT
            : STREAM_CRYPTO_METHOD_SSLv23_CLIENT;

        $result = @stream_socket_enable_crypto($this->stream, true, $method);

        if ($result !== true) {
            throw SmtpException::handshakeFailed('négociation TLS refusée par le serveur');
        }

        return true;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
    }

    /**
     * @throws SmtpException
     */
    private function write(string $bytes): void
    {
        $written = @fwrite($this->stream, $bytes);

        if (!is_int($written) || $written < strlen($bytes)) {
            $error = error_get_last();

            throw SmtpException::connectionFailed(
                $error !== null ? (string) $error['message'] : 'connexion fermée par le serveur',
            );
        }

        @fflush($this->stream);
        $this->assertNotTimedOut('envoi');
    }

    private function read(): SmtpResponse
    {
        $buffer = '';

        while (true) {
            $line = @fgets($this->stream);

            if (!is_string($line)) {
                $this->assertNotTimedOut('lecture');

                throw SmtpException::handshakeFailed('le serveur a fermé la connexion sans répondre');
            }

            $buffer .= $line;

            // A final line is "250 text"; a continuation is "250-text".
            if (preg_match('/^\d{3} /', $line) === 1) {
                break;
            }

            $this->assertNotTimedOut('lecture');
        }

        return SmtpResponse::parse($buffer);
    }

    private function assertNotTimedOut(string $phase): void
    {
        $meta = stream_get_meta_data($this->stream);

        if ($meta['timed_out'] === true) {
            throw SmtpException::connectionFailed(sprintf('délai de %d s dépassé pendant la %s', $this->timeout, $phase));
        }

        if ($meta['eof'] === true && feof($this->stream)) {
            throw SmtpException::connectionFailed(sprintf('connexion interrompue pendant la %s', $phase));
        }
    }
}
