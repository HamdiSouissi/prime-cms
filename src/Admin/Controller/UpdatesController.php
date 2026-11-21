<?php

declare(strict_types=1);

namespace Prime\Admin\Controller;

use Prime\Admin\AdminContext;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Kernel\Application;
use Prime\Update\Updater;
use Prime\View\View;
use Throwable;

/**
 * « Outils › Mises à jour » (`settings.manage`): installed and deployed versions, pending
 * migrations (core and plugins), the last run, and a button that runs `bin/prime update`.
 */
final class UpdatesController
{
    public function __construct(
        private readonly View $view,
        private readonly AdminContext $context,
        private readonly Application $app,
    ) {
    }

    public function index(Request $request): Response
    {
        $title = t('admin.tools.tabs.updates');
        $status = (new Updater($this->app))->status();

        return Response::html($this->view->render('admin::tools/updates', $this->context->build($request, $title, [
            'activeTab' => 'updates',
            'status' => $status,
            'upToDate' => $status['pending'] === [] && version_compare($status['installed'], $status['code'], '>='),
            'breadcrumbs' => [
                ['label' => t('admin.nav.dashboard'), 'url' => '/admin'],
                ['label' => t('admin.tools.title'), 'url' => '/admin/tools'],
                ['label' => $title, 'url' => null],
            ],
        ])));
    }

    public function run(Request $request): Response
    {
        $confirmed = $request->body('backup_done') === '1';
        $automatic = $request->body('backup_mode') === 'auto';

        try {
            $result = (new Updater($this->app))->run(backupConfirmed: $confirmed, automaticBackup: $automatic);
            $this->context->flashToast(
                t('admin.updates.done', ['from' => $result['from'], 'to' => $result['to'], 'count' => (string) $result['migrations']]),
                'success',
            );
        } catch (Throwable $exception) {
            $this->context->flashToast($exception->getMessage(), 'danger', t('admin.updates.failed'));
        }

        return Response::redirect('/admin/tools/updates');
    }
}
