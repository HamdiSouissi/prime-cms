<?php

declare(strict_types=1);

namespace Prime\Admin\Controller;

use Prime\Admin\AdminContext;
use Prime\Admin\Data\DashboardShortcut;
use Prime\Admin\Data\DashboardStat;
use Prime\Admin\Demo\DemoDashboardData;
use Prime\Auth\Auth;
use Prime\Hooks\Hooks;
use Prime\Http\Exception\ForbiddenException;
use Prime\Http\Exception\NotFoundException;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Kernel\Application;
use Prime\Log\Logger;
use Prime\View\Icon;
use Prime\View\View;

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
        'settings/general' => 'settings.manage',
        'settings/permalinks' => 'settings.manage',
        'settings/security' => 'settings.manage',
        'settings/emails' => 'settings.manage',
    ];

    public function __construct(
        private readonly Application $app,
        private readonly View $view,
        private readonly AdminContext $context,
        private readonly DemoDashboardData $demo,
        private readonly Auth $auth,
        private readonly ?Hooks $hooks = null,
        private readonly ?Logger $logger = null,
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
                if (str_starts_with($url, '/admin/pages') || str_starts_with($url, '/admin/articles')) {
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

        $stats = array_values(array_filter(
            $this->demo->stats(),
            static function (array $tile) use ($user): bool {
                $url = $tile['url'];
                if (str_starts_with($url, '/admin/users')) {
                    return $user === null || $user->can('users.view');
                }
                if (str_starts_with($url, '/admin/media')) {
                    return $user === null || $user->can('media.view');
                }
                if (str_starts_with($url, '/admin/pages') || str_starts_with($url, '/admin/articles')) {
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

        $data = $this->context->build($request, 'Tableau de bord', [
            'stats' => $stats,
            'recentActivity' => $this->demo->recentActivity(),
            'shortcuts' => $shortcuts,
            'systemStatus' => $this->demo->systemStatus($this->app),
        ]);

        return Response::html($this->view->render('admin::dashboard/index', $data));
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
