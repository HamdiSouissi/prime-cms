<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Plugins;

use PHPUnit\Framework\TestCase;
use Prime\Plugins\AssetPublisher;
use Prime\Plugins\Exception\PluginException;

final class AssetPublisherTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/prime-assets-test-' . bin2hex(random_bytes(6));
        mkdir($this->tmpRoot . '/plugin/assets/css', 0755, true);
        mkdir($this->tmpRoot . '/plugin/assets/.hidden', 0755, true);
        mkdir($this->tmpRoot . '/public', 0755, true);
    }

    protected function tearDown(): void
    {
        AssetPublisher::deleteDirectory($this->tmpRoot);
    }

    public function testPublishesOnlyWhitelistedStaticFilesAndNeverPhpOrDotfiles(): void
    {
        file_put_contents($this->tmpRoot . '/plugin/assets/css/app.css', 'body { color: #000; }');
        file_put_contents($this->tmpRoot . '/plugin/assets/icon.svg', '<svg></svg>');
        file_put_contents($this->tmpRoot . '/plugin/assets/shell.php', '<?php system($_GET["c"]);');
        file_put_contents($this->tmpRoot . '/plugin/assets/.htaccess', 'Deny from all');
        file_put_contents($this->tmpRoot . '/plugin/assets/.hidden/secret.css', 'a{}');

        $publisher = new AssetPublisher($this->tmpRoot . '/public', '/assets/plugins');
        $count = $publisher->publish('demo-plugin', $this->tmpRoot . '/plugin');

        $this->assertSame(2, $count);
        $this->assertTrue(is_file($this->tmpRoot . '/public/demo-plugin/css/app.css'));
        $this->assertTrue(is_file($this->tmpRoot . '/public/demo-plugin/icon.svg'));
        $this->assertFalse(is_file($this->tmpRoot . '/public/demo-plugin/shell.php'));
        $this->assertFalse(is_file($this->tmpRoot . '/public/demo-plugin/.htaccess'));
        $this->assertFalse(is_dir($this->tmpRoot . '/public/demo-plugin/.hidden'));

        $url = $publisher->publicUrl('demo-plugin', '1.2.0', 'css/app.css');
        $this->assertStringStartsWith('/assets/plugins/demo-plugin/css/app.css?v=1.2.0-', $url);

        $publisher->remove('demo-plugin');
        $this->assertFalse(is_dir($this->tmpRoot . '/public/demo-plugin'));
    }

    public function testPublicUrlRejectsPhpAndTraversalPaths(): void
    {
        $publisher = new AssetPublisher($this->tmpRoot . '/public', '/assets/plugins');

        $this->assertFalse(AssetPublisher::isAllowedFile('evil.php'));
        $this->assertFalse(AssetPublisher::isAllowedFile('../etc/passwd'));
        $this->assertFalse(AssetPublisher::isAllowedFile('.env'));
        $this->assertTrue(AssetPublisher::isAllowedFile('css/style.css'));

        $this->expectException(PluginException::class);
        $publisher->publicUrl('demo-plugin', '1.0.0', '../evil.css');
    }
}
