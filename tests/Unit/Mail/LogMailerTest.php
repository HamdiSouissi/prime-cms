<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Mail;

use PHPUnit\Framework\TestCase;
use Prime\Mail\LogMailer;
use Prime\Mail\Message;

final class LogMailerTest extends TestCase
{
    public function testWritesFormattedMessageToDailyMailLogFile(): void
    {
        $dir = sys_get_temp_dir() . '/prime_mail_test_' . uniqid('', true);
        mkdir($dir, 0755, true);

        try {
            $mailer = new LogMailer($dir, 'no-reply@example.fr', 'Prime CMS');
            $mailer->send(new Message(
                to: 'alice@example.fr',
                subject: 'Réinitialisation de mot de passe',
                html: '<p>Cliquez ici</p>',
                text: 'Cliquez ici',
            ));

            $files = glob($dir . '/mail-*.log') ?: [];
            $this->assertCount(1, $files);
            $content = (string) file_get_contents($files[0]);
            $this->assertStringContainsString('To: alice@example.fr', $content);
            $this->assertStringContainsString('Subject: Réinitialisation de mot de passe', $content);
            $this->assertStringContainsString('Cliquez ici', $content);
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }
}
