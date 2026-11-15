<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Admin\Menu;

use PHPUnit\Framework\TestCase;
use Prime\Admin\Exception\InvalidMenuItemException;
use Prime\Admin\Menu\AdminMenu;
use Prime\Admin\Menu\MenuItem;
use Prime\Http\Request;

final class AdminMenuTest extends TestCase
{
    public function testMenuItemValidatesRequiredFieldsAndAccent(): void
    {
        $item = new MenuItem(
            id: 'pages',
            label: 'Pages',
            icon: 'file-text',
            url: '/admin/pages',
            position: 20,
            accent: 'teal',
            badge: '18',
            group: 'Contenu',
            capability: 'pages.read',
        );

        $this->assertSame('pages', $item->id);
        $this->assertSame('teal', $item->accent);
        $this->assertSame('18', $item->badge);

        $copy = $item->withUrl('/admin/pages/custom');
        $this->assertSame('/admin/pages', $item->url);
        $this->assertSame('/admin/pages/custom', $copy->url);
    }

    public function testMenuItemRejectsInvalidAccent(): void
    {
        $this->expectException(InvalidMenuItemException::class);
        new MenuItem(
            id: 'bad',
            label: 'Bad',
            icon: 'file-text',
            url: '/admin/bad',
            accent: 'neon-pink',
        );
    }

    public function testMenuItemRejectsEmptyId(): void
    {
        $this->expectException(InvalidMenuItemException::class);
        new MenuItem(id: ' ', label: 'Bad', icon: 'file-text', url: '/admin');
    }

    public function testTreeSortsByPositionThenInsertionOrder(): void
    {
        $menu = new AdminMenu();
        $menu->add(new MenuItem('third', 'Third', 'settings', '/admin/third', position: 30));
        $menu->add(new MenuItem('first_a', 'First A', 'file-text', '/admin/a', position: 10));
        $menu->add(new MenuItem('first_b', 'First B', 'newspaper', '/admin/b', position: 10));
        $menu->add(new MenuItem('second', 'Second', 'image', '/admin/second', position: 20));

        $ids = array_map(static fn ($node): string => $node->item->id, $menu->tree());
        $this->assertSame(['first_a', 'first_b', 'second', 'third'], $ids);
    }

    public function testAddChildGetRemoveAndGroupTree(): void
    {
        $menu = new AdminMenu();
        $menu->add(new MenuItem('dashboard', 'Tableau de bord', 'layout-dashboard', '/admin', 10, 'brand', group: null));
        $menu->add(new MenuItem('settings', 'Réglages', 'settings', '/admin/settings', 80, 'neutral', group: 'Système'));
        $menu->addChild('settings', new MenuItem('settings.security', 'Sécurité', 'shield', '/admin/settings/security', 30));
        $menu->addChild('settings', new MenuItem('settings.general', 'Général', 'settings', '/admin/settings/general', 10));

        $this->assertNotNull($menu->get('settings.general'));
        $this->assertSame('Général', $menu->get('settings.general')->label);

        $tree = $menu->tree();
        $this->assertCount(2, $tree);
        $childIds = array_map(static fn ($node): string => $node->item->id, $tree[1]->children);
        $this->assertSame(['settings.general', 'settings.security'], $childIds);

        $groups = $menu->groupedTree();
        $this->assertCount(2, $groups);
        $this->assertNull($groups[0]['label']);
        $this->assertSame('Système', $groups[1]['label']);

        // Remove single child
        $menu->remove('settings.security');
        $this->assertNull($menu->get('settings.security'));
        $this->assertCount(1, $menu->tree()[1]->children);

        // Remove parent also removes its children
        $menu->remove('settings');
        $this->assertNull($menu->get('settings'));
        $this->assertNull($menu->get('settings.general'));
    }

    public function testAddChildToUnknownParentThrowsException(): void
    {
        $menu = new AdminMenu();

        $this->expectException(InvalidMenuItemException::class);
        $menu->addChild('unknown', new MenuItem('child', 'Child', 'layers', '/admin/child'));
    }

    public function testSetActiveMatchesExactChildAndMarksParentHasActiveChild(): void
    {
        $menu = new AdminMenu();
        $menu->add(new MenuItem('dashboard', 'Tableau de bord', 'layout-dashboard', '/admin', 10, 'brand'));
        $menu->add(new MenuItem('pages', 'Pages', 'file-text', '/admin/pages', 20, 'teal'));
        $menu->add(new MenuItem('settings', 'Réglages', 'settings', '/admin/settings', 80, 'neutral'));
        $menu->addChild('settings', new MenuItem('settings.general', 'Général', 'settings', '/admin/settings/general', 10));

        // Exact child match
        $menu->setActive(Request::create('GET', '/admin/settings/general'));
        $this->assertSame('settings.general', $menu->activeId());

        $tree = $menu->tree();
        $this->assertFalse($tree[0]->active);
        $this->assertFalse($tree[2]->active);
        $this->assertTrue($tree[2]->hasActiveChild);
        $this->assertTrue($tree[2]->children[0]->active);

        // Nested sub-path falls back to parent prefix (not `/admin`)
        $menu->setActive(Request::create('GET', '/admin/pages/42/edit'));
        $this->assertSame('pages', $menu->activeId());

        // Exact dashboard home
        $menu->setActive(Request::create('GET', '/admin'));
        $this->assertSame('dashboard', $menu->activeId());
    }
}
