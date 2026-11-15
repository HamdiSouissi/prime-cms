<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Admin\Assets;

use PHPUnit\Framework\TestCase;
use Prime\Admin\Assets\CssBuilder;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleApplication;
use Prime\Console\ConsoleOutput;
use Prime\Kernel\Application;
use RuntimeException;

final class CssBuilderTest extends TestCase
{
    public function testBuildsAdminCssFromManifestInOrder(): void
    {
        $tmpDir = sys_get_temp_dir() . '/prime-css-test-' . bin2hex(random_bytes(4));
        mkdir($tmpDir . '/src', 0775, true);

        file_put_contents($tmpDir . '/src/a.css', "/* comment */\n:root { --brand: #ED6647; }\n");
        file_put_contents($tmpDir . '/src/b.css', ".box { width: calc(100% - 24px); font-family: \"Plus Jakarta Sans\", sans-serif; }\n.nav :focus { outline: 2px solid var(--brand); }\n");
        file_put_contents($tmpDir . '/manifest.php', "<?php return ['a.css', 'b.css'];\n");

        $outputPath = $tmpDir . '/out/admin.css';
        $builder = new CssBuilder();
        $result = $builder->build($tmpDir . '/manifest.php', $tmpDir . '/src', $outputPath);

        $this->assertSame(['a.css', 'b.css'], $result['files']);
        $this->assertGreaterThan(0, $result['bytes']);
        $compiled = (string) file_get_contents($outputPath);

        $this->assertStringNotContainsString('/* comment */', $compiled);
        $this->assertStringContainsString('--brand:#ED6647', $compiled);
        $this->assertStringContainsString('calc(100% - 24px)', $compiled);
        $this->assertStringContainsString('"Plus Jakarta Sans"', $compiled);
        $this->assertStringContainsString('.nav :focus{', $compiled);
        $this->assertTrue(strpos($compiled, ':root') < strpos($compiled, '.box'));

        // Clean up temp directory.
        @unlink($outputPath);
        @rmdir($tmpDir . '/out');
        @unlink($tmpDir . '/src/a.css');
        @unlink($tmpDir . '/src/b.css');
        @rmdir($tmpDir . '/src');
        @unlink($tmpDir . '/manifest.php');
        @rmdir($tmpDir);
    }

    public function testThrowsWhenManifestSourceFileIsMissing(): void
    {
        $tmpDir = sys_get_temp_dir() . '/prime-css-missing-' . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0775, true);
        file_put_contents($tmpDir . '/manifest.php', "<?php return ['missing.css'];\n");

        try {
            $builder = new CssBuilder();
            $this->expectException(RuntimeException::class);
            $builder->build($tmpDir . '/manifest.php', $tmpDir, $tmpDir . '/out.css');
        } finally {
            @unlink($tmpDir . '/manifest.php');
            @rmdir($tmpDir);
        }
    }

    public function testConsoleAssetsBuildCommandSucceedsAndMatchesCommittedFile(): void
    {
        $root = dirname(__DIR__, 4);
        $target = $root . '/public/assets/admin/css/admin.css';
        $before = is_file($target) ? (string) file_get_contents($target) : '';

        $app = new Application($root, loadEnv: false);
        $app->boot();

        $console = new ConsoleApplication($app);
        $output = new ConsoleOutput(buffered: true);
        $exitCode = $console->run(['bin/prime', 'assets:build'], $output);

        $this->assertSame(CommandInterface::SUCCESS, $exitCode);
        $this->assertStringContainsString('Assets CSS compilés', $output->stdout());
        $this->assertFileExists($target);
        $after = (string) file_get_contents($target);
        $this->assertNotSame('', $after);

        if ($before !== '') {
            $this->assertSame($before, $after, 'Committed admin.css must match assets:build output.');
        }
    }
}
