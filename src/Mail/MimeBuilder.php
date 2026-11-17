<?php

declare(strict_types=1);

namespace Prime\Mail;

/**
 * Turns a `Message` into the bytes an MTA expects: RFC 2047 headers, `multipart/alternative` (or
 * `mixed` when attachments ride along), quoted-printable bodies folded at 76 characters.
 *
 * Everything here is static and depends on nothing but the message, which is what makes the wire
 * format testable: a unit test asserts the exact bytes without opening a socket.
 */
final class MimeBuilder
{
    /** RFC 5322 line limit, kept below 78 so the soft break never pushes a line over. */
    public const MAX_LINE = 75;

    private const ENCODED_WORD_OVERHEAD = 13; // "=?UTF-8?Q??=" + the fold space

    private function __construct()
    {
    }

    /**
     * @return array{headers: list<string>, body: string, recipients: list<string>}
     */
    public static function build(Message $message, MailConfig $config): array
    {
        $boundary = self::boundary($message);
        $text = $message->textOrFallback();
        $html = $message->html;

        $from = self::addressHeader($message->fromAddress ?? $config->fromAddress(), $message->fromName ?? $config->fromName());
        $replyTo = $message->replyTo ?? $config->replyTo();

        $headers = [
            'Date: ' . gmdate('r'),
            'From: ' . $from,
            'To: ' . self::addressList($message->to),
            'Subject: ' . self::encodeHeader($message->subject),
            'Message-ID: <' . ($message->messageId ?? self::messageId($config->domain())) . '>',
            'MIME-Version: 1.0',
            'X-Mailer: Prime CMS',
        ];

        $cc = self::addressList($message->cc);
        if ($cc !== '') {
            $headers[] = 'Cc: ' . $cc;
        }

        // Bcc recipients are collected for the envelope but never written into the message.
        $bcc = self::splitAddresses($message->bcc);

        if ($replyTo !== '') {
            $headers[] = 'Reply-To: ' . self::addressList($replyTo);
        }

        if ($message->inReplyTo !== null && $message->inReplyTo !== '') {
            $headers[] = 'In-Reply-To: <' . self::sanitize($message->inReplyTo) . '>';
        }

        if ($message->attachments !== []) {
            $inner = self::boundary($message);
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

            // The text/HTML pair keeps its own nested multipart: a client that prefers plain text would
            // otherwise read the attachment section as body copy.
            $body = "--{$boundary}\r\n"
                . 'Content-Type: multipart/alternative; boundary="' . $inner . '"' . "\r\n\r\n"
                . self::alternativePart($text, $html, $inner)
                . "--{$boundary}\r\n";

            foreach ($message->attachments as $attachment) {
                $body .= "--{$boundary}\r\n"
                    . 'Content-Type: ' . $attachment->mime . '; name="' . self::encodeParameter($attachment->filename) . '"' . "\r\n"
                    . 'Content-Transfer-Encoding: base64' . "\r\n"
                    . 'Content-Disposition: attachment; filename="' . self::encodeParameter($attachment->filename) . '"' . "\r\n\r\n"
                    . rtrim(self::base64Lines($attachment->contents), "\r\n") . "\r\n";
            }

            $body .= "--{$boundary}--\r\n";
        } elseif ($text !== '' && $html !== '') {
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $body = self::alternativePart($text, $html, $boundary);
        } else {
            $isHtml = $html !== '';
            $headers[] = 'Content-Type: text/' . ($isHtml ? 'html' : 'plain') . '; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: quoted-printable';
            $body = self::quotedPrintable($isHtml ? $html : $text) . "\r\n";
        }

        return [
            'headers' => $headers,
            'body' => $body,
            'recipients' => array_values(array_unique(array_merge(
                self::splitAddresses($message->to),
                self::splitAddresses($message->cc),
                $bcc,
            ))),
        ];
    }

    /**
     * RFC 2047 encoded words, Q-encoding, one chunk per line so nothing exceeds the line limit.
     */
    public static function encodeHeader(string $value, string $charset = 'UTF-8'): string
    {
        $value = self::sanitize($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return self::foldText($value);
        }

        $encoded = self::quotePrintableWord($value, $charset);
        $width = self::MAX_LINE - self::ENCODED_WORD_OVERHEAD;
        $chunks = self::splitUnsafe($encoded, max(20, $width));
        $words = [];

        foreach ($chunks as $chunk) {
            $words[] = sprintf('=?%s?Q?%s?=', $charset, $chunk);
        }

        return implode("\r\n ", $words);
    }

    /**
     * Quoted-printable body: CRLF line endings, `=20` for trailing blanks, soft breaks that never
     * split an escape sequence.
     */
    public static function quotedPrintable(string $text): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = [];

        foreach (explode("\n", $normalized) as $line) {
            $escaped = self::escapeNonPrintable(str_replace('=', '=3D', $line));
            // A blank at the end of a line is eaten by far too many MTAs to leave it literal.
            $escaped = (string) preg_replace_callback(
                '/[ \t]+$/',
                static fn (array $m): string => strtr($m[0], [' ' => '=20', "\t" => '=09']),
                $escaped,
            );
            $lines[] = self::foldLine($escaped);
        }

        return implode("\r\n", $lines);
    }

    /**
     * Base64 in 76-character chunks, as attachments must be transmitted.
     */
    public static function base64Lines(string $binary): string
    {
        return chunk_split(base64_encode($binary), 76, "\r\n");
    }

    /**
     * RFC 2821 §4.5.2: a line starting with "." has to be doubled, or the server sees the terminator.
     */
    public static function stuffDots(string $data): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $data);
        $stuffed = preg_replace('/(?m)^\./', '..', $normalized);

        return str_replace("\n", "\r\n", (string) $stuffed);
    }

    /**
     * Strip everything that could smuggle a header or a second RCPT command into a value coming from
     * a request.
     */
    public static function sanitize(string $value): string
    {
        $clean = preg_replace('/[\r\n\0].*/s', '', trim($value));

        return trim((string) $clean);
    }

    public static function messageId(string $domain): string
    {
        return sprintf('%s.%s@%s', bin2hex(random_bytes(8)), (string) time(), $domain !== '' ? $domain : 'localhost');
    }

    /**
     * A readable plain-text rendering of an HTML body: mail clients that show text, and the spam
     * filters that compare the two parts, get the same message.
     */
    public static function plainTextFromHtml(string $html): string
    {
        $text = preg_replace('/<(style|script|head)[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $text = preg_replace('/<a\b[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/is', '$2 ($1)', $text) ?? $text;
        $text = preg_replace('/<\/(p|div|h[1-6]|li|tr|table|blockquote)>/i', "\n\n", $text) ?? $text;
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<li\b[^>]*>/i', '- ', $text) ?? $text;
        $text = preg_replace('/<[^>]+>/', '', $text) ?? $text;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xc2\xa0", ' ', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/ ?\n ?/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * @param string|list<string> $list
     *
     * @return list<string>
     */
    public static function splitAddresses(string|array $list): array
    {
        if (is_array($list)) {
            $list = implode(',', $list);
        }

        $addresses = [];

        foreach (explode(',', $list) as $entry) {
            $entry = self::sanitize($entry);
            if ($entry === '') {
                continue;
            }

            // Keep only the address part: SMTP commands take `RCPT TO:<a@b>`, not the display name.
            if (preg_match('/<([^>]*)>/', $entry, $m) === 1) {
                $entry = trim($m[1]);
            }

            if ($entry !== '' && filter_var($entry, FILTER_VALIDATE_EMAIL) !== false) {
                $addresses[] = $entry;
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * @param string|list<string> $list
     */
    private static function addressList(string|array $list): string
    {
        if (is_array($list)) {
            $list = implode(',', $list);
        }

        $parts = [];

        foreach (explode(',', $list) as $entry) {
            $entry = self::sanitize($entry);
            if ($entry === '') {
                continue;
            }

            if (preg_match('/^(.*?)<([^>]*)>$/', $entry, $m) === 1) {
                $parts[] = self::encodeHeader(trim($m[1])) . ' <' . trim($m[2]) . '>';
                continue;
            }

            $parts[] = $entry;
        }

        return implode(', ', $parts);
    }

    private static function addressHeader(string $address, string $name): string
    {
        $address = self::sanitize($address);

        if (trim($name) === '') {
            return $address;
        }

        return self::encodeHeader($name) . ' <' . $address . '>';
    }

    private static function alternativePart(string $text, string $html, string $boundary): string
    {
        return "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . self::quotedPrintable($text) . "\r\n\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . self::quotedPrintable($html) . "\r\n\r\n"
            . "--{$boundary}--\r\n";
    }

    /**
     * A boundary that cannot collide with the payload, or the message is truncated at the first
     * accidental match.
     */
    private static function boundary(Message $message): string
    {
        $needle = 'primemail';

        do {
            $boundary = $needle . '-' . bin2hex(random_bytes(12));
            $haystack = $message->html . $message->text . implode('', array_map(
                static fn (Attachment $a): string => $a->contents,
                $message->attachments,
            ));
        } while (stripos($haystack, $boundary) !== false);

        return $boundary;
    }

    /**
     * RFC 2047 Q-encoding: "_" for spaces, uppercase hex escapes for everything that is not
     * allowed inside an encoded word.
     */
    private static function quotePrintableWord(string $value, string $charset): string
    {
        $out = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            $code = ord($char);

            if ($char === ' ') {
                $out .= '_';
                continue;
            }

            // "!<H" and "~" are the printable set RFC 2047 allows unescaped, minus "?" and "=" handling.
            if ($code >= 33 && $code <= 126 && !in_array($char, ['?', '=', '_', '(', ')', '[', ']', ';', ',', '.', ':'], true)) {
                $out .= $char;
                continue;
            }

            $out .= '=' . strtoupper(dechex($code));
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function splitUnsafe(string $value, int $width): array
    {
        if (strlen($value) <= $width) {
            return [$value];
        }

        $chunks = [];
        $current = '';
        $length = strlen($value);
        $i = 0;

        while ($i < $length) {
            $unit = $value[$i] === '=' ? substr($value, $i, 3) : $value[$i];
            $i += strlen($unit);

            if (strlen($current) + strlen($unit) > $width && $current !== '') {
                $chunks[] = $current;
                $current = '';
            }

            $current .= $unit;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private static function foldText(string $value): string
    {
        if (strlen($value) <= self::MAX_LINE) {
            return $value;
        }

        $words = preg_split('/ +/', $value) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            if ($current !== '' && strlen($current) + 1 + strlen($word) > self::MAX_LINE) {
                $lines[] = $current;
                $current = $word;
                continue;
            }

            $current = $current === '' ? $word : $current . ' ' . $word;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return implode("\r\n ", $lines);
    }

    private static function escapeNonPrintable(string $line): string
    {
        $out = '';
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $code = ord($line[$i]);

            if ($code === 9 || ($code >= 32 && $code <= 126)) {
                $out .= $line[$i];
                continue;
            }

            $out .= '=' . strtoupper(bin2hex($line[$i]));
        }

        return $out;
    }

    /**
     * Fold one already-escaped line, keeping the soft break inside the 76-character limit and never
     * inside an `=XX` escape.
     */
    private static function foldLine(string $line): string
    {
        $line = rtrim($line);

        if ($line === '') {
            return '';
        }

        return implode("=\r\n", self::splitUnsafe($line, self::MAX_LINE));
    }

    private static function encodeParameter(string $filename): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $filename) === 1) {
            return str_replace('"', '', $filename);
        }

        return rawurlencode($filename);
    }
}
