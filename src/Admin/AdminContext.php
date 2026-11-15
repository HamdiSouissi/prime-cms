<?php

declare(strict_types=1);

namespace Prime\Admin;

use Prime\Admin\Data\AdminAssets;
use Prime\Admin\Data\CommandPaletteItem;
use Prime\Admin\Data\CreateMenuItem;
use Prime\Admin\Menu\AdminMenu;
use Prime\Admin\Menu\MenuItem;
use Prime\Admin\Menu\MenuNode;
use Prime\Auth\Auth;
use Prime\Auth\User;
use Prime\Content\ContentTypeRegistry;
use Prime\Content\EntryRepository;
use Prime\Content\TaxonomyRegistry;
use Prime\Hooks\Hooks;
use Prime\Http\Request;
use Prime\Http\Session;
use Prime\Kernel\Application;
use Prime\Log\Logger;
use Throwable;

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
        private readonly ?Notices $notices = null,
        private readonly ?Logger $logger = null,
        private readonly ?ContentTypeRegistry $contentTypes = null,
        private readonly ?TaxonomyRegistry $taxonomies = null,
        private readonly ?EntryRepository $entries = null,
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
        $this->injectContentMenuItems($menu);
        $filteredMenu = $this->hooks->applyValidated(
            'admin.menu',
            $menu,
            function (mixed $candidate, mixed $previous, ?string $owner, string $hookName): AdminMenu {
                /** @var AdminMenu $fallback */
                $fallback = $previous instanceof AdminMenu ? $previous : clone $this->baseMenu;
                if (!$candidate instanceof AdminMenu) {
                    $this->logInvalidFilterData($hookName, $owner, 'La valeur retournée doit être une instance de AdminMenu.');

                    return $fallback;
                }

                foreach ($candidate->sanitize() as $issue) {
                    $this->logInvalidFilterData($hookName, $owner, $issue);
                }

                return $candidate;
            },
        );
        if ($filteredMenu instanceof AdminMenu) {
            $menu = $filteredMenu;
        }

        if ($user !== null) {
            $menu->filterByCapability(static fn (string $cap): bool => $user->can($cap));
        }

        $menu->setActive($request);

        $menuGroups = $menu->groupedTree();
        $createItems = $this->createItems($user);
        $paletteItems = $this->commandPaletteItems($menu->tree(), $createItems, $user);
        $assets = $this->assets();

        $headExtra = $this->hooks->apply('admin.head', '');
        $footerExtra = $this->hooks->apply('admin.footer', '');

        $appName = (string) $this->app->config()->get('app.name', 'Prime CMS');
        $rawToasts = $this->session !== null ? $this->session->getFlash(self::FLASH_TOASTS_KEY, []) : [];
        $toasts = is_array($rawToasts) ? array_values($rawToasts) : [];
        $baseNotices = $this->notices !== null ? $this->notices->forUser($user) : [];
        $adminNotices = $this->normalizeNotices($baseNotices, $user);
        $safeMode = (bool) $this->app->config()->get('plugins.safe_mode', false);

        return array_merge([
            'appName' => $appName,
            'pageTitle' => $pageTitle,
            'fullTitle' => $pageTitle . ' — ' . $appName,
            'environment' => $this->app->environment(),
            'menuGroups' => $menuGroups,
            'createItems' => $createItems,
            'commandPaletteJson' => $this->encodeJson($paletteItems),
            'flashToastsJson' => $this->encodeJson($toasts),
            'adminNotices' => $adminNotices,
            'adminNoticesCount' => count($adminNotices),
            'safeMode' => $safeMode,
            'primaryCtaInContent' => false,
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
        $defaults = [];

        if ($this->contentTypes !== null) {
            foreach ($this->contentTypes->all() as $ct) {
                $defaults[] = [
                    'id' => 'create.' . $ct->name,
                    'label' => $ct->label('add_new'),
                    'description' => 'Créer un élément (' . mb_strtolower($ct->label('singular')) . ')',
                    'icon' => $ct->icon,
                    'accent' => $ct->accent,
                    'url' => $ct->adminCreateUrl(),
                    'capability' => $ct->capability('create'),
                ];
            }
        } else {
            $defaults[] = [
                'id' => 'create.page',
                'label' => 'Nouvelle page',
                'description' => 'Créer une page statique',
                'icon' => 'file-text',
                'accent' => 'teal',
                'url' => '/admin/content/page/create',
                'capability' => 'content.create',
            ];
            $defaults[] = [
                'id' => 'create.post',
                'label' => 'Nouvel article',
                'description' => 'Rédiger une actualité',
                'icon' => 'newspaper',
                'accent' => 'teal',
                'url' => '/admin/content/post/create',
                'capability' => 'content.create',
            ];
        }

        $defaults[] = [
            'id' => 'create.media',
            'label' => 'Téléverser un média',
            'description' => 'Ajouter image ou document',
            'icon' => 'upload',
            'accent' => 'sky',
            'url' => '/admin/media',
            'capability' => 'media.upload',
        ];
        $defaults[] = [
            'id' => 'create.user',
            'label' => 'Nouvel utilisateur',
            'description' => 'Créer ou inviter un membre',
            'icon' => 'users',
            'accent' => 'amber',
            'url' => '/admin/users/create',
            'capability' => 'users.create',
        ];

        /** @var list<array{id: string, label: string, description: string, icon: string, accent: string, url: string, capability?: string}> $items */
        $items = $this->hooks->applyValidated(
            'admin.create.items',
            $defaults,
            function (mixed $candidate, mixed $previous, ?string $owner, string $hookName): array {
                /** @var list<array{id: string, label: string, description: string, icon: string, accent: string, url: string, capability?: string}> $fallback */
                $fallback = is_array($previous) ? array_values($previous) : [];
                if (!is_array($candidate)) {
                    $this->logInvalidFilterData($hookName, $owner, 'La valeur retournée doit être une liste d\'éléments de création rapide.');

                    return $fallback;
                }

                $valid = [];
                foreach ($candidate as $index => $entry) {
                    $normalized = CreateMenuItem::fromArray($entry);
                    if ($normalized === null) {
                        $this->logInvalidFilterData($hookName, $owner, sprintf('Élément du menu Créer invalide ignoré à l\'index %s.', (string) $index));
                        continue;
                    }
                    $valid[] = $normalized->toArray();
                }

                return $valid;
            },
        );

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
        'content.page' => ['pages', 'statiques', 'arborescence', 'éditorial'],
        'content.post' => ['articles', 'blog', 'actualités', 'publications', 'billets'],
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
        'create.post' => ['créer', 'nouveau', 'ajouter', 'article', 'blog'],
        'create.media' => ['créer', 'nouveau', 'ajouter', 'importer', 'upload', 'médias', 'images'],
        'create.user' => ['créer', 'nouveau', 'ajouter', 'utilisateur', 'membre', 'inviter'],
    ];

    /**
     * Dynamically inject registered ContentTypes and their associated Taxonomies into the Contenu group.
     */
    private function injectContentMenuItems(AdminMenu $menu): void
    {
        if ($this->contentTypes === null) {
            return;
        }

        $position = 20;
        foreach ($this->contentTypes->all() as $ct) {
            $parentId = 'content.' . $ct->name;
            $baseUrl = $ct->adminListUrl();

            $menu->add(new MenuItem(
                id: $parentId,
                label: $ct->label('plural'),
                icon: $ct->icon,
                url: $baseUrl,
                position: $ct->menuPosition > 0 ? $ct->menuPosition : $position,
                accent: $ct->accent,
                capability: $ct->capability('view'),
                group: 'Contenu',
            ));

            $menu->addChild($parentId, new MenuItem(
                id: $parentId . '.all',
                label: 'Tous : ' . $ct->label('plural'),
                icon: $ct->icon,
                url: $baseUrl,
                position: 10,
                accent: $ct->accent,
                capability: $ct->capability('view'),
            ));

            $menu->addChild($parentId, new MenuItem(
                id: $parentId . '.create',
                label: 'Ajouter',
                icon: 'plus',
                url: $ct->adminCreateUrl(),
                position: 20,
                accent: $ct->accent,
                capability: $ct->capability('create'),
            ));

            if ($this->taxonomies !== null) {
                $taxPos = 30;
                foreach ($this->taxonomies->forContentType($ct->name, $ct) as $tax) {
                    $menu->addChild($parentId, new MenuItem(
                        id: $parentId . '.tax.' . $tax->name,
                        label: $tax->label('plural'),
                        icon: $tax->icon,
                        url: $tax->adminUrl(),
                        position: $taxPos,
                        accent: $ct->accent,
                        capability: $ct->capability('view'),
                    ));
                    $taxPos += 10;
                }
            }

            $position += 5;
        }
    }

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
     * Command palette entries built from capability-filtered menu, quick actions, and recent entries.
     *
     * @param list<MenuNode> $tree
     * @param list<array{id: string, label: string, description: string, icon: string, accent: string, url: string, capability?: string}> $createItems
     * @return list<array<string, mixed>>
     */
    private function commandPaletteItems(array $tree, array $createItems, ?User $user = null): array
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

        if ($this->entries !== null && ($user === null || $user->can('content.view'))) {
            try {
                foreach ($this->entries->recentActivity(8) as $recentEntry) {
                    $ct = $this->contentTypes?->get($recentEntry->type);
                    $entries[] = [
                        'id' => 'entry.' . $recentEntry->id,
                        'label' => $recentEntry->title,
                        'subtitle' => ($ct !== null ? $ct->label('singular') : $recentEntry->type) . ' · ' . $recentEntry->statusLabel(),
                        'group' => 'Contenus récents',
                        'section' => 'Contenus récents',
                        'icon' => $ct !== null ? $ct->icon : 'file-text',
                        'accent' => $ct !== null ? $ct->accent : 'teal',
                        'url' => $recentEntry->editUrl(),
                        'keywords' => [$recentEntry->slug, $recentEntry->type, $recentEntry->status],
                    ];
                }
            } catch (Throwable) {
                // Gracefully skip if content tables have not been migrated yet.
            }
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

        /** @var list<array<string, mixed>> $normalized */
        $normalized = $this->hooks->applyValidated(
            'admin.command_palette',
            $entries,
            function (mixed $candidate, mixed $previous, ?string $owner, string $hookName): array {
                /** @var list<array<string, mixed>> $fallback */
                $fallback = is_array($previous) ? array_values($previous) : [];
                if (!is_array($candidate)) {
                    $this->logInvalidFilterData($hookName, $owner, 'La valeur retournée doit être une liste d\'entrées de palette de commandes.');

                    return $fallback;
                }

                $valid = [];
                foreach ($candidate as $index => $entry) {
                    $item = CommandPaletteItem::fromArray($entry);
                    if ($item === null) {
                        $this->logInvalidFilterData($hookName, $owner, sprintf('Entrée de palette de commandes invalide ignorée à l\'index %s.', (string) $index));
                        continue;
                    }
                    $valid[] = $item->toArray();
                }

                return $valid;
            },
        );

        return $normalized;
    }

    /**
     * CSS and JS asset URLs loaded in the admin shell (filterable via `admin.assets`).
     *
     * @return array{css: list<string>, js: list<string>}
     */
    private function assets(): array
    {
        $defaults = new AdminAssets(
            css: [asset('admin/css/admin.css')],
            js: [asset('admin/js/admin.js')],
        );

        /** @var array{css: list<string>, js: list<string>} $normalized */
        $normalized = $this->hooks->applyValidated(
            'admin.assets',
            $defaults->toArray(),
            function (mixed $candidate, mixed $previous, ?string $owner, string $hookName) use ($defaults): array {
                $fallback = is_array($previous)
                    ? AdminAssets::fromArray($previous, $defaults)
                    : $defaults;

                $assets = AdminAssets::fromArray(
                    $candidate,
                    $fallback,
                    function (string $reason) use ($hookName, $owner): void {
                        $this->logInvalidFilterData($hookName, $owner, $reason);
                    },
                );

                return $assets->toArray();
            },
        );

        return $normalized;
    }

    /**
     * Allow filtering admin notices via `admin.notices` while strictly validating each entry.
     *
     * @param list<Notice> $notices
     * @return list<Notice>
     */
    private function normalizeNotices(array $notices, ?User $user): array
    {
        /** @var list<Notice> $filtered */
        $filtered = $this->hooks->applyValidated(
            'admin.notices',
            $notices,
            function (mixed $candidate, mixed $previous, ?string $owner, string $hookName): array {
                /** @var list<Notice> $fallback */
                $fallback = is_array($previous) ? array_values($previous) : [];
                if (!is_array($candidate)) {
                    $this->logInvalidFilterData($hookName, $owner, 'La valeur retournée doit être une liste de notifications.');

                    return $fallback;
                }

                $valid = [];
                foreach ($candidate as $index => $entry) {
                    $notice = Notice::fromArray($entry);
                    if ($notice === null) {
                        $this->logInvalidFilterData($hookName, $owner, sprintf('Notification invalide ignorée à l\'index %s.', (string) $index));
                        continue;
                    }
                    $valid[] = $notice;
                }

                return $valid;
            },
            $user,
        );

        return $filtered;
    }

    private function logInvalidFilterData(string $hookName, ?string $owner, string $reason): void
    {
        $this->logger?->warning(sprintf('Filtre [%s] : %s', $hookName, $reason), [
            'hook' => $hookName,
            'plugin' => $owner ?? 'core',
        ]);
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
