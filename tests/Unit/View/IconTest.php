<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use Prime\Kernel\Application;
use Prime\View\Exception\ViewException;
use Prime\View\Icon;

final class IconTest extends TestCase
{
    protected function setUp(): void
    {
        Icon::clearCache();
    }

    public function testRendersValidLucideIconWithEscapedAttributes(): void
    {
        $app = new Application(dirname(__DIR__, 3), loadEnv: false);
        $app->config()->set('app.debug', true);

        $svg = icon('layout-dashboard', 18, 'custom" onclick="alert(1)');

        $this->assertStringContainsString('<svg ', $svg);
        $this->assertStringContainsString('aria-hidden="true"', $svg);
        $this->assertStringContainsString('width="18" height="18"', $svg);
        $this->assertStringContainsString('stroke-width="1.5"', $svg);
        $this->assertStringContainsString('sprite.svg', $svg);
        $this->assertStringContainsString('#layout-dashboard', $svg);
        $this->assertStringContainsString('custom&quot; onclick=&quot;alert(1)', $svg);
        $this->assertStringNotContainsString('onclick="alert(1)"', $svg);
    }

    public function testThrowsClearExceptionInDebugModeWhenIconIsUnknown(): void
    {
        $app = new Application(dirname(__DIR__, 3), loadEnv: false);
        $app->config()->set('app.debug', true);

        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('Unknown icon [nonexistent-icon]');
        icon('nonexistent-icon');
    }

    public function testReturnsEmptyStringInProductionModeWhenIconIsUnknown(): void
    {
        $app = new Application(dirname(__DIR__, 3), loadEnv: false);
        $app->config()->set('app.debug', false);

        $this->assertSame('', icon('nonexistent-icon'));
    }

    public function testSpriteContainsAtLeastFortyIcons(): void
    {
        $names = Icon::names();

        $this->assertGreaterThan(40, count($names));
        $this->assertTrue(Icon::exists('layout-dashboard'));
        $this->assertTrue(Icon::exists('search'));
        $this->assertTrue(Icon::exists('settings'));
    }
}
