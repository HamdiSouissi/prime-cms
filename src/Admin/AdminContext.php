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
     * Default search keywords attached to core menu & quick-create entries.
     *
     * @var array<string, list<string>>
     */
    private const DEFAULT_KEYWORDS = [
        'dashboard' => ['accueil', 'statistiques', 'aperçu', 'indicateurs'],
        'pages' => ['statiques', 'arborescence', 'éditorial'],
        'articles' => ['blog', 'actualités', 'publications', 'billets'],
        'media' => ['médiathèque', 'images', 'fichiers', 'photos', 'bibliothèque', 'upload'],
        'appearance' => ['design', 'habillage', 'personnalisation'],
        'appearance.themes' => ['habillage', 'design', 'aurora', 'modèles'],
        'appearance.menus' => ['navigation', 'liens', 'en-tête'],
        'appearance.widgets' => ['blocs', 'zones', 'pied de page'],
        'plugins' => ['extensions', 'modules', 'compléments'],
        'users' => ['comptes', 'rôles', 'permissions', 'équipe', 'membres'],
        'settings' => ['paramètres', 'configuration', 'options', 'préférences'],
        'settings.general' => ['paramètres', 'configuration', 'site', 'langue', 'fuseau'],
        'settings.permalinks' => ['paramètres', 'configuration', 'urls', 'slugs', 'liens'],
        'settings.security' => ['paramètres', 'configuration', 'protection', 'sessions', 'audit'],
        'settings.emails' => ['paramètres', 'configuration', 'smtp', 'courriels', 'mails'],
        'styleguide' => ['composants', 'ui', 'tokens', 'palette', 'couleurs'],
        'create.page' => ['créer', 'nouveau', 'ajouter', 'page'],
        'create.article' => ['créer', 'nouveau', 'ajouter', 'article', 'blog'],
        'create.media' => ['créer', 'nouveau', 'ajouter', 'importer', 'upload', 'médias', 'images'],
        'create.user' => ['créer', 'nouveau', 'ajouter', 'utilisateur', 'membre', 'inviter'],
    ];

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
            $groupLabel = $item->group ?? 'Général';
            $entries[] = [
                'id' => 'nav.' . $item->id,
                'label' => $item->label,
                'subtitle' => $item->group ?? 'Accueil',
                'group' => $groupLabel,
                'section' => 'Navigation',
                'icon' => $item->icon,
                'accent' => $item->accent,
                'url' => $item->url,
                'keywords' => self::DEFAULT_KEYWORDS[$item->id] ?? [],
            ];

            foreach ($node->children as $childNode) {
                $child = $childNode->item;
                $entries[] = [
                    'id' => 'nav.' . $child->id,
                    'label' => $item->label . ' → ' . $child->label,
                    'subtitle' => $item->group ?? $item->label,
                    'group' => $groupLabel,
                    'section' => 'Navigation',
                    'icon' => $child->icon,
                    'accent' => $child->accent,
                    'url' => $child->url,
                    'keywords' => self::DEFAULT_KEYWORDS[$child->id] ?? [],
                ];
            }
        }

        foreach ($createItems as $create) {
            $entries[] = [
                'id' => $create['id'],
                'label' => $create['label'],
                'subtitle' => $create['description'],
                'group' => 'Actions rapides',
                'section' => 'Actions rapides',
                'icon' => $create['icon'],
                'accent' => $create['accent'],
                'url' => $create['url'],
                'keywords' => self::DEFAULT_KEYWORDS[$create['id']] ?? ['créer', 'nouveau', 'ajouter'],
            ];
        }

        $entries[] = [
            'id' => 'action.view_site',
            'label' => 'Voir le site public',
            'subtitle' => 'Ouvrir la page d’accueil',
            'group' => 'Raccourcis',
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
