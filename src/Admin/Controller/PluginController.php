<?php

declare(strict_types=1);

namespace Prime\Admin\Controller;

use Prime\Admin\AdminContext;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Kernel\Application;
use Prime\Plugins\Exception\PluginException;
use Prime\Plugins\Exception\PluginInstallationException;
use Prime\Plugins\PluginInfo;
use Prime\Plugins\PluginInstaller;
use Prime\Plugins\PluginManager;
use Prime\View\View;

/**
 * Administration controller for `/admin/plugins` (`plugins.manage`).
 */
final class PluginController
{
    public function __construct(
        private readonly Application $app,
        private readonly View $view,
        private readonly AdminContext $context,
        private readonly PluginManager $manager,
        private readonly PluginInstaller $installer,
    ) {
    }

    public function index(Request $request): Response
    {
        $allPlugins = $this->manager->discover(fresh: true);

        $statusFilter = trim((string) $request->query('status', 'all'));
        if (!in_array($statusFilter, ['all', PluginInfo::STATUS_ACTIVE, PluginInfo::STATUS_INACTIVE, PluginInfo::STATUS_ERRORED], true)) {
            $statusFilter = 'all';
        }

        $search = trim((string) $request->query('q', ''));
        $searchLower = mb_strtolower($search);

        $counts = [
            'all' => count($allPlugins),
            PluginInfo::STATUS_ACTIVE => 0,
            PluginInfo::STATUS_INACTIVE => 0,
            PluginInfo::STATUS_ERRORED => 0,
        ];

        foreach ($allPlugins as $info) {
            if (isset($counts[$info->status])) {
                $counts[$info->status]++;
            }
        }

        $filtered = [];
        foreach ($allPlugins as $slug => $info) {
            if ($statusFilter !== 'all' && $info->status !== $statusFilter) {
                continue;
            }

            if ($searchLower !== '') {
                $haystack = mb_strtolower(implode(' ', [
                    $info->name(),
                    $info->slug,
                    $info->manifest !== null ? $info->manifest->description : '',
                    $info->manifest !== null ? $info->manifest->authorName : '',
                ]));
                if (!str_contains($haystack, $searchLower)) {
                    continue;
                }
            }

            $filtered[$slug] = $info;
        }

        $allowUpload = (bool) $this->app->config()->get('plugins.allow_upload', true);
        $maxUploadMb = max(1, (int) $this->app->config()->get('plugins.max_upload_mb', 20));

        $html = $this->view->render('admin::plugins/index', $this->context->build($request, 'Plugins', [
            'plugins' => array_values($filtered),
            'allPlugins' => $allPlugins,
            'counts' => $counts,
            'filters' => [
                'status' => $statusFilter,
                'q' => $search,
            ],
            'allowUpload' => $allowUpload,
            'maxUploadMb' => $maxUploadMb,
            'primaryCtaInContent' => $allowUpload,
            'breadcrumbs' => [
                ['label' => 'Tableau de bord', 'url' => '/admin'],
                ['label' => 'Plugins', 'url' => null],
            ],
        ]));

        return Response::html($html);
    }

    public function activate(Request $request, string $slug): Response
    {
        if ($this->manager->isSafeMode()) {
            $message = 'Impossible d\'activer un plugin tant que PRIME_SAFE_MODE est activé.';
            if ($this->wantsJson($request)) {
                return Response::json(['ok' => false, 'message' => $message], 409);
            }
            $this->context->flashToast($message, 'warning', 'Mode sans échec');

            return Response::redirect('/admin/plugins');
        }

        try {
            $info = $this->manager->activate($slug);
            $message = sprintf('Le plugin « %s » (v%s) a été activé.', $info->name(), $info->version());
            $this->context->flashToast($message, 'success', 'Plugin activé');

            if ($this->wantsJson($request)) {
                return Response::json(['ok' => true, 'slug' => $slug, 'message' => $message]);
            }
        } catch (PluginException $exception) {
            if ($this->wantsJson($request)) {
                return Response::json(['ok' => false, 'slug' => $slug, 'message' => $exception->getMessage()], 422);
            }
            $this->context->flashToast($exception->getMessage(), 'danger', 'Échec d\'activation');
        }

        return Response::redirect('/admin/plugins');
    }

    public function deactivate(Request $request, string $slug): Response
    {
        try {
            $info = $this->manager->deactivate($slug);
            $message = sprintf('Le plugin « %s » a été désactivé. Ses données ont été conservées.', $info->name());
            $this->context->flashToast($message, 'info', 'Plugin désactivé');

            if ($this->wantsJson($request)) {
                return Response::json(['ok' => true, 'slug' => $slug, 'message' => $message]);
            }
        } catch (PluginException $exception) {
            if ($this->wantsJson($request)) {
                return Response::json(['ok' => false, 'slug' => $slug, 'message' => $exception->getMessage()], 422);
            }
            $this->context->flashToast($exception->getMessage(), 'danger', 'Désactivation impossible');
        }

        return Response::redirect('/admin/plugins');
    }

    public function uninstall(Request $request, string $slug): Response
    {
        $info = $this->manager->get($slug, fresh: true);
        $displayName = $info !== null ? $info->name() : $slug;

        try {
            $this->manager->uninstall($slug, deleteFiles: true);
            $message = sprintf('Le plugin « %s » ainsi que ses données ont été supprimés.', $displayName);
            $this->context->flashToast($message, 'success', 'Plugin supprimé');

            if ($this->wantsJson($request)) {
                return Response::json(['ok' => true, 'slug' => $slug, 'message' => $message]);
            }
        } catch (PluginException $exception) {
            if ($this->wantsJson($request)) {
                return Response::json(['ok' => false, 'slug' => $slug, 'message' => $exception->getMessage()], 422);
            }
            $this->context->flashToast($exception->getMessage(), 'danger', 'Suppression impossible');
        }

        return Response::redirect('/admin/plugins');
    }

    public function upload(Request $request): Response
    {
        $wantsJson = $this->wantsJson($request);

        if (!(bool) $this->app->config()->get('plugins.allow_upload', true)) {
            $message = 'Le téléversement de plugins ZIP est désactivé dans la configuration (PLUGINS_ALLOW_UPLOAD=false).';
            if ($wantsJson) {
                return Response::json(['ok' => false, 'message' => $message], 403);
            }
            $this->context->flashToast($message, 'warning', 'Téléversement désactivé');

            return Response::redirect('/admin/plugins');
        }

        $files = $request->files();
        $file = $files['plugin_zip'] ?? null;

        if (!is_array($file) || !isset($file['error'])) {
            $message = 'Veuillez sélectionner une archive ZIP de plugin à téléverser.';
            if ($wantsJson) {
                return Response::json(['ok' => false, 'message' => $message], 422);
            }
            $this->context->flashToast($message, 'danger', 'Archive manquante');

            return Response::redirect('/admin/plugins');
        }

        $uploadError = (int) $file['error'];
        if ($uploadError !== UPLOAD_ERR_OK) {
            $message = $this->uploadErrorMessage($uploadError);
            if ($wantsJson) {
                return Response::json(['ok' => false, 'message' => $message], 422);
            }
            $this->context->flashToast($message, 'danger', 'Erreur de téléversement');

            return Response::redirect('/admin/plugins');
        }

        $tmpPath = isset($file['tmp_name']) && is_string($file['tmp_name']) ? $file['tmp_name'] : '';
        if ($tmpPath === '' || !is_file($tmpPath)) {
            $message = 'Fichier temporaire de téléversement introuvable.';
            if ($wantsJson) {
                return Response::json(['ok' => false, 'message' => $message], 422);
            }
            $this->context->flashToast($message, 'danger', 'Erreur de téléversement');

            return Response::redirect('/admin/plugins');
        }

        $replaceExisting = (string) $request->body('replace_existing', '0') === '1';
        $force = (string) $request->body('force_replace', '0') === '1';

        try {
            $result = $this->installer->installFromZip($tmpPath, $replaceExisting, $force);
            $manifest = $result['manifest'];
            $message = $result['replaced']
                ? sprintf('Le plugin « %s » a été mis à jour vers la version %s.', $manifest->name, $manifest->version)
                : sprintf('Le plugin « %s » (v%s) a été installé. Vous pouvez maintenant l\'activer.', $manifest->name, $manifest->version);

            $this->context->flashToast($message, 'success', $result['replaced'] ? 'Plugin remplacé' : 'Plugin installé');

            if ($wantsJson) {
                return Response::json([
                    'ok' => true,
                    'slug' => $manifest->slug,
                    'name' => $manifest->name,
                    'version' => $manifest->version,
                    'replaced' => $result['replaced'],
                    'warnings' => $result['warnings'],
                    'message' => $message,
                ]);
            }
        } catch (PluginInstallationException $exception) {
            if ($wantsJson) {
                if ($exception->isReplacementConflict()) {
                    return Response::json([
                        'ok' => false,
                        'conflict' => true,
                        'slug' => $exception->conflictSlug,
                        'existing_version' => $exception->existingVersion,
                        'incoming_version' => $exception->incomingVersion,
                        'is_downgrade_or_same' => $exception->isDowngradeOrSame,
                        'message' => $exception->getMessage(),
                    ], 409);
                }

                return Response::json(['ok' => false, 'message' => $exception->getMessage()], 422);
            }

            $this->context->flashToast($exception->getMessage(), 'danger', 'Installation refusée');
        }

        return Response::redirect('/admin/plugins');
    }

    private function uploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => sprintf(
                'Le fichier dépasse la taille maximale autorisée (%d Mo).',
                max(1, (int) $this->app->config()->get('plugins.max_upload_mb', 20)),
            ),
            UPLOAD_ERR_PARTIAL => 'Le téléversement du fichier a été interrompu.',
            UPLOAD_ERR_NO_FILE => 'Aucun fichier n\'a été envoyé.',
            default => 'Une erreur serveur est survenue lors du téléversement de l\'archive.',
        };
    }

    private function wantsJson(Request $request): bool
    {
        $accept = strtolower((string) $request->header('accept', ''));
        $xhr = strtolower((string) $request->header('x-requested-with', ''));

        return str_contains($accept, 'application/json') || $xhr === 'xmlhttprequest';
    }
}
