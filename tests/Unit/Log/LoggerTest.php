<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Log;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prime\Log\Logger;
use RuntimeException;

final class LoggerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/prime_logs_' . uniqid('', true);
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

    public function testFiltersEntriesBelowMinimumLevel(): void
    {
        $logger = new Logger($this->tempDir, Logger::WARNING);

        $logger->debug('ignored debug');
        $logger->info('ignored info');
        $logger->notice('ignored notice');
        $logger->warning('kept warning');
        $logger->error('kept error');

        $logFile = $logger->logFilePath();
        $this->assertFileExists($logFile);

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('WARNING: kept warning', $lines[0]);
        $this->assertStringContainsString('ERROR: kept error', $lines[1]);
    }

    public function testInterpolatesPlaceholdersFormatsSingleLineAndSerializesContext(): void
    {
        $logger = new Logger($this->tempDir, Logger::DEBUG);

        $logger->error("User {user} failed\non action {action}", [
            'user' => 'hamdi',
            'action' => 'publish',
            'exception' => new RuntimeException('Disk full'),
        ]);

        $content = file_get_contents($logger->logFilePath());
        $this->assertIsString($content);

        $lines = explode(PHP_EOL, trim($content));
        $this->assertCount(1, $lines);
        $this->assertMatchesRegularExpression('/^\[\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00\] ERROR: User hamdi failed on action publish \{.+\}$/', $lines[0]);
        $this->assertStringContainsString('"class":"RuntimeException"', $lines[0]);
        $this->assertStringContainsString('"message":"Disk full"', $lines[0]);
    }

    public function testThrowsOnInvalidLogLevel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Logger($this->tempDir, 'verbose');
    }
}
