<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Kernel;

use ErrorException;
use PHPUnit\Framework\TestCase;
use Prime\Http\Request;
use Prime\Kernel\ErrorHandler;
use Prime\Log\Logger;
use Prime\View\View;
use RuntimeException;

final class ErrorHandlerTest extends TestCase
{
    private string $tempLogDir;

    protected function setUp(): void
    {
        $this->tempLogDir = sys_get_temp_dir() . '/prime_err_logs_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempLogDir)) {
            $files = glob($this->tempLogDir . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    unlink($file);
                }
            }
            rmdir($this->tempLogDir);
        }
    }

    public function testConvertsPhpErrorIntoErrorException(): void
    {
        $handler = $this->createHandler(debug: true);
        $previous = error_reporting(E_ALL);

        try {
            $this->expectException(ErrorException::class);
            $this->expectExceptionMessage('Synthetic warning');

            $handler->handlePhpError(E_USER_WARNING, 'Synthetic warning', __FILE__, __LINE__);
        } finally {
            error_reporting($previous);
        }
    }

    public function testIgnoresSuppressedPhpErrors(): void
    {
        $handler = $this->createHandler(debug: true);
        $previous = error_reporting(0);

        try {
            $result = $handler->handlePhpError(E_USER_WARNING, 'Suppressed warning', __FILE__, __LINE__);
            $this->assertFalse($result);
        } finally {
            error_reporting($previous);
        }
    }

    public function testExtractsSurroundingCodeExcerptWithHighlight(): void
    {
        $handler = $this->createHandler(debug: true);
        $excerpt = $handler->extractCodeExcerpt(__FILE__, 10, 2);

        $this->assertCount(5, $excerpt);
        $this->assertSame(8, $excerpt[0]['number']);
        $this->assertFalse($excerpt[0]['highlight']);
        $this->assertSame(10, $excerpt[2]['number']);
        $this->assertTrue($excerpt[2]['highlight']);
        $this->assertSame(12, $excerpt[4]['number']);
    }

    public function testJsonErrorRespectsDebugFlag(): void
    {
        $request = Request::create('GET', '/api/fail', headers: ['Accept' => 'application/json']);

        $prodHandler = $this->createHandler(debug: false);
        $prodResponse = $prodHandler->handleException(new RuntimeException('Internal secret'), $request);
        /** @var array{error: array<string, mixed>} $prodPayload */
        $prodPayload = json_decode($prodResponse->body(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(500, $prodResponse->status());
        $this->assertSame('Erreur interne du serveur.', $prodPayload['error']['message']);
        $this->assertArrayNotHasKey('exception', $prodPayload['error']);

        $devHandler = $this->createHandler(debug: true);
        $devResponse = $devHandler->handleException(new RuntimeException('Internal secret'), $request);
        /** @var array{error: array<string, mixed>} $devPayload */
        $devPayload = json_decode($devResponse->body(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(500, $devResponse->status());
        $this->assertSame('Internal secret', $devPayload['error']['message']);
        $this->assertSame(RuntimeException::class, $devPayload['error']['exception']);
    }

    public function testCliUncaughtExceptionPrintsSingleCleanBlockWithOptionalDebugTrace(): void
    {
        $prodStream = fopen('php://memory', 'w+b');
        $this->assertIsResource($prodStream);

        $logger = new Logger($this->tempLogDir, Logger::DEBUG);
        $view = new View(dirname(__DIR__, 3) . '/resources/views');

        $prodHandler = new ErrorHandler($logger, $view, debug: false, cliErrorStream: $prodStream, terminateOnCli: false);
        $prodHandler->handleUncaughtException(new RuntimeException('CLI failure prod'));
        // Calling a second time (e.g. from shutdown) must never duplicate output.
        $prodHandler->handleUncaughtException(new RuntimeException('CLI failure prod'));

        rewind($prodStream);
        $prodOut = (string) stream_get_contents($prodStream);
        fclose($prodStream);

        $this->assertSame(1, substr_count($prodOut, '[RuntimeException] CLI failure prod'));
        $this->assertStringContainsString('at ' . __FILE__ . ':', $prodOut);
        $this->assertStringNotContainsString('Stack trace:', $prodOut);

        $devStream = fopen('php://memory', 'w+b');
        $this->assertIsResource($devStream);

        $devHandler = new ErrorHandler($logger, $view, debug: true, cliErrorStream: $devStream, terminateOnCli: false);
        $devHandler->handleUncaughtException(new RuntimeException('CLI failure debug'));

        rewind($devStream);
        $devOut = (string) stream_get_contents($devStream);
        fclose($devStream);

        $this->assertSame(1, substr_count($devOut, '[RuntimeException] CLI failure debug'));
        $this->assertStringContainsString('Stack trace:', $devOut);
    }

    private function createHandler(bool $debug): ErrorHandler
    {
        $logger = new Logger($this->tempLogDir, Logger::DEBUG);
        $view = new View(dirname(__DIR__, 3) . '/resources/views');

        return new ErrorHandler($logger, $view, $debug);
    }
}
