<?php

declare(strict_types=1);

namespace Prime\Mail;

use RuntimeException;

/**
 * PHP's `mail()` transport: the message is built like any other (UTF-8, `multipart/alternative`,
 * quoted-printable) and handed to the local MTA, which owns the envelope.
 */
final class NativeMailer implements MailerInterface
{
    public function __construct(
        private readonly string $defaultFromAddress = 'no-reply@prime-cms.local',
        private readonly string $defaultFromName = 'Prime CMS',
    ) {
    }

    public function send(Message $message): void
    {
        if (!function_exists('mail')) {
            throw new RuntimeException('La fonction mail() de PHP est désactivée sur ce serveur.');
        }

        $built = MimeBuilder::build($message, new MailConfig([
            'from_address' => $this->defaultFromAddress,
            'from_name' => $this->defaultFromName,
        ]));

        // `mail()` writes the To and Subject headers from its first two arguments: repeating them in the
        // header block is how a message ends up with two Subject lines and lands in spam.
        $headers = array_values(array_filter(
            $built['headers'],
            static fn (string $header): bool => preg_match('/^(To|Subject):/i', $header) !== 1,
        ));

        // The address list, not the display names: this is what sendmail is handed as recipients, and it
        // includes the Bcc entries that must receive the message without ever appearing in it.
        $sent = @mail(
            implode(', ', $built['recipients']),
            $message->subject,
            rtrim($built['body'], "\r\n"),
            implode("\r\n", $headers),
            $this->envelopeArgument($built['recipients']),
        );

        if (!$sent) {
            throw new RuntimeException('Échec de l\'envoi de l\'e-mail via la fonction PHP mail().');
        }
    }

    /**
     * The `-f` flag sets the envelope sender, without which bounces go to root. It is passed to
     * sendmail as a shell argument, so an address that is not an address is simply not used.
     *
     * @param list<string> $recipients
     */
    private function envelopeArgument(array $recipients): string
    {
        $sender = $this->defaultFromAddress;

        if (filter_var($sender, FILTER_VALIDATE_EMAIL) === false) {
            return '';
        }

        return sprintf('-f%s', $sender);
    }
}
