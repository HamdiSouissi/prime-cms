<?php

declare(strict_types=1);

namespace Prime\Admin\Controller;

use Prime\Admin\AdminContext;
use Prime\Admin\Demo\DemoDashboardData;
use Prime\Http\Exception\NotFoundException;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Kernel\Application;
use Prime\View\Icon;
use Prime\View\View;

/**
 * Thin controller serving the Phase 2 admin shell, styleguide, and module placeholders.
 */
final class DashboardController
{
    public function __construct(
        private readonly Application $app,
        private readonly View $view,
        private readonly AdminContext $context,
        private readonly DemoDashboardData $demo,
    ) {
    }

    /**
     * Dashboard home (`GET /admin`).
     */
    public function index(Request $request): Response
    {
        $data = $this->context->build($request, 'Tableau de bord', [
            'stats' => $this->demo->stats(),
            'recentActivity' => $this->demo->recentActivity(),
            'shortcuts' => $this->demo->shortcuts(),
            'systemStatus' => $this->demo->systemStatus($this->app),
        ]);

        return Response::html($this->view->render('admin::dashboard/index', $data));
    }

    /**
     * Live interactive design system styleguide (`GET /admin/styleguide`).
     */
    public function styleguide(Request $request): Response
    {
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
}
