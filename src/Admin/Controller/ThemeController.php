<?php

declare(strict_types=1);

namespace Prime\Admin\Controller;

use Prime\Admin\AdminContext;
use Prime\Admin\TranslatesAdminCopy;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Kernel\Application;
use Prime\Media\MediaRepository;
use Prime\Settings\Settings;
use Prime\Themes\Exception\ThemeException;
use Prime\Themes\Exception\ThemeInstallationException;
use Prime\Themes\Exception\ThemeValidationException;
use Prime\Themes\ThemeInstaller;
use Prime\Themes\ThemeManager;
use Prime\Themes\ThemeOptions;
use Prime\View\View;

/**
 * Admin controller for « Apparence › Thèmes » and « Apparence › Personnaliser » (`themes.manage`).
 */
final class ThemeController
{
    use TranslatesAdminCopy;

    public function __construct(
        private readonly Application $app,
        private readonly View $view,
        private readonly AdminContext $context,
        private readonly ThemeManager $themes,
        private readonly ThemeOptions $options,
        private readonly ThemeInstaller $installer,
        private readonly Settings $settings,
        private readonly ?MediaRepository $media = null,
    ) {
    }

    /**
     * Render the installed themes gallery (`GET /admin/appearance`, `GET /admin/appearance/themes`).
     */
    public function index(Request $request): Response
    {
        $all = $this->themes->discover(fresh: true);
        $active = null;
        foreach ($all as $info) {
            if ($info->active) {
                $active = $info;
                break;
            }
        }

        $allowUpload = (bool) $this->app->config()->get('themes.allow_upload', true);
        $maxUploadMb = (int) $this->app->config()->get('themes.max_upload_mb', 20);
        $fallbackError = $this->themes->fallbackError();

        $html = $this->view->render('admin::appearance/themes', $this->context->build($request, 'Thèmes', [
            'themes' => array_values($all),
            'activeTheme' => $active,
            'allowUpload' => $allowUpload,
            'maxUploadMb' => $maxUploadMb,
            'safeMode' => $this->themes->isSafeMode(),
            'fallbackError' => $fallbackError,
            'primaryCtaInContent' => $allowUpload,
            'breadcrumbs' => [
                ['label' => $this->t('admin.ctrl_text_theme.tableau_de_bord'), 'url' => '/admin'],
                ['label' => 'Apparence', 'url' => '/admin/appearance/themes'],
                ['label' => 'Thèmes', 'url' => null],
            ],
        ]));

        return Response::html($html);
    }

    /**
     * Activate an installed theme (`POST /admin/appearance/themes/{slug}/activate`).
     */
    public function activate(Request $request, string $slug): Response
    {
        try {
            $activated = $this->themes->activate($slug);
            $this->context->flashToast(sprintf($this->t('admin.ctrl_text_theme.le_theme_s_est_desormais_actif'), $activated->name()), 'success');
        } catch (ThemeException $e) {
            $this->context->flashToast($e->getMessage(), 'danger');
        }

        return Response::redirect('/admin/appearance/themes');
    }

    /**
     * Delete an inactive, non-default, non-parent theme (`POST /admin/appearance/themes/{slug}/delete`).
     */
    public function delete(Request $request, string $slug): Response
    {
        try {
            $this->themes->delete($slug);
            $this->context->flashToast(sprintf($this->t('admin.ctrl_text_theme.le_theme_s_a_ete_supprime'), $slug), 'success');
        } catch (ThemeException $e) {
            $this->context->flashToast($e->getMessage(), 'danger');
        }

        return Response::redirect('/admin/appearance/themes');
    }

    /**
     * Upload and securely install a theme ZIP archive (`POST /admin/appearance/themes/upload`).
     */
    public function upload(Request $request): Response
    {
        if (!(bool) $this->app->config()->get('themes.allow_upload', true)) {
            $this->context->flashToast($this->t('admin.ctrl_text_theme.le_televersement_de_themes_zip_est_desactive_sur_cette_insta'), 'danger');

            return Response::redirect('/admin/appearance/themes');
        }

        $file = $request->file('theme_zip');
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->context->flashToast($this->t('admin.ctrl_text_theme.veuillez_selectionner_une_archive_zip_valide'), 'danger');

            return Response::redirect('/admin/appearance/themes');
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        $replace = $request->input('replace_existing') === '1';

        try {
            $result = $this->installer->installFromZip($tmpPath, replaceExisting: $replace);
            $manifest = $result['manifest'];
            $msg = $result['replaced']
                ? sprintf($this->t('admin.ctrl_text_theme.le_theme_s_a_ete_mis_a_jour_vers_la_version_s'), $manifest->name, $manifest->version)
                : sprintf($this->t('admin.ctrl_text_theme.le_theme_s_v_s_a_ete_installe_avec_succes'), $manifest->name, $manifest->version);
            $this->context->flashToast($msg, 'success');
        } catch (ThemeInstallationException $e) {
            $this->context->flashToast($e->getMessage(), 'danger');
        }

        return Response::redirect('/admin/appearance/themes');
    }

    /**
     * Render the live theme Customizer (`GET /admin/appearance/customizer`).
     */
    public function customizer(Request $request): Response
    {
        $active = $this->themes->active();
        $parent = $this->themes->activeParent();
        $manifest = $active->manifest;

        $schema = $manifest !== null
            ? $this->options->schema($manifest, $parent?->manifest)
            : ['sections' => [], 'fields' => []];
        $values = $manifest !== null
            ? $this->options->all($manifest, $parent?->manifest)
            : [];

        $rawSiteName = $this->settings->get('site.name') ?? $this->settings->get('site_name', 'Prime CMS');
        $siteName = is_string($rawSiteName) && trim($rawSiteName) !== '' ? trim($rawSiteName) : 'Prime CMS';
        $rawTagline = $this->settings->get('site.tagline') ?? $this->settings->get('site_tagline', '');
        $siteTagline = is_string($rawTagline) ? trim($rawTagline) : '';
        $logoId = $this->settings->get('site.logo_id');
        $iconId = $this->settings->get('site.icon_id');
        $logoMedia = (is_numeric($logoId) && (int) $logoId > 0 && $this->media !== null) ? $this->media->find((int) $logoId) : null;
        $iconMedia = (is_numeric($iconId) && (int) $iconId > 0 && $this->media !== null) ? $this->media->find((int) $iconId) : null;

        $optionMedia = [];
        if ($this->media !== null) {
            $ids = [];
            foreach ($schema['fields'] as $key => $field) {
                if (($field['type'] ?? '') === 'media' && is_numeric($values[$key] ?? null) && (int) $values[$key] > 0) {
                    $ids[] = (int) $values[$key];
                }
            }
            if ($ids !== []) {
                $optionMedia = $this->media->findByIds($ids);
            }
        }

        $html = $this->view->render('admin::appearance/customizer', $this->context->build($request, 'Personnaliser', [
            'activeTheme' => $active,
            'schema' => $schema,
            'values' => $values,
            'siteIdentity' => [
                'name' => $siteName,
                'tagline' => $siteTagline,
                'logo_id' => is_numeric($logoId) ? (int) $logoId : null,
                'logo_url' => $logoMedia?->url(),
                'icon_id' => is_numeric($iconId) ? (int) $iconId : null,
                'icon_url' => $iconMedia?->url(),
            ],
            'optionMedia' => $optionMedia,
            'previewUrl' => '/?customizer_preview=1',
            'primaryCtaInContent' => true,
            'breadcrumbs' => [
                ['label' => $this->t('admin.ctrl_text_theme.tableau_de_bord'), 'url' => '/admin'],
                ['label' => 'Apparence', 'url' => '/admin/appearance/themes'],
                ['label' => 'Personnaliser', 'url' => null],
            ],
        ]));

        return Response::html($html);
    }

    /**
     * Validate and publish Customizer site identity + theme options (`POST /admin/appearance/customizer/save`).
     */
    public function saveCustomizer(Request $request): Response
    {
        $active = $this->themes->active();
        $parent = $this->themes->activeParent();
        $manifest = $active->manifest;

        if ($manifest === null) {
            return $this->customizerErrorResponse($request, $this->t('admin.ctrl_text_theme.aucun_theme_valide_actif'));
        }

        $payload = $this->extractBody($request);

        $identity = is_array($payload['identity'] ?? null) ? $payload['identity'] : [];
        if (array_key_exists('name', $identity) && is_string($identity['name'])) {
            $name = trim(strip_tags($identity['name']));
            if ($name !== '') {
                $cleanName = mb_substr($name, 0, 120);
                $this->settings->set('site.name', $cleanName, autoload: true);
                $this->settings->set('site_name', $cleanName, autoload: true);
            }
        }
        if (array_key_exists('tagline', $identity) && is_string($identity['tagline'])) {
            $tagline = trim(strip_tags($identity['tagline']));
            $cleanTagline = mb_substr($tagline, 0, 240);
            $this->settings->set('site.tagline', $cleanTagline, autoload: true);
            $this->settings->set('site_tagline', $cleanTagline, autoload: true);
        }
        if (array_key_exists('logo_id', $identity)) {
            $logoId = is_numeric($identity['logo_id']) && (int) $identity['logo_id'] > 0 ? (int) $identity['logo_id'] : null;
            $this->settings->set('site.logo_id', $logoId, autoload: true);
        }
        if (array_key_exists('icon_id', $identity)) {
            $iconId = is_numeric($identity['icon_id']) && (int) $identity['icon_id'] > 0 ? (int) $identity['icon_id'] : null;
            $this->settings->set('site.icon_id', $iconId, autoload: true);
            if ($iconId !== null) {
                $this->options->generateFaviconsFromMedia($iconId);
            } else {
                $this->settings->delete('site.favicons');
            }
        }

        /** @var array<string, mixed> $rawOptions */
        $rawOptions = is_array($payload['options'] ?? null) ? $payload['options'] : [];

        try {
            $saved = $this->options->save($manifest, $rawOptions, $parent?->manifest);
        } catch (ThemeValidationException $e) {
            if ($request->wantsJson()) {
                return Response::json([
                    'ok' => false,
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ], 422);
            }
            $this->context->flashToast($e->getMessage(), 'danger');

            return Response::redirect('/admin/appearance/customizer');
        }

        if ($request->wantsJson()) {
            return Response::json([
                'ok' => true,
                'message' => $this->t('admin.ctrl_text_theme.personnalisation_publiee_avec_succes'),
                'values' => $saved['values'],
                'css_url' => $saved['css_url'],
            ]);
        }

        $this->context->flashToast($this->t('admin.ctrl_text_theme.les_reglages_d_apparence_ont_ete_publies'), 'success');

        return Response::redirect('/admin/appearance/customizer');
    }

    /**
     * Reset all active theme options back to manifest defaults (`POST /admin/appearance/customizer/reset`).
     */
    public function resetCustomizer(Request $request): Response
    {
        $active = $this->themes->active();
        $parent = $this->themes->activeParent();

        if ($active->manifest === null) {
            return $this->customizerErrorResponse($request, $this->t('admin.ctrl_text_theme.aucun_theme_valide_actif'));
        }

        $reset = $this->options->reset($active->manifest, $parent?->manifest);

        if ($request->wantsJson()) {
            return Response::json([
                'ok' => true,
                'message' => $this->t('admin.ctrl_text_theme.options_reinitialisees_aux_valeurs_par_defaut'),
                'values' => $reset['values'],
                'css_url' => $reset['css_url'],
            ]);
        }

        $this->context->flashToast($this->t('admin.ctrl_text_theme.les_options_du_theme_ont_ete_reinitialisees'), 'success');

        return Response::redirect('/admin/appearance/customizer');
    }

    private function customizerErrorResponse(Request $request, string $message): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['ok' => false, 'message' => $message], 422);
        }
        $this->context->flashToast($message, 'danger');

        return Response::redirect('/admin/appearance/customizer');
    }

    /**
     * @return array<string, mixed>
     */
    private function extractBody(Request $request): array
    {
        $body = $request->body();
        if (is_array($body) && $body !== []) {
            /** @var array<string, mixed> $body */
            return $body;
        }
        $raw = $request->rawBody();
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                return $decoded;
            }
        }

        return [];
    }
}
