<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Mail;

use PDO;
use PHPUnit\Framework\TestCase;
use Prime\Database\Connection;
use Prime\Hooks\Hooks;
use Prime\Kernel\Config;
use Prime\Mail\MailConfig;
use Prime\Settings\Settings;
use Prime\Tests\Support\MysqlEmulatingPdo;

/**
 * Which of the three sources answers: the database, `config/mail.php`, or the shipped default.
 */
final class MailConfigTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new MysqlEmulatingPdo('name');
        $this->pdo->exec(
            'CREATE TABLE `pc_settings` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `name` TEXT UNIQUE NOT NULL,'
            . ' `value` TEXT NULL, `autoload` INTEGER NOT NULL DEFAULT 1, `created_at` TEXT NOT NULL, `updated_at` TEXT NOT NULL)',
        );
    }

    private function settings(): Settings
    {
        return new Settings(new Connection(['prefix' => 'pc_'], hooks: new Hooks(), debug: false, pdo: $this->pdo));
    }

    public function testTheDatabaseWinsOverTheConfigFile(): void
    {
        $settings = $this->settings();
        $settings->set(MailConfig::SETTING_HOST, 'smtp.fourneur.fr');
        $settings->set(MailConfig::SETTING_PORT, 465);

        $config = new Config(['mail' => ['host' => 'env.test', 'port' => 587, 'from' => ['address' => 'from@env.test', 'name' => 'Env']]]);

        $resolved = MailConfig::resolve($settings, $config);

        $this->assertSame('smtp.fourneur.fr', $resolved->host());
        $this->assertSame(465, $resolved->port());
        $this->assertSame('from@env.test', $resolved->fromAddress(), 'un réglage absent retombe sur config/mail.php');
    }

    public function testAnEmptyDatabaseValueFallsThroughInsteadOfShadowingTheConfigFile(): void
    {
        $settings = $this->settings();
        $settings->set(MailConfig::SETTING_HOST, '');
        $settings->set(MailConfig::SETTING_REPLY_TO, '');

        $resolved = MailConfig::resolve($settings, new Config(['mail' => ['host' => 'relay.internal', 'reply_to' => 'reply@x.fr']]));

        $this->assertSame('relay.internal', $resolved->host());
        $this->assertSame('reply@x.fr', $resolved->replyTo());
    }

    public function testAPasswordStoredAsPlainTextIsStillReadable(): void
    {
        $settings = $this->settings();
        $settings->set(MailConfig::SETTING_PASSWORD, '  secret de passe  ');

        $this->assertSame('secret de passe', MailConfig::resolve($settings, new Config())->password());
    }

    public function testWithoutAnySourceTheDocumentedDefaultsHold(): void
    {
        $config = MailConfig::resolve();

        $this->assertSame('log', $config->driver());
        $this->assertSame('no-reply@prime-cms.local', $config->fromAddress());
        $this->assertSame(587, $config->port());
        $this->assertSame('tls', $config->encryption());
        $this->assertTrue($config->verifyPeer());
        $this->assertTrue($config->logEnabled());
        $this->assertSame(30, $config->logDays());
        $this->assertSame('prime-cms.local', $config->domain());
    }

    public function testEncryptionSynonymsAreNormalised(): void
    {
        $this->assertSame('tls', (new MailConfig(['encryption' => 'STARTTLS ']))->encryption());
        $this->assertSame('ssl', (new MailConfig(['encryption' => 'smtps']))->encryption());
        $this->assertSame('', (new MailConfig(['encryption' => 'none']))->encryption());
        $this->assertSame('ssl', (new MailConfig(['encryption' => 'SSL']))->encryption());
    }

    public function testNumericBoundsAreClampedAndVerificationIsOnByDefault(): void
    {
        $config = new MailConfig(['timeout' => 900, 'log_days' => 0, 'verify_peer' => false]);

        $this->assertSame(120, $config->timeout());
        $this->assertSame(1, $config->logDays());
        $this->assertFalse($config->verifyPeer());
    }

    public function testSmtpNeedsAHostAndAMatchBetweenCredentials(): void
    {
        $complete = new MailConfig(['driver' => 'smtp', 'host' => 'smtp.test', 'from_address' => 'a@b.fr', 'username' => 'u', 'password' => 'p']);
        $this->assertTrue($complete->isSmtpConfigured());

        $anonymousRelay = new MailConfig(['driver' => 'smtp', 'host' => 'smtp.test', 'from_address' => 'a@b.fr']);
        $this->assertTrue($anonymousRelay->isSmtpConfigured(), 'un relais interne sans identifiants est une configuration valide');

        $halfAuth = new MailConfig(['driver' => 'smtp', 'host' => 'smtp.test', 'from_address' => 'a@b.fr', 'username' => 'u']);
        $this->assertFalse($halfAuth->isSmtpConfigured());

        $badFrom = new MailConfig(['driver' => 'smtp', 'host' => 'smtp.test', 'from_address' => 'pas-une-adresse', 'username' => 'u', 'password' => 'p']);
        $this->assertFalse($badFrom->isSmtpConfigured());

        $this->assertTrue((new MailConfig(['driver' => 'log']))->isSmtpConfigured(), 'un site sans SMTP na pas de problème de SMTP');
    }

    public function testQueueCountsAsSmtpAndRedactsThePassword(): void
    {
        $config = new MailConfig(['driver' => 'queue', 'host' => 'smtp.test', 'password' => 'secret', 'username' => 'u', 'from_address' => 'a@b.fr']);

        $this->assertTrue($config->usesQueue());
        $this->assertTrue($config->usesSmtp());
        $this->assertSame('••••••••', $config->redacted()['password']);
        $this->assertStringNotContainsString('secret', json_encode($config->redacted(), JSON_UNESCAPED_UNICODE) ?: '');

        $this->assertFalse((new MailConfig(['driver' => 'log']))->usesSmtp());
        $this->assertSame('', (new MailConfig())->redacted()['password']);
    }

    public function testWithKeepsTheOriginalObjectIntact(): void
    {
        $config = new MailConfig(['host' => 'a.test']);

        $this->assertSame('b.test', $config->with(['host' => 'b.test'])->host());
        $this->assertSame('a.test', $config->host());
    }
}
