<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Mail;

use PHPUnit\Framework\TestCase;
use Prime\Mail\Attachment;
use Prime\Mail\MailConfig;
use Prime\Mail\Message;
use Prime\Mail\MimeBuilder;

/**
 * The bytes on the wire: quoted-printable that fits in 76 columns, RFC 2047 encoded words, dot
 * stuffing that cannot end the message early, and a MIME structure clients can actually parse.
 */
final class MimeBuilderTest extends TestCase
{
    public function testQuotedPrintableFoldsWithoutBreakingAnEscapeSequence(): void
    {
        $text = "Bonjour à tous, voici une ligne beaucoup trop longue pour tenir dans soixante-quinze caractères sans être coupée.\n";
        $encoded = MimeBuilder::quotedPrintable($text);

        foreach (explode("\r\n", $encoded) as $line) {
            $this->assertLessThanOrEqual(76, strlen($line), 'une ligne dépasse la limite RFC 5322');
        }

        $this->assertStringContainsString('=C3=A0', $encoded);
        $this->assertStringContainsString('=C3=A8res', $encoded);
        // Un折 escape would survive the length check and silently corrupt the body: only a round-trip proves it.
        $this->assertSame(str_replace("\n", "\r\n", $text), quoted_printable_decode($encoded));
    }

    public function testEqualsSignAndTrailingBlanksAreEscaped(): void
    {
        $encoded = MimeBuilder::quotedPrintable("a = b\nc   ");

        $this->assertSame("a =3D b\r\nc=20=20=20", $encoded);
    }

    public function testCrlfIsNormalisedAndBlankLinesSurvive(): void
    {
        $this->assertSame("un\r\n\r\ndeux\r\n", MimeBuilder::quotedPrintable("un\n\ndeux\r\n"));
    }

    public function testNonAsciiSubjectBecomesEncodedWordsWithinTheLineLimit(): void
    {
        $subject = 'Réinitialisation de votre mot de passe pour l’atelier Lumière & Fils de Créteil — confirmez';
        $header = MimeBuilder::encodeHeader($subject);

        foreach (explode("\r\n", $header) as $line) {
            $this->assertLessThanOrEqual(76, strlen(trim($line)));
        }

        $this->assertMatchesRegularExpression('/=\?UTF-8\?Q\?.*\?=/', $header);
        $this->assertSame($subject, iconv_mime_decode($header, 0, 'UTF-8'));
    }

    public function testAsciiHeaderIsLeftReadable(): void
    {
        $this->assertSame('Your password was changed', MimeBuilder::encodeHeader('Your password was changed'));
    }

    public function testHeaderInjectionIsStrippedRatherThanEncoded(): void
    {
        $this->assertSame('a@b.fr', MimeBuilder::encodeHeader("a@b.fr\r\nBcc: victim@example.fr"));
        $this->assertSame('a@b.fr', MimeBuilder::sanitize("a@b.fr\n\rTo: x@y.z"));
    }

    public function testDotStuffingProtectsTheEndOfDataService(): void
    {
        $this->assertSame("a\r\n..hidden\r\nb", MimeBuilder::stuffDots("a\n.hidden\nb"));
        $this->assertSame('..alone', MimeBuilder::stuffDots('.alone'));
        $this->assertSame("a\r\nb", MimeBuilder::stuffDots("a\r\nb"));
    }

    public function testSplitAddressesKeepsOnlyValidOnes(): void
    {
        $this->assertSame(
            ['a@x.fr', 'bob@y.fr'],
            MimeBuilder::splitAddresses('Alice <a@x.fr>, bob@y.fr, not-an-email, ""'),
        );
        $this->assertSame(['c@d.fr'], MimeBuilder::splitAddresses(['  <c@d.fr>  ', '']));
    }

    public function testPlainTextIsDerivedFromHtmlWithLinksPreserved(): void
    {
        $html = '<style>.x{color:red}</style><h1>Bonjour</h1><p>Un paragraphe <a href="https://x.fr/l">avec un lien</a></p><ul><li>un</li><li>deux</li></ul>';
        $text = MimeBuilder::plainTextFromHtml($html);

        $this->assertStringNotContainsString('color:red', $text);
        $this->assertStringContainsString('avec un lien (https://x.fr/l)', $text);
        $this->assertStringContainsString('- un', $text);
        $this->assertStringContainsString('- deux', $text);
        $this->assertStringNotContainsString('<', $text);
        $this->assertMatchesRegularExpression('/\ABonjour\n/', $text);
    }

    public function testMessageCarriesBothPartsAndNeverTheBccHeader(): void
    {
        $built = MimeBuilder::build(
            new Message(
                to: 'alice@example.fr',
                subject: 'Bienvenue',
                html: '<p>Bonjour</p>',
                text: 'Bonjour',
                bcc: ['hidden@example.fr'],
            ),
            new MailConfig(['from_address' => 'no-reply@prime.test', 'from_name' => 'Prime']),
        );

        $headers = implode("\r\n", $built['headers']);

        $this->assertStringNotContainsString('hidden@example.fr', $headers);
        $this->assertStringContainsString('multipart/alternative', $headers);
        $this->assertContains('hidden@example.fr', $built['recipients']);
        $this->assertContains('alice@example.fr', $built['recipients']);
        $this->assertMatchesRegularExpression('/Message-ID: <[^>]+@prime\.test>/', $headers);
        $this->assertMatchesRegularExpression('/Date: /', $headers);

        [$textPart, $htmlPart] = preg_split('/Content-Type: text\//', $built['body']) ?: [];
        $this->assertIsString($textPart);
        $this->assertIsString($htmlPart);
        $this->assertLessThan(strpos($built['body'], 'text/html'), strpos($built['body'], 'text/plain'), 'le texte simple doit précéder le HTML');
    }

    public function testAnHtmlOnlyMessageStillGainsATextPartAndATextOnlyMessageStaysSimple(): void
    {
        $htmlOnly = MimeBuilder::build(new Message(to: 'a@b.fr', subject: 's', html: '<p>x</p>'), new MailConfig());
        $this->assertStringContainsString('multipart/alternative', implode("\r\n", $htmlOnly['headers']));
        $this->assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $htmlOnly['body']);
        $this->assertStringContainsString('x', $htmlOnly['body']);

        $textOnly = MimeBuilder::build(new Message(to: 'a@b.fr', subject: 's', text: 'x'), new MailConfig());
        $this->assertMatchesRegularExpression('/Content-Type: text\/plain; charset=UTF-8/', implode("\r\n", $textOnly['headers']));
        $this->assertStringNotContainsString('multipart', implode("\r\n", $textOnly['headers']));
    }

    public function testAttachmentsWrapTheAlternativeInMultipartMixed(): void
    {
        $built = MimeBuilder::build(
            new Message(
                to: 'alice@example.fr',
                subject: 'Facture',
                html: '<p>ci-joint</p>',
                text: 'ci-joint',
                attachments: [Attachment::fromContents(str_repeat('Ligne de facture.' . PHP_EOL, 40), 'facture.txt', 'text/plain')],
            ),
            new MailConfig(['from_address' => 'billing@prime.test']),
        );

        $headers = implode("\r\n", $built['headers']);

        $this->assertStringContainsString('multipart/mixed', $headers);
        $this->assertStringContainsString('Content-Type: multipart/alternative; boundary=', $built['body']);
        $this->assertStringContainsString('Content-Disposition: attachment; filename="facture.txt"', $built['body']);
        $this->assertStringContainsString('Content-Transfer-Encoding: base64', $built['body']);
        $this->assertMatchesRegularExpression('/--primemail-[a-f0-9]+--\r\n\z/', $built['body']);

        // Two distinct boundaries: reusing one would terminate the outer multipart at the inner close.
        preg_match_all('/boundary="([^"]+)"/', $headers . $built['body'], $matches);
        $this->assertCount(2, array_unique($matches[1]));
        $this->assertStringEndsWith("--{$matches[1][0]}--\r\n", $built['body']);
    }

    public function testAttachmentNamesAreStrippedOfQuotesAndDirectoryTraversal(): void
    {
        $attachment = Attachment::fromContents('x', '../../etc/"passwd"');

        $this->assertSame('passwd', $attachment->filename);
        $this->assertSame('application/octet-stream', $attachment->mime);
        $this->assertSame(1, $attachment->size());
    }

}
