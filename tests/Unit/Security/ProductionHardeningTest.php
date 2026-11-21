<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prime\Kernel\Application;

/**
 * Security audit (phase 12): `APP_DEBUG=true` left on a production server must not expose stack
 * traces, source excerpts or configuration on error pages.
 */
final class ProductionHardeningTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function environments(): iterable
    {
        yield 'production, debug off' => ['production', false, false];
        yield 'production, debug left on' => ['production', true, false];
        yield 'local, debug on' => ['local', true, true];
        yield 'local, debug off' => ['local', false, false];
    }

    #[DataProvider('environments')]
    public function testErrorDetailsAreNeverShownInProduction(string $env, bool $debug, bool $expected): void
    {
        $app = new Application(dirname(__DIR__, 3), loadEnv: false, registerErrorHandler: false);
        $app->config()->set('app.env', $env);
        $app->config()->set('app.debug', $debug);

        $this->assertSame($expected, $app->showsErrorDetails());
        $this->assertSame($debug, $app->isDebug(), 'the system status still reports the real switch');
    }
}
