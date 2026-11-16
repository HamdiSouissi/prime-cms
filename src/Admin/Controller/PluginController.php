<?php

declare(strict_types=1);

namespace Prime\Admin\Controller;

use Prime\Admin\AdminContext;
use Prime\Admin\TranslatesAdminCopy;
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
    use TranslatesAdminCopy;

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
                ['label' => $this->t('admin.ctrl_text_plugin.tableau_de_bord'), 'url' => '/admin'],
                ['label' => 'Plugins', 'url' => null],
            ],
        ]));

        return Response::html($html);
    }

    public function activate(Request $request, string $slug): Response
    {
        if ($this->manager->isSafeMode()) {
            $message = $this->t('admin.ctrl_text_plugin.impossible_d_activer_un_plugin_tant_que_prime_safe_mode_est_');
            if ($this->wantsJson($request)) {
                return Response::json(['ok' => false, 'message' => $message], 409);
            }
            $this->context->flashToast($message, 'warning', $this->t('admin.ctrl_text_plugin.mode_sans_echec'));

            return Response::redirect('/admin/plugins');
        }

        try {
            $info = $this->manager->activate($slug);
            $message = sprintf($this->t('admin.ctrl_text_plugin.le_plugin_s_v_s_a_ete_active'), $info->name(), $info->version());
            $this->context->flashToast($message, 'success', $this->t('admin.ctrl_text_plugin.plugin_active'));

            if ($this->wantsJson($request)) {
                return Response::json(['ok' => true, 'slug' => $slug, 'message' => $message]);
            }
        } catch (PluginException $exception) {
            if ($this->wantsJson($request)) {
                return Response::json(['ok' => false, 'slug' => $slug, 'message' => $exception->getMessage()], 422);
            }
            $this->context->flashToast($exception->getMessage(), 'danger', $this->t('admin.ctrl_text_plugin.echec_d_activation'));
        }

        return Response::redirect('/admin/plugins');
    }

    public function deactivate(Request $request, string $slug): Response
    {
        try {
            $info = $this->manager->deactivate($slug);
            $message = sprintf($this->t('admin.ctrl_text_plugin.le_plugin_s_a_ete_desactive_ses_donnees_ont_ete_conservees'), $info->name());
            $this->context->flashToast($message, 'info', $this->t('admin.ctrl_text_plugin.plugin_desactive'));

            if ($this->wantsJson($request)) {
                return Response::json(['ok' => true, 'slug' => $slug, 'message' => $message]);
            }
        } catch (PluginException $exception) {
            if ($this->wantsJson($request)) {
                return Response::json(['ok' => false, 'slug' => $slug, 'message' => $exception->getMessage()], 422);
            }
            $this->context->flashToast($exception->getMessage(), 'danger', $this->t('admin.ctrl_text_plugin.desactivation_impossible'));
        }

        return Response::redirect('/admin/plugins');
    }

    public function uninstall(Request $request, string $slug): Response
    {
        $info = $this->manager->get($slug, fresh: true);
        $displayName = $info !== null ? $info->name() : $slug;

        try {
            $this->manager->uninstall($slug, deleteFiles: true);
            $message = sprintf($this->t('admin.ctrl_text_plugin.le_plugin_s_ainsi_que_ses_donnees_ont_ete_supprimes'), $displayName);
            $this->context->flashToast($message, 'success', $this->t('admin.ctrl_text_plugin.plugin_supprime'));

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
            $message = $this->t('admin.ctrl_text_plugin.le_televersement_de_plugins_zip_est_desactive_dans_la_config');
            if ($wantsJson) {
                return Response::json(['ok' => false, 'message' => $message], 403);
            }
            $this->context->flashToast($message, 'warning', $this->t('admin.ctrl_text_plugin.televersement_desactive'));

            return Response::redirect('/admin/plugins');
        }

        $files = $request->files();
        $file = $files['plugin_zip'] ?? null;

        if (!is_array($file) || !isset($file['error'])) {
            $message = $this->t('admin.ctrl_text_plugin.veuillez_selectionner_une_archive_zip_de_plugin_a_televerser');
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
            $this->context->flashToast($message, 'danger', $this->t('admin.ctrl_text_plugin.erreur_de_televersement'));

            return Response::redirect('/admin/plugins');
        }

        $tmpPath = isset($file['tmp_name']) && is_string($file['tmp_name']) ? $file['tmp_name'] : '';
        if ($tmpPath === '' || !is_file($tmpPath)) {
            $message = $this->t('admin.ctrl_text_plugin.fichier_temporaire_de_televersement_introuvable');
            if ($wantsJson) {
                return Response::json(['ok' => false, 'message' => $message], 422);
            }
            $this->context->flashToast($message, 'danger', $this->t('admin.ctrl_text_plugin.erreur_de_televersement'));

            return Response::redirect('/admin/plugins');
        }

        $replaceExisting = (string) $request->body('replace_existing', '0') === '1';
        $force = (string) $request->body('force_replace', '0') === '1';

        try {
            $result = $this->installer->installFromZip($tmpPath, $replaceExisting, $force);
            $manifest = $result['manifest'];
            $message = $result['replaced']
                ? sprintf($this->t('admin.ctrl_text_plugin.le_plugin_s_a_ete_mis_a_jour_vers_la_version_s'), $manifest->name, $manifest->version)
                : sprintf($this->t('admin.ctrl_text_plugin.le_plugin_s_v_s_a_ete_installe_vous_pouvez_maintenant_l_acti'), $manifest->name, $manifest->version);

            $this->context->flashToast($message, 'success', $result['replaced'] ? $this->t('admin.ctrl_text_plugin.plugin_remplace') : $this->t('admin.ctrl_text_plugin.plugin_installe'));

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

            $this->context->flashToast($exception->getMessage(), 'danger', $this->t('admin.ctrl_text_plugin.installation_refusee'));
        }

        return Response::redirect('/admin/plugins');
    }

    private function uploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => sprintf(
                $this->t('admin.ctrl_text_plugin.le_fichier_depasse_la_taille_maximale_autorisee_d_mo'),
                max(1, (int) $this->app->config()->get('plugins.max_upload_mb', 20)),
            ),
            UPLOAD_ERR_PARTIAL => $this->t('admin.ctrl_text_plugin.le_televersement_du_fichier_a_ete_interrompu'),
            UPLOAD_ERR_NO_FILE => $this->t('admin.ctrl_text_plugin.aucun_fichier_n_a_ete_envoye'),
            default => $this->t('admin.ctrl_text_plugin.une_erreur_serveur_est_survenue_lors_du_televersement_de_l_a'),
        };
    }

    private function wantsJson(Request $request): bool
    {
        $accept = strtolower((string) $request->header('accept', ''));
        $xhr = strtolower((string) $request->header('x-requested-with', ''));

        return str_contains($accept, 'application/json') || $xhr === 'xmlhttprequest';
    }
}
