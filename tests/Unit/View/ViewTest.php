<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use Prime\Hooks\Hooks;
use Prime\View\Exception\ViewException;
use Prime\View\Exception\ViewNotFoundException;
use Prime\View\View;

final class ViewTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/prime_views_' . uniqid('', true);
        mkdir($this->tempDir . '/layouts', 0775, true);
        mkdir($this->tempDir . '/partials', 0775, true);
        mkdir($this->tempDir . '/plugin_views', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testRendersLayoutsSectionsPartialsAndEscaping(): void
    {
        file_put_contents(
            $this->tempDir . '/layouts/app.php',
            '<html><head><title><?= $this->e($title) ?></title></head><body><?= $this->section("content") ?></body></html>',
        );
        file_put_contents(
            $this->tempDir . '/partials/badge.php',
            '<span class="badge"><?= $this->e($label) ?></span>',
        );
        file_put_contents(
            $this->tempDir . '/page.php',
            '<?php $this->layout("layouts.app", ["title" => $pageTitle]); ?>'
            . '<?php $this->start("content"); ?>'
            . '<h1><?= $this->e($unsafe) ?></h1>'
            . '<?= $this->raw($trustedHtml) ?>'
            . '<?= $this->insert("partials.badge", ["label" => "Prime"]) ?>'
            . '<?php $this->stop(); ?>',
        );

        $view = new View($this->tempDir);
        $html = $view->render('page', [
            'pageTitle' => 'Accueil <Prime>',
            'unsafe' => '<script>alert(1)</script>',
            'trustedHtml' => '<strong>Safe</strong>',
        ]);

        $this->assertSame(
            '<html><head><title>Accueil &lt;Prime&gt;</title></head><body><h1>&lt;script&gt;alert(1)&lt;/script&gt;</h1><strong>Safe</strong><span class="badge">Prime</span></body></html>',
            $html,
        );
    }

    public function testSupportsNamespacedViewsAndViewDataFilter(): void
    {
        file_put_contents(
            $this->tempDir . '/plugin_views/panel.php',
            '<div><?= $this->e($greeting) ?> <?= $this->e($injected) ?></div>',
        );

        $hooks = new Hooks();
        $hooks->filter('view.data', static function (array $data, string $template): array {
            if ($template === 'admin::panel') {
                $data['injected'] = 'CMS';
            }

            return $data;
        });

        $view = new View($this->tempDir, $hooks);
        $view->addNamespace('admin', $this->tempDir . '/plugin_views');

        $this->assertTrue($view->exists('admin::panel'));
        $this->assertSame('<div>Prime CMS</div>', $view->render('admin::panel', ['greeting' => 'Prime']));
    }

    public function testThrowsOnMissingViewAndPathTraversal(): void
    {
        $view = new View($this->tempDir);

        try {
            $view->render('does.not.exist');
            $this->fail('Expected ViewNotFoundException.');
        } catch (ViewNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(ViewException::class);
        $view->render('../etc/passwd');
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
