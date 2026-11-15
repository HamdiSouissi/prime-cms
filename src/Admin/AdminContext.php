<?php

declare(strict_types=1);

namespace Prime\Admin;

use Prime\Admin\Menu\AdminMenu;
use Prime\Admin\Menu\MenuNode;
use Prime\Hooks\Hooks;
use Prime\Http\Request;
use Prime\Kernel\Application;

/**
 * Builds shared shell data for every admin view and invokes admin hooks.
 */
final class AdminContext
{
    public function __construct(
        private readonly Application $app,
        private readonly AdminMenu $baseMenu,
        private readonly Hooks $hooks,
    ) {
    }

    /**
     * Build the full view context required by `admin::layouts/app`.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public function build(Request $request, string $pageTitle, array $extra = []): array
    {
        $menu = clone $this->baseMenu;
        $filteredMenu = $this->hooks->apply('admin.menu', $menu);
        if ($filteredMenu instanceof AdminMenu) {
            $menu = $filteredMenu;
        }
        $menu->setActive($request);

        $menuGroups = $menu->groupedTree();
        $createItems = $this->createItems();
        $paletteItems = $this->commandPaletteItems($menu->tree(), $createItems);
        $assets = $this->assets();

        $headExtra = $this->hooks->apply('admin.head', '');
        $footerExtra = $this->hooks->apply('admin.footer', '');

        $appName = (string) $this->app->config()->get('app.name', 'Prime CMS');

        return array_merge([
            'appName' => $appName,
            'pageTitle' => $pageTitle,
            'fullTitle' => $pageTitle . ' — ' . $appName,
            'environment' => $this->app->environment(),
            'menuGroups' => $menuGroups,
            'createItems' => $createItems,
            'commandPaletteJson' => $this->encodeJson($paletteItems),
            'adminStyles' => $assets['css'],
            'adminScripts' => $assets['js'],
            'adminHeadExtra' => is_string($headExtra) ? $headExtra : '',
            'adminFooterExtra' => is_string($footerExtra) ? $footerExtra : '',
            'currentUser' => [
                'name' => 'Administrateur',
                'role' => 'Aperçu local',
                'email' => 'admin@prime-cms.local',
                'initials' => 'AD',
            ],
        ], $extra);
    }

    /**
     * Items listed in the topbar coral "Créer" dropdown (filterable via `admin.create.items`).
     *
     * @return list<array{id: string, label: string, description: string, icon: string, accent: string, url: string}>
     */
    public function createItems(): array
    {
        $defaults = [
            [
                'id' => 'create.page',
                'label' => 'Nouvelle page',
                'description' => 'Créer une page statique',
                'icon' => 'file-text',
                'accent' => 'teal',
                'url' => '/admin/pages',
            ],
            [
                'id' => 'create.article',
                'label' => 'Nouvel article',
                'description' => 'Rédiger une actualité',
                'icon' => 'newspaper',
                'accent' => 'teal',
                'url' => '/admin/articles',
            ],
            [
                'id' => 'create.media',
                'label' => 'Téléverser un média',
                'description' => 'Ajouter image ou document',
                'icon' => 'upload',
                'accent' => 'sky',
                'url' => '/admin/media',
            ],
            [
                'id' => 'create.user',
                'label' => 'Nouvel utilisateur',
                'description' => 'Inviter un membre',
                'icon' => 'users',
                'accent' => 'amber',
                'url' => '/admin/users',
            ],
        ];

        $filtered = $this->hooks->apply('admin.create.items', $defaults);

        return is_array($filtered) ? array_values($filtered) : $defaults;
    }

    /**
     * Command palette entries built from menu + quick actions (filterable via `admin.command_palette`).
     *
     * @param list<MenuNode> $tree
     * @param list<array{id: string, label: string, description: string, icon: string, accent: string, url: string}> $createItems
     * @return list<array<string, mixed>>
     */
    private function commandPaletteItems(array $tree, array $createItems): array
    {
        $entries = [];

        foreach ($tree as $node) {
            $item = $node->item;
            $entries[] = [
                'id' => 'nav.' . $item->id,
                'label' => $item->label,
                'subtitle' => $item->group ?? 'Tableau de bord',
                'section' => 'Navigation',
                'icon' => $item->icon,
                'accent' => $item->accent,
                'url' => $item->url,
                'keywords' => [$item->id, $item->group ?? 'accueil'],
            ];

            foreach ($node->children as $childNode) {
                $child = $childNode->item;
                $entries[] = [
                    'id' => 'nav.' . $child->id,
                    'label' => $item->label . ' → ' . $child->label,
                    'subtitle' => $child->url,
                    'section' => 'Navigation',
                    'icon' => $child->icon,
                    'accent' => $child->accent,
                    'url' => $child->url,
                    'keywords' => [$item->label, $child->label, $child->id],
                ];
            }
        }

        foreach ($createItems as $create) {
            $entries[] = [
                'id' => $create['id'],
                'label' => $create['label'],
                'subtitle' => $create['description'],
                'section' => 'Actions rapides',
                'icon' => $create['icon'],
                'accent' => $create['accent'],
                'url' => $create['url'],
                'keywords' => ['creer', 'nouveau', 'ajouter'],
            ];
        }

        $entries[] = [
            'id' => 'action.view_site',
            'label' => 'Voir le site public',
            'subtitle' => 'Ouvrir la page d’accueil',
            'section' => 'Raccourcis',
            'icon' => 'external-link',
            'accent' => 'brand',
            'url' => '/',
            'keywords' => ['site', 'accueil', 'public', 'front'],
        ];

        $filtered = $this->hooks->apply('admin.command_palette', $entries);

        return is_array($filtered) ? array_values($filtered) : $entries;
    }

    /**
     * CSS and JS asset URLs loaded in the admin shell (filterable via `admin.assets`).
     *
     * @return array{css: list<string>, js: list<string>}
     */
    private function assets(): array
    {
        $defaults = [
            'css' => [asset('admin/css/admin.css')],
            'js' => [asset('admin/js/admin.js')],
        ];

        $filtered = $this->hooks->apply('admin.assets', $defaults);
        if (!is_array($filtered)) {
            return $defaults;
        }

        $css = isset($filtered['css']) && is_array($filtered['css'])
            ? array_values(array_filter($filtered['css'], 'is_string'))
            : $defaults['css'];

        $js = isset($filtered['js']) && is_array($filtered['js'])
            ? array_values(array_filter($filtered['js'], 'is_string'))
            : $defaults['js'];

        return ['css' => $css, 'js' => $js];
    }

    /**
     * Encode data safely for embedding inside `<script type="application/json">`.
     *
     * @param list<array<string, mixed>> $data
     */
    private function encodeJson(array $data): string
    {
        $encoded = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP,
        );

        return is_string($encoded) ? $encoded : '[]';
    }
}
