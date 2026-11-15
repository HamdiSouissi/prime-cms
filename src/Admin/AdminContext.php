<?php

declare(strict_types=1);

namespace Prime\Admin;

use Prime\Admin\Menu\AdminMenu;
use Prime\Admin\Menu\MenuNode;
use Prime\Auth\Auth;
use Prime\Auth\User;
use Prime\Hooks\Hooks;
use Prime\Http\Request;
use Prime\Http\Session;
use Prime\Kernel\Application;

/**
 * Builds shared shell data for every admin view, filters navigation/actions by user
 * capabilities, attaches flash toasts, and invokes admin hooks.
 */
final class AdminContext
{
    public const FLASH_TOASTS_KEY = '_admin.toasts';

    public function __construct(
        private readonly Application $app,
        private readonly AdminMenu $baseMenu,
        private readonly Hooks $hooks,
        private readonly ?Auth $auth = null,
        private readonly ?Session $session = null,
    ) {
    }

    /**
     * Queue a toast notification to be displayed once on the next admin page render.
     */
    public function flashToast(string $message, string $type = 'success', ?string $title = null): void
    {
        if ($this->session === null) {
            return;
        }

        $existing = $this->session->getFlash(self::FLASH_TOASTS_KEY, []);
        $list = is_array($existing) ? array_values($existing) : [];
        $entry = [
            'message' => $message,
            'type' => $type,
        ];
        if ($title !== null && $title !== '') {
            $entry['title'] = $title;
        }
        $list[] = $entry;

        $this->session->flash(self::FLASH_TOASTS_KEY, $list);
    }

    /**
     * Build the full view context required by `admin::layouts/app`.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public function build(Request $request, string $pageTitle, array $extra = []): array
    {
        $user = $this->auth?->user($request);

        $menu = clone $this->baseMenu;
        $filteredMenu = $this->hooks->apply('admin.menu', $menu);
        if ($filteredMenu instanceof AdminMenu) {
            $menu = $filteredMenu;
        }

        if ($user !== null) {
            $menu->filterByCapability(static fn (string $cap): bool => $user->can($cap));
        }

        $menu->setActive($request);

        $menuGroups = $menu->groupedTree();
        $createItems = $this->createItems($user);
        $paletteItems = $this->commandPaletteItems($menu->tree(), $createItems);
        $assets = $this->assets();

        $headExtra = $this->hooks->apply('admin.head', '');
        $footerExtra = $this->hooks->apply('admin.footer', '');

        $appName = (string) $this->app->config()->get('app.name', 'Prime CMS');
        $rawToasts = $this->session !== null ? $this->session->getFlash(self::FLASH_TOASTS_KEY, []) : [];
        $toasts = is_array($rawToasts) ? array_values($rawToasts) : [];

        return array_merge([
            'appName' => $appName,
            'pageTitle' => $pageTitle,
            'fullTitle' => $pageTitle . ' — ' . $appName,
            'environment' => $this->app->environment(),
            'menuGroups' => $menuGroups,
            'createItems' => $createItems,
            'commandPaletteJson' => $this->encodeJson($paletteItems),
            'flashToastsJson' => $this->encodeJson($toasts),
            'adminStyles' => $assets['css'],
            'adminScripts' => $assets['js'],
            'adminHeadExtra' => is_string($headExtra) ? $headExtra : '',
            'adminFooterExtra' => is_string($footerExtra) ? $footerExtra : '',
            'authUser' => $user,
            'currentUser' => $this->formatCurrentUser($user),
        ], $extra);
    }

    /**
     * Items listed in the topbar coral "Créer" dropdown (filtered by capabilities & `admin.create.items`).
     *
     * @return list<array{id: string, label: string, description: string, icon: string, accent: string, url: string, capability?: string}>
     */
    public function createItems(?User $user = null): array
    {
        $defaults = [
            [
                'id' => 'create.page',
                'label' => 'Nouvelle page',
                'description' => 'Créer une page statique',
                'icon' => 'file-text',
                'accent' => 'teal',
                'url' => '/admin/pages',
                'capability' => 'content.create',
            ],
            [
                'id' => 'create.article',
                'label' => 'Nouvel article',
                'description' => 'Rédiger une actualité',
                'icon' => 'newspaper',
                'accent' => 'teal',
                'url' => '/admin/articles',
                'capability' => 'content.create',
            ],
            [
                'id' => 'create.media',
                'label' => 'Téléverser un média',
                'description' => 'Ajouter image ou document',
                'icon' => 'upload',
                'accent' => 'sky',
                'url' => '/admin/media',
                'capability' => 'media.upload',
            ],
            [
                'id' => 'create.user',
                'label' => 'Nouvel utilisateur',
                'description' => 'Créer ou inviter un membre',
                'icon' => 'users',
                'accent' => 'amber',
                'url' => '/admin/users/create',
                'capability' => 'users.create',
            ],
        ];

        $filtered = $this->hooks->apply('admin.create.items', $defaults);
        $items = is_array($filtered) ? array_values($filtered) : $defaults;

        if ($user !== null) {
            $items = array_values(array_filter(
                $items,
                static function (array $item) use ($user): bool {
                    $cap = $item['capability'] ?? null;

                    return !is_string($cap) || $cap === '' || $user->can($cap);
                },
            ));
        }

        return $items;
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
        'users.list' => ['utilisateurs', 'comptes', 'équipe', 'membres'],
        'users.create' => ['ajouter', 'inviter', 'nouveau', 'utilisateur'],
        'users.roles' => ['rôles', 'permissions', 'droits', 'rbac'],
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
     * @return array{id: ?int, name: string, role: string, email: string, initials: string, accent: string, isAdmin: bool, canSettings: bool}
     */
    private function formatCurrentUser(?User $user): array
    {
        if ($user === null) {
            return [
                'id' => null,
                'name' => 'Invité',
                'role' => 'Non connecté',
                'email' => '',
                'initials' => 'IN',
                'accent' => 'brand',
                'isAdmin' => false,
                'canSettings' => false,
            ];
        }

        return [
            'id' => $user->id,
            'name' => $user->displayName,
            'role' => $user->primaryRoleName(),
            'email' => $user->email,
            'initials' => $user->initials(),
            'accent' => $user->avatarAccent(),
            'isAdmin' => $user->isAdministrator(),
            'canSettings' => $user->can('settings.manage'),
        ];
    }

    /**
     * Command palette entries built from capability-filtered menu + quick actions.
     *
     * @param list<MenuNode> $tree
     * @param list<array{id: string, label: string, description: string, icon: string, accent: string, url: string, capability?: string}> $createItems
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
            'id' => 'action.profile',
            'label' => 'Mon profil et sécurité',
            'subtitle' => 'Mot de passe, 2FA et sessions',
            'group' => 'Raccourcis',
            'section' => 'Raccourcis',
            'icon' => 'user',
            'accent' => 'sky',
            'url' => '/admin/profile',
            'keywords' => ['profil', 'compte', 'mot de passe', '2fa', 'totp', 'sécurité'],
        ];

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
