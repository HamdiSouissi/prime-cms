<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use Prime\Install\EnvWriter;
use Prime\Kernel\Env;

final class EnvWriterTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/prime_env_writer_' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            foreach (scandir($this->tempDir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $full = $this->tempDir . '/' . $entry;
                chmod($full, 0644);
                unlink($full);
            }
            rmdir($this->tempDir);
        }
    }

    public function testGeneratesBase64AppKeyWith256BitEntropy(): void
    {
        $writer = new EnvWriter();
        $key1 = $writer->generateAppKey();
        $key2 = $writer->generateAppKey();

        $this->assertStringStartsWith('base64:', $key1);
        $decoded = base64_decode(substr($key1, 7), true);
        $this->assertIsString($decoded);
        $this->assertSame(32, strlen($decoded));
        $this->assertNotSame($key1, $key2);
    }

    public function testCreatesAndUpdatesEnvPreservingCommentsUnrelatedKeysAndEscapingSpecialCharacters(): void
    {
        $writer = new EnvWriter();

        $existing = <<<'ENV'
# Header comment preserved
APP_NAME="Old Name"
CUSTOM_FLAG=keep_me

# Database section
# DB_HOST=db
# DB_PASSWORD=secret
ENV;

        $trickyPassword = 'p@ss #1 "$special\\end';
        $rendered = $writer->render([
            'APP_NAME' => 'Mon Site #1',
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'DB_HOST' => 'mysql.internal',
            'DB_PASSWORD' => $trickyPassword,
        ], $existing);

        // Comments and unrelated keys stay intact.
        $this->assertStringContainsString('# Header comment preserved', $rendered);
        $this->assertStringContainsString('# Database section', $rendered);
        $this->assertStringContainsString('CUSTOM_FLAG=keep_me', $rendered);

        // Commented template lines are activated in place rather than duplicated.
        $this->assertStringNotContainsString('# DB_HOST=db', $rendered);
        $this->assertStringContainsString('DB_HOST=mysql.internal', $rendered);

        // Round-trip through Prime's own Env::parse() preserves every special character verbatim!
        $parsed = Env::parse($rendered);
        $this->assertSame('Mon Site #1', $parsed['APP_NAME']);
        $this->assertSame('production', $parsed['APP_ENV']);
        $this->assertSame('false', $parsed['APP_DEBUG']);
        $this->assertSame('keep_me', $parsed['CUSTOM_FLAG']);
        $this->assertSame('mysql.internal', $parsed['DB_HOST']);
        $this->assertSame($trickyPassword, $parsed['DB_PASSWORD']);
    }

    public function testWritesFileToDiskAndDetectsReadOnlyTarget(): void
    {
        $writer = new EnvWriter();
        $envFile = $this->tempDir . '/.env';

        $this->assertTrue($writer->isWritable($envFile));
        $this->assertTrue($writer->write($envFile, [
            'APP_NAME' => 'Prime Test',
            'APP_KEY' => $writer->generateAppKey(),
        ]));
        $this->assertFileExists($envFile);

        $parsed = Env::parse((string) file_get_contents($envFile));
        $this->assertSame('Prime Test', $parsed['APP_NAME']);
        $this->assertStringStartsWith('base64:', $parsed['APP_KEY']);

        // Make file read-only (only enforced when not running as root).
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            chmod($envFile, 0444);
            $this->assertFalse($writer->isWritable($envFile));
            $this->assertFalse($writer->write($envFile, ['APP_NAME' => 'Blocked']));
        }
    }
}
