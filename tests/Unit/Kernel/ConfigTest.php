<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Kernel;

use PHPUnit\Framework\TestCase;
use Prime\Kernel\Config;
use RuntimeException;

final class ConfigTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/prime_config_' . uniqid('', true);
        mkdir($this->tempDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    unlink($file);
                }
            }
            rmdir($this->tempDir);
        }
    }

    public function testLoadsAllPhpFilesFromDirectory(): void
    {
        file_put_contents($this->tempDir . '/app.php', "<?php\nreturn ['name' => 'Prime CMS', 'nested' => ['flag' => true]];\n");
        file_put_contents($this->tempDir . '/logging.php', "<?php\nreturn ['level' => 'warning'];\n");

        $config = Config::loadFromDirectory($this->tempDir);

        $this->assertSame('Prime CMS', $config->get('app.name'));
        $this->assertTrue($config->get('app.nested.flag'));
        $this->assertSame('warning', $config->get('logging.level'));
        $this->assertSame('fallback', $config->get('app.unknown.key', 'fallback'));
    }

    public function testSetHasAndAllWithDotNotation(): void
    {
        $config = new Config(['app' => ['name' => 'Prime CMS', 'nullable' => null]]);

        $this->assertTrue($config->has('app.name'));
        $this->assertTrue($config->has('app.nullable'));
        $this->assertFalse($config->has('app.missing'));

        $config->set('mail.smtp.host', 'smtp.example.test');
        $config->set('mail.smtp.port', 587);

        $this->assertTrue($config->has('mail.smtp.host'));
        $this->assertSame('smtp.example.test', $config->get('mail.smtp.host'));
        $this->assertSame(587, $config->get('mail.smtp.port'));

        $all = $config->all();
        $this->assertArrayHasKey('app', $all);
        $this->assertArrayHasKey('mail', $all);
    }

    public function testThrowsWhenConfigFileDoesNotReturnArray(): void
    {
        file_put_contents($this->tempDir . '/broken.php', "<?php\nreturn 'not-an-array';\n");

        $this->expectException(RuntimeException::class);
        Config::loadFromDirectory($this->tempDir);
    }
}
