<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Kernel;

use PHPUnit\Framework\TestCase;
use Prime\Kernel\Autoloader;

final class AutoloaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/prime_autoloader_' . uniqid('', true);
        mkdir($this->tempDir . '/dirA', 0775, true);
        mkdir($this->tempDir . '/dirB', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testLoadsClassFromMappedNamespace(): void
    {
        $uniqueClass = 'FixtureOne_' . bin2hex(random_bytes(4));
        $file = $this->tempDir . '/dirA/' . $uniqueClass . '.php';
        file_put_contents($file, "<?php\nnamespace PrimeTestNs;\nfinal class {$uniqueClass} { public static string \$source = 'A'; }\n");

        $autoloader = new Autoloader();
        $autoloader->addNamespace('PrimeTestNs\\', $this->tempDir . '/dirA');

        $fqcn = 'PrimeTestNs\\' . $uniqueClass;
        $this->assertTrue($autoloader->loadClass($fqcn));
        $this->assertTrue(class_exists($fqcn, false));
        $this->assertSame('A', $fqcn::$source);
    }

    public function testSupportsMultipleDirectoriesAndPrependPriority(): void
    {
        $uniqueClass = 'FixturePriority_' . bin2hex(random_bytes(4));
        file_put_contents(
            $this->tempDir . '/dirA/' . $uniqueClass . '.php',
            "<?php\nnamespace PrimePriorityNs;\nfinal class {$uniqueClass} { public static string \$source = 'A'; }\n",
        );
        file_put_contents(
            $this->tempDir . '/dirB/' . $uniqueClass . '.php',
            "<?php\nnamespace PrimePriorityNs;\nfinal class {$uniqueClass} { public static string \$source = 'B'; }\n",
        );

        $autoloader = new Autoloader();
        $autoloader->addNamespace('PrimePriorityNs', $this->tempDir . '/dirA');
        $autoloader->addNamespace('PrimePriorityNs', $this->tempDir . '/dirB', prepend: true);

        $fqcn = 'PrimePriorityNs\\' . $uniqueClass;
        $this->assertTrue($autoloader->loadClass($fqcn));
        $this->assertSame('B', $fqcn::$source);
    }

    public function testReturnsFalseForUnknownNamespaceOrMissingFile(): void
    {
        $autoloader = new Autoloader();
        $autoloader->addNamespace('PrimeKnown\\', $this->tempDir . '/dirA');

        $this->assertFalse($autoloader->loadClass('PrimeKnown\\DoesNotExist'));
        $this->assertFalse($autoloader->loadClass('OtherNs\\Missing'));
        $this->assertFalse($autoloader->loadClass('GlobalClassWithoutNamespace'));
    }

    public function testRegisterAndUnregisterOnSplStack(): void
    {
        $uniqueClass = 'FixtureSpl_' . bin2hex(random_bytes(4));
        file_put_contents(
            $this->tempDir . '/dirA/' . $uniqueClass . '.php',
            "<?php\nnamespace PrimeSplNs;\nfinal class {$uniqueClass} {}\n",
        );

        $autoloader = new Autoloader();
        $autoloader->addNamespace('PrimeSplNs\\', $this->tempDir . '/dirA');
        $autoloader->register();

        try {
            $fqcn = 'PrimeSplNs\\' . $uniqueClass;
            $this->assertTrue(class_exists($fqcn));
        } finally {
            $autoloader->unregister();
        }

        $stillRegistered = false;
        foreach (spl_autoload_functions() as $fn) {
            if (is_array($fn) && $fn[0] === $autoloader) {
                $stillRegistered = true;
            }
        }

        $this->assertFalse($stillRegistered);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
