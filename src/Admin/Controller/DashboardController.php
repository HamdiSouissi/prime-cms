<?php

declare(strict_types=1);

namespace Prime\Admin\Controller;

use Prime\Admin\AdminContext;
use Prime\Admin\Data\DashboardShortcut;
use Prime\Admin\Data\DashboardStat;
use Prime\Admin\Demo\DemoDashboardData;
use Prime\Auth\Auth;
use Prime\Auth\User;
use Prime\Auth\UserRepository;
use Prime\Content\ContentTypeRegistry;
use Prime\Content\Entry;
use Prime\Content\EntryRepository;
use Prime\Hooks\Hooks;
use Prime\Http\Exception\ForbiddenException;
use Prime\Http\Exception\NotFoundException;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Kernel\Application;
use Prime\Log\Logger;
use Prime\Media\Media;
use Prime\Media\MediaHelper;
use Prime\Media\MediaRepository;
use Prime\View\Icon;
use Prime\View\View;
use Throwable;

/**
 * Controller serving the dashboard home, local dev styleguide, and upcoming CMS module placeholders.
 */
final class DashboardController
{
    /**
     * Required capability per placeholder module key.
     *
     * @var array<string, string>
     */
    private const MODULE_CAPABILITIES = [
        'pages' => 'content.view',
        'articles' => 'content.view',
        'media' => 'media.view',
        'appearance' => 'appearance.manage',
        'appearance/themes' => 'appearance.manage',
        'appearance/menus' => 'appearance.manage',
        'appearance/widgets' => 'appearance.manage',
        'plugins' => 'plugins.manage',
        'settings' => 'settings.manage',
    ];

    public function __construct(
        private readonly Application $app,
        private readonly View $view,
        private readonly AdminContext $context,
        private readonly DemoDashboardData $demo,
        private readonly Auth $auth,
        private readonly ?Hooks $hooks = null,
        private readonly ?Logger $logger = null,
        private readonly ?EntryRepository $entries = null,
        private readonly ?UserRepository $users = null,
        private readonly ?ContentTypeRegistry $types = null,
        private readonly ?MediaRepository $media = null,
    ) {
    }

    /**
     * Dashboard home (`GET /admin`).
     */
    public function index(Request $request): Response
    {
        $user = $this->auth->user($request);

        $shortcuts = array_values(array_filter(
            $this->demo->shortcuts(),
            function (array $shortcut) use ($user): bool {
                $url = $shortcut['url'];
                if ($url === '/admin/styleguide') {
                    return $this->app->environment() === 'local' && $user !== null && $user->isAdministrator();
                }
                if (str_starts_with($url, '/admin/content/')) {
                    return $user === null || $user->can('content.view');
                }
                if (str_starts_with($url, '/admin/media')) {
                    return $user === null || $user->can('media.view');
                }
                if (str_starts_with($url, '/admin/settings')) {
                    return $user === null || $user->can('settings.manage');
                }
                if (str_starts_with($url, '/admin/users')) {
                    return $user === null || $user->can('users.view');
                }

                return true;
            },
        ));

        $rawStats = $this->buildLiveStats() ?? $this->demo->stats();
        $stats = array_values(array_filter(
            $rawStats,
            static function (array $tile) use ($user): bool {
                $url = $tile['url'];
                if (str_starts_with($url, '/admin/users')) {
                    return $user === null || $user->can('users.view');
                }
                if (str_starts_with($url, '/admin/media')) {
                    return $user === null || $user->can('media.view');
                }
                if (str_starts_with($url, '/admin/content/')) {
                    return $user === null || $user->can('content.view');
                }

                return true;
            },
        ));

        if ($this->hooks !== null) {
            /** @var list<array{title: string, description: string, icon: string, accent: string, url: string}> $normalizedShortcuts */
            $normalizedShortcuts = $this->hooks->applyValidated(
                'admin.dashboard.shortcuts',
                $shortcuts,
                function (mixed $candidate, mixed $previous, ?string $owner, string $hookName): array {
                    /** @var list<array{title: string, description: string, icon: string, accent: string, url: string}> $fallback */
                    $fallback = is_array($previous) ? array_values($previous) : [];
                    if (!is_array($candidate)) {
                        $this->logInvalidFilterData($hookName, $owner, 'La valeur retournée doit être une liste de raccourcis.');

                        return $fallback;
                    }

                    $valid = [];
                    foreach ($candidate as $index => $entry) {
                        $normalized = DashboardShortcut::fromArray($entry);
                        if ($normalized === null) {
                            $this->logInvalidFilterData($hookName, $owner, sprintf('Raccourci invalide ignoré à l\'index %s.', (string) $index));
                            continue;
                        }
                        $valid[] = $normalized->toArray();
                    }

                    return $valid;
                },
                $user,
            );
            $shortcuts = $normalizedShortcuts;

            /** @var list<array{label: string, value: string, icon: string, accent: string, trend: string, trendDirection: 'up'|'down'|'flat', hint: string, url: string}> $normalizedStats */
            $normalizedStats = $this->hooks->applyValidated(
                'admin.dashboard.stats',
                $stats,
                function (mixed $candidate, mixed $previous, ?string $owner, string $hookName): array {
                    /** @var list<array{label: string, value: string, icon: string, accent: string, trend: string, trendDirection: 'up'|'down'|'flat', hint: string, url: string}> $fallback */
                    $fallback = is_array($previous) ? array_values($previous) : [];
                    if (!is_array($candidate)) {
                        $this->logInvalidFilterData($hookName, $owner, 'La valeur retournée doit être une liste de statistiques.');

                        return $fallback;
                    }

                    $valid = [];
                    foreach ($candidate as $index => $entry) {
                        $normalized = DashboardStat::fromArray($entry);
                        if ($normalized === null) {
                            $this->logInvalidFilterData($hookName, $owner, sprintf('Statistique invalide ignorée à l\'index %s.', (string) $index));
                            continue;
                        }
                        $valid[] = $normalized->toArray();
                    }

                    return $valid;
                },
                $user,
            );
            $stats = $normalizedStats;
        }

        $liveActivity = $this->buildLiveRecentActivity();
        $data = $this->context->build($request, 'Tableau de bord', [
            'stats' => $stats,
            'recentActivity' => $liveActivity ?? $this->demo->recentActivity(),
            'isLiveActivity' => $liveActivity !== null,
            'shortcuts' => $shortcuts,
            'systemStatus' => $this->demo->systemStatus($this->app),
        ]);

        return Response::html($this->view->render('admin::dashboard/index', $data));
    }

    /**
     * Build live KPI stats from database repositories, or return null if content tables do not exist yet.
     *
     * @return list<array{label: string, value: string, icon: string, accent: string, trend: string, trendDirection: 'up'|'down'|'flat', hint: string, url: string}>|null
     */
    private function buildLiveStats(): ?array
    {
        if ($this->entries === null) {
            return null;
        }

        try {
            $pageCounts = $this->entries->countByStatus('page');
            $postCounts = $this->entries->countByStatus('post');
            $usersTotal = $this->users !== null ? $this->users->paginate(page: 1, perPage: 1)['total'] : 0;
        } catch (Throwable) {
            return null;
        }

        $mediaCount = 0;
        $mediaBytes = 0;
        $mediaImages = 0;
        if ($this->media !== null) {
            try {
                $mediaStats = $this->media->stats();
                $mediaCount = $mediaStats['total_count'];
                $mediaBytes = $mediaStats['total_bytes'] + $mediaStats['variants_bytes'];
                $mediaImages = $mediaStats['by_kind'][Media::KIND_IMAGE]['count'] ?? 0;
            } catch (Throwable) {
            }
        }

        return [
            [
                'label' => 'Pages publiées',
                'value' => (string) ($pageCounts[Entry::STATUS_PUBLISHED] ?? 0),
                'icon' => 'file-text',
                'accent' => 'teal',
                'trend' => sprintf('%d total', ($pageCounts[Entry::STATUS_PUBLISHED] ?? 0) + ($pageCounts[Entry::STATUS_DRAFT] ?? 0)),
                'trendDirection' => 'flat',
                'hint' => sprintf('%d brouillon(s)', $pageCounts[Entry::STATUS_DRAFT] ?? 0),
                'url' => '/admin/content/page',
            ],
            [
                'label' => 'Articles publiés',
                'value' => (string) ($postCounts[Entry::STATUS_PUBLISHED] ?? 0),
                'icon' => 'newspaper',
                'accent' => 'violet',
                'trend' => sprintf('%d brouillon(s)', $postCounts[Entry::STATUS_DRAFT] ?? 0),
                'trendDirection' => 'flat',
                'hint' => sprintf('%d planifié(s)', $postCounts[Entry::STATUS_SCHEDULED] ?? 0),
                'url' => '/admin/content/post',
            ],
            [
                'label' => 'Médias',
                'value' => (string) $mediaCount,
                'icon' => 'image',
                'accent' => 'sky',
                'trend' => MediaHelper::formatBytes($mediaBytes),
                'trendDirection' => $mediaCount > 0 ? 'up' : 'flat',
                'hint' => sprintf('%d image%s · Médiathèque active', $mediaImages, $mediaImages > 1 ? 's' : ''),
                'url' => '/admin/media',
            ],
            [
                'label' => 'Utilisateurs',
                'value' => (string) $usersTotal,
                'icon' => 'users',
                'accent' => 'amber',
                'trend' => 'Comptes actifs',
                'trendDirection' => 'up',
                'hint' => 'RBAC + 2FA',
                'url' => '/admin/users',
            ],
        ];
    }

    /**
     * Build recent editorial activity from the latest updated entries.
     *
     * @return list<array{title: string, meta: string, author: string, initials: string, status: string, statusVariant: string, accent: string, url?: string}>|null
     */
    private function buildLiveRecentActivity(): ?array
    {
        if ($this->entries === null) {
            return null;
        }

        try {
            $recent = $this->entries->recentActivity(6);
        } catch (Throwable) {
            return null;
        }

        if ($recent === []) {
            return [];
        }

        $authors = [];
        if ($this->users !== null) {
            foreach ($this->users->paginate(page: 1, perPage: 100)['items'] as $u) {
                $authors[$u->id] = $u;
            }
        }

        $items = [];
        foreach ($recent as $entry) {
            $author = $entry->authorId !== null ? ($authors[$entry->authorId] ?? null) : null;
            $authorName = $author instanceof User ? $author->displayName : ($entry->authorName ?? 'Système');
            $initials = $author instanceof User ? $author->initials() : 'SY';
            $ct = $this->types?->get($entry->type);
            $typeLabel = $ct !== null ? $ct->label('singular') : $entry->type;

            $items[] = [
                'title' => sprintf('%s « %s »', $typeLabel, $entry->title),
                'meta' => sprintf('Mis à jour par %s · %s UTC', $authorName, $entry->updatedAt),
                'author' => $authorName,
                'initials' => $initials,
                'status' => $entry->statusLabel(),
                'statusVariant' => $entry->statusVariant(),
                'accent' => $ct !== null ? $ct->accent : 'teal',
                'url' => $entry->editUrl(),
            ];
        }

        return $items;
    }

    /**
     * Live interactive design system styleguide (`GET /admin/styleguide`).
     * Requires administrator role (`can:*`) AND `APP_ENV=local`.
     */
    public function styleguide(Request $request): Response
    {
        if ($this->app->environment() !== 'local') {
            throw new NotFoundException('Le Design System est disponible uniquement en environnement local.');
        }

        $data = $this->context->build($request, 'Design System', [
            'iconNames' => Icon::names(),
        ]);

        return Response::html($this->view->render('admin::styleguide/index', $data));
    }

    /**
     * Placeholder page for upcoming CMS modules (`GET /admin/{module}` and `GET /admin/{module}/{section}`).
     */
    public function module(Request $request, string $module, ?string $section = null): Response
    {
        $key = $section !== null ? $module . '/' . $section : $module;
        $modules = $this->demo->modulePlaceholders();

        if (!isset($modules[$key])) {
            throw new NotFoundException('Module d’administration introuvable.');
        }

        $requiredCap = self::MODULE_CAPABILITIES[$key] ?? null;
        if ($requiredCap !== null && !$this->auth->can($requiredCap, $request)) {
            throw new ForbiddenException('Vous ne disposez pas des permissions nécessaires pour accéder à ce module.');
        }

        $meta = $modules[$key];
        $breadcrumbs = [
            ['label' => 'Tableau de bord', 'url' => '/admin'],
        ];

        if (isset($meta['parentTitle'], $meta['parentUrl'])) {
            $breadcrumbs[] = [
                'label' => $meta['parentTitle'],
                'url' => $meta['parentUrl'],
            ];
        }

        $breadcrumbs[] = ['label' => $meta['title'], 'url' => null];

        $data = $this->context->build($request, $meta['title'], [
            'moduleMeta' => $meta,
            'breadcrumbs' => $breadcrumbs,
        ]);

        return Response::html($this->view->render('admin::placeholders/module', $data));
    }

    private function logInvalidFilterData(string $hookName, ?string $owner, string $reason): void
    {
        $this->logger?->warning(sprintf('Filtre [%s] : %s', $hookName, $reason), [
            'hook' => $hookName,
            'plugin' => $owner ?? 'core',
        ]);
    }
}
