<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use Prime\Install\RequirementsChecker;
use Prime\Kernel\Application;

final class RequirementsCheckerTest extends TestCase
{
    public function testDetectsBlockingPhpAndExtensionFailuresWithFixInstructions(): void
    {
        $app = new Application(dirname(__DIR__, 3), loadEnv: false);
        $checker = new RequirementsChecker($app);

        // Simulate PHP 8.1 and missing pdo_mysql + intl.
        $report = $checker->check(
            phpVersion: '8.1.27',
            extensionLoader: static fn (string $ext): bool => !in_array($ext, ['pdo_mysql', 'intl'], true),
        );

        $this->assertFalse($report['passed']);
        $this->assertGreaterThanOrEqual(2, $report['blocking_errors']);
        $this->assertGreaterThanOrEqual(1, $report['warnings']);

        $byId = [];
        foreach ($report['checks'] as $check) {
            $byId[$check['id']] = $check;
        }

        $this->assertSame('error', $byId['php.version']['status']);
        $this->assertNotNull($byId['php.version']['fix']);
        $this->assertSame('error', $byId['ext.pdo_mysql']['status']);
        $this->assertTrue($byId['ext.pdo_mysql']['blocking']);
        $this->assertNotNull($byId['ext.pdo_mysql']['fix']);

        // `intl` is optional: warning only, non-blocking.
        $this->assertSame('warning', $byId['ext.intl']['status']);
        $this->assertFalse($byId['ext.intl']['blocking']);
    }

    public function testPassesWhenPhpExtensionsAndWritablePathsAreSatisfied(): void
    {
        $app = new Application(dirname(__DIR__, 3), loadEnv: false);
        $checker = new RequirementsChecker($app);

        $report = $checker->check(
            phpVersion: '8.3.10',
            extensionLoader: static fn (string $ext): bool => true,
        );

        $this->assertTrue($report['passed']);
        $this->assertSame(0, $report['blocking_errors']);
    }
}
