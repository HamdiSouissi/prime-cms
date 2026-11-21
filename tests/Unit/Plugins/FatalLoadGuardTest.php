<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Plugins;

use PHPUnit\Framework\TestCase;
use Prime\Plugins\FatalLoadGuard;

/**
 * Prime CMS 1.0.2 — the fatal witness must tell a crashed request from a concurrent one.
 */
final class FatalLoadGuardTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/prime_guard_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testAConcurrentRequestStillLoadingIsNotReportedAsACrash(): void
    {
        $requestA = new FatalLoadGuard($this->dir . '/loading.json');
        $requestB = new FatalLoadGuard($this->dir . '/loading.json');

        $seenByB = $requestA->guard('prime-starter', 'register', static fn (): ?array => $requestB->consumePendingCrash());

        $this->assertNull($seenByB, 'A request in progress (lock held) is not a crash');
        $this->assertNull($requestB->readWitness(), 'Request A cleaned its own witness on normal return');
        $this->assertSame([], glob($this->dir . '/*') ?: []);
    }

    public function testAWitnessAbandonedByADeadRequestIsReportedOnceAndRemoved(): void
    {
        // The temporary guard is destroyed without clear(): like a process killed mid-load.
        (new FatalLoadGuard($this->dir . '/loading.json'))->markLoading('broken-plugin', 'boot');

        $next = new FatalLoadGuard($this->dir . '/loading.json');
        $crash = $next->consumePendingCrash();

        $this->assertNotNull($crash);
        $this->assertSame('broken-plugin', $crash['slug']);
        $this->assertSame('boot', $crash['phase']);
        $this->assertNull($next->consumePendingCrash(), 'Reported only once');
        $this->assertSame([], glob($this->dir . '/*') ?: []);
    }

    public function testALegacySharedWitnessFromAnOlderVersionIsStillRecovered(): void
    {
        file_put_contents($this->dir . '/loading.json', '{"slug":"old-crash","phase":"activate","started_at":"2026-11-21 10:00:00"}');

        $crash = (new FatalLoadGuard($this->dir . '/loading.json'))->consumePendingCrash();

        $this->assertSame('old-crash', $crash['slug'] ?? null);
        $this->assertFileDoesNotExist($this->dir . '/loading.json');
    }

    public function testAProcessKilledWhileHoldingTheLockIsDetectedFromAnotherProcess(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is not available.');
        }

        // A child PHP process marks a load, holds the lock, then is killed with SIGKILL.
        $code = sprintf(
            'require %s; $g = new Prime\Plugins\FatalLoadGuard(%s); $g->markLoading("killed-plugin", "boot"); fwrite(STDOUT, "ready\n"); fflush(STDOUT); sleep(30);',
            var_export(dirname(__DIR__, 3) . '/src/Plugins/FatalLoadGuard.php', true),
            var_export($this->dir . '/loading.json', true),
        );
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $this->assertSame("ready\n", fgets($pipes[1]));

        $observer = new FatalLoadGuard($this->dir . '/loading.json');
        $this->assertNull($observer->consumePendingCrash(), 'While the child runs, its witness is not a crash');

        proc_terminate($process, 9);
        fclose($pipes[1]);
        proc_close($process);

        $crash = $observer->consumePendingCrash();
        $this->assertSame('killed-plugin', $crash['slug'] ?? null, 'Once killed, the OS released the lock: crash detected');
    }
}
