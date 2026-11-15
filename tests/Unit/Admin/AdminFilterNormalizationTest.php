<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Prime\Admin\Data\AdminAssets;
use Prime\Admin\Data\CommandPaletteItem;
use Prime\Admin\Data\CreateMenuItem;
use Prime\Admin\Data\DashboardShortcut;
use Prime\Admin\Data\DashboardStat;
use Prime\Admin\Menu\AdminMenu;
use Prime\Admin\Menu\MenuItem;
use Prime\Admin\Notice;
use Prime\Hooks\Hooks;

final class AdminFilterNormalizationTest extends TestCase
{
    public function testDashboardShortcutNormalizesValidAndRejectsInvalidPayloads(): void
    {
        $valid = DashboardShortcut::fromArray([
            'title' => 'Hello Prime',
            'description' => 'Configurer le plugin.',
            'icon' => 'sparkles',
            'accent' => 'violet',
            'url' => '/admin/hello-prime',
        ]);
        $this->assertNotNull($valid);
        $this->assertSame('Hello Prime', $valid->title);
        $this->assertSame('/admin/hello-prime', $valid->url);

        // Missing `title` (using `label` instead), bad types, unsafe URL, scalar
        $this->assertNull(DashboardShortcut::fromArray(['label' => 'Wrong key', 'url' => '/admin/x']));
        $this->assertNull(DashboardShortcut::fromArray(['title' => 123, 'url' => '/admin/x']));
        $this->assertNull(DashboardShortcut::fromArray(['title' => 'XSS', 'url' => 'javascript:alert(1)']));
        $this->assertNull(DashboardShortcut::fromArray(['title' => 'Bad accent', 'url' => '/admin/x', 'accent' => 'neon']));
        $this->assertNull(DashboardShortcut::fromArray('not-an-array'));
    }

    public function testDashboardStatNormalizesValidAndRejectsInvalidPayloads(): void
    {
        $valid = DashboardStat::fromArray([
            'label' => 'Commandes',
            'value' => 42,
            'icon' => 'shopping-bag',
            'accent' => 'success',
            'trend' => '+12%',
            'trendDirection' => 'up',
            'hint' => 'Voir les commandes',
            'url' => '/admin/orders',
        ]);
        $this->assertNotNull($valid);
        $this->assertSame('42', $valid->value);
        $this->assertSame('up', $valid->trendDirection);

        $this->assertNull(DashboardStat::fromArray(['label' => 'Missing value']));
        $this->assertNull(DashboardStat::fromArray(['label' => 'Bad value', 'value' => ['nested']]));
        $this->assertNull(DashboardStat::fromArray(['label' => 'Bad dir', 'value' => '1', 'trendDirection' => 'sideways']));
        $this->assertNull(DashboardStat::fromArray(['label' => 'Bad url', 'value' => '1', 'url' => 'javascript:void(0)']));
        $this->assertNull(DashboardStat::fromArray(null));
    }

    public function testCreateMenuAndCommandPaletteAndAssetsAndMenuAndNoticeValidation(): void
    {
        $create = CreateMenuItem::fromArray([
            'id' => 'create.product',
            'label' => 'Nouveau produit',
            'url' => '/admin/products/create',
            'capability' => 'commerce.manage',
        ]);
        $this->assertNotNull($create);
        $this->assertSame('commerce.manage', $create->capability);
        $this->assertNull(CreateMenuItem::fromArray(['label' => 'Missing id', 'url' => '/admin/x']));
        $this->assertNull(CreateMenuItem::fromArray(['id' => 'x', 'label' => 'XSS', 'url' => 'javascript:alert(1)']));

        $palette = CommandPaletteItem::fromArray([
            'id' => 'cmd.hello',
            'label' => 'Ouvrir Hello Prime',
            'url' => '/admin/hello-prime',
            'keywords' => ['hello', 'plugin'],
        ]);
        $this->assertNotNull($palette);
        $this->assertSame(['hello', 'plugin'], $palette->keywords);
        $this->assertNull(CommandPaletteItem::fromArray([
            'id' => 'cmd.bad',
            'label' => 'Bad keywords',
            'url' => '/admin/hello-prime',
            'keywords' => 'not-an-array',
        ]));

        $fallback = new AdminAssets(['/assets/admin/css/admin.css'], ['/assets/admin/js/admin.js']);
        $warnings = [];
        $assets = AdminAssets::fromArray(
            [
                'css' => ['/assets/admin/css/admin.css', 'javascript:alert(1)', 42],
                'js' => ['/assets/admin/js/admin.js', '/assets/plugins/hello/app.js'],
            ],
            $fallback,
            static function (string $reason) use (&$warnings): void {
                $warnings[] = $reason;
            },
        );
        $this->assertSame(['/assets/admin/css/admin.css'], $assets->css);
        $this->assertSame(['/assets/admin/js/admin.js', '/assets/plugins/hello/app.js'], $assets->js);
        $this->assertCount(2, $warnings);

        // AdminMenu::sanitize removes unsafe URLs
        $menu = new AdminMenu();
        $menu->add(new MenuItem('good', 'Valide', 'home', '/admin/good'));
        $menu->add(new MenuItem('bad', 'XSS', 'home', 'javascript:alert(1)'));
        $issues = $menu->sanitize();
        $this->assertCount(1, $issues);
        $this->assertNotNull($menu->get('good'));
        $this->assertNull($menu->get('bad'));

        // Notice::fromArray rejects unsafe action_url and non-arrays
        $this->assertNull(Notice::fromArray('scalar'));
        $this->assertNull(Notice::fromArray([
            'id' => 'bad.notice',
            'type' => Notice::TYPE_INFO,
            'title' => 'Test',
            'message' => 'Body',
            'action_url' => 'javascript:alert(1)',
        ]));
        $this->assertNotNull(Notice::fromArray([
            'id' => 'good.notice',
            'type' => Notice::TYPE_INFO,
            'title' => 'Test',
            'message' => 'Body',
            'action_url' => '/admin/plugins',
        ]));
    }

    public function testHooksApplyValidatedAttributesPluginOwnerPerFilter(): void
    {
        $hooks = new Hooks();
        $hooks->withOwner('bad-plugin', static function () use ($hooks): void {
            $hooks->filter('admin.dashboard.shortcuts', static fn (): string => 'corrupted');
        });

        $reportedOwner = null;
        $result = $hooks->applyValidated(
            'admin.dashboard.shortcuts',
            [['title' => 'Initial', 'url' => '/admin']],
            static function (mixed $candidate, mixed $previous, ?string $owner) use (&$reportedOwner): mixed {
                if (!is_array($candidate)) {
                    $reportedOwner = $owner;

                    return $previous;
                }

                return $candidate;
            },
        );

        $this->assertSame('bad-plugin', $reportedOwner);
        $this->assertSame([['title' => 'Initial', 'url' => '/admin']], $result);
    }
}
