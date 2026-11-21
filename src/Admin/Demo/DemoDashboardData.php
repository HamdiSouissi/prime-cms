<?php

declare(strict_types=1);

namespace Prime\Admin\Demo;

use Prime\Kernel\Application;

/**
 * Isolated demonstration data for the Phase 2 dashboard preview.
 *
 * Kept strictly isolated in its own class so it can be cleanly removed once
 * real database repositories arrive in Phases 3–6.
 */
final class DemoDashboardData
{
    use \Prime\Admin\TranslatesAdminCopy;

    /**
     * Four vibrant overview KPI tiles displayed on the dashboard home.
     *
     * @return list<array{
     *   label: string,
     *   value: string,
     *   icon: string,
     *   accent: string,
     *   trend: string,
     *   trendDirection: 'up'|'down'|'flat',
     *   hint: string,
     *   url: string
     * }>
     */
    public function stats(): array
    {
        return [
            [
                'label' => $this->t('admin.ctrl_demo_demodashboarddata.pages_publiees'),
                'value' => '18',
                'icon' => 'file-text',
                'accent' => 'teal',
                'trend' => $this->t('admin.ctrl_demo_demodashboarddata.trend_plus_3_ce_mois'),
                'trendDirection' => 'up',
                'hint' => $this->t('admin.ctrl_demo_demodashboarddata.hint_2_brouillons'),
                'url' => '/admin/content/page',
            ],
            [
                'label' => $this->t('admin.ctrl_demo_demodashboarddata.module_articles'),
                'value' => '42',
                'icon' => 'newspaper',
                'accent' => 'violet',
                'trend' => '+12 %',
                'trendDirection' => 'up',
                'hint' => $this->t('admin.ctrl_demo_demodashboarddata.5_programmes'),
                'url' => '/admin/content/post',
            ],
            [
                'label' => $this->t('admin.shared.medias'),
                'value' => '264',
                'icon' => 'image',
                'accent' => 'sky',
                'trend' => '128 Mo',
                'trendDirection' => 'flat',
                'hint' => 'WebP & PNG',
                'url' => '/admin/media',
            ],
            [
                'label' => $this->t('admin.shared.utilisateurs'),
                'value' => '6',
                'icon' => 'users',
                'accent' => 'amber',
                'trend' => $this->t('admin.ctrl_demo_demodashboarddata.100_actifs'),
                'trendDirection' => 'up',
                'hint' => $this->t('admin.ctrl_demo_demodashboarddata.3_roles'),
                'url' => '/admin/users',
            ],
        ];
    }

    /**
     * Recent editorial and system events (demo dataset).
     *
     * @return list<array{
     *   title: string,
     *   meta: string,
     *   author: string,
     *   initials: string,
     *   status: string,
     *   statusVariant: string,
     *   accent: string
     * }>
     */
    public function recentActivity(): array
    {
        return [
            [
                'title' => $this->t('admin.ctrl_demo_demodashboarddata.page_a_propos_de_notre_equipe_mise_a_jour'),
                'meta' => $this->t('admin.ctrl_demo_demodashboarddata.modifiee_par_claire_moreau_il_y_a_18_minutes'),
                'author' => 'Claire Moreau',
                'initials' => 'CM',
                'status' => $this->t('admin.shared.publie'),
                'statusVariant' => 'success',
                'accent' => 'teal',
            ],
            [
                'title' => $this->t('admin.ctrl_demo_demodashboarddata.article_guide_complet_du_design_system_prime'),
                'meta' => $this->t('admin.ctrl_demo_demodashboarddata.enregistre_en_brouillon_par_lucas_bernard_il_y_a_1_heure'),
                'author' => 'Lucas Bernard',
                'initials' => 'LB',
                'status' => $this->t('admin.shared.brouillon'),
                'statusVariant' => 'amber',
                'accent' => 'violet',
            ],
            [
                'title' => $this->t('admin.ctrl_demo_demodashboarddata.lot_de_8_visuels_importe_dans_la_mediatheque'),
                'meta' => $this->t('admin.ctrl_demo_demodashboarddata.optimise_automatiquement_il_y_a_3_heures'),
                'author' => 'Nadia Benali',
                'initials' => 'NB',
                'status' => $this->t('admin.ctrl_demo_demodashboarddata.traite'),
                'statusVariant' => 'sky',
                'accent' => 'sky',
            ],
            [
                'title' => $this->t('admin.ctrl_demo_demodashboarddata.verification_des_en_tetes_de_securite_http'),
                'meta' => $this->t('admin.ctrl_demo_demodashboarddata.csp_stricte_et_protection_xss_verifiees_hier_a_17_40'),
                'author' => $this->t('admin.ctrl_demo_demodashboarddata.systeme'),
                'initials' => 'SY',
                'status' => $this->t('admin.ctrl_demo_demodashboarddata.conforme'),
                'statusVariant' => 'brand',
                'accent' => 'brand',
            ],
        ];
    }

    /**
     * Quick shortcuts displayed on the dashboard home.
     *
     * @return list<array{
     *   title: string,
     *   description: string,
     *   icon: string,
     *   accent: string,
     *   url: string
     * }>
     */
    public function shortcuts(): array
    {
        return [
            [
                'title' => $this->t('admin.demo.shortcut_create_page'),
                'description' => $this->t('admin.demo.shortcut_create_page_desc'),
                'icon' => 'file-text',
                'accent' => 'teal',
                'url' => '/admin/content/page/create',
            ],
            [
                'title' => $this->t('admin.demo.shortcut_write_post'),
                'description' => $this->t('admin.demo.shortcut_write_post_desc'),
                'icon' => 'newspaper',
                'accent' => 'violet',
                'url' => '/admin/content/post/create',
            ],
            [
                'title' => $this->t('admin.demo.shortcut_import_media'),
                'description' => $this->t('admin.demo.shortcut_import_media_desc'),
                'icon' => 'upload',
                'accent' => 'sky',
                'url' => '/admin/media',
            ],
            [
                'title' => $this->t('admin.demo.shortcut_guide'),
                'description' => $this->t('admin.demo.shortcut_guide_desc'),
                'icon' => 'sparkles',
                'accent' => 'amber',
                'url' => '/admin/styleguide',
            ],
        ];
    }

    /**
     * Real runtime diagnostics alongside upcoming phase milestones.
     *
     * @return list<array{label: string, value: string, badge?: string, variant?: string}>
     */
    public function systemStatus(Application $app): array
    {
        return [
            [
                'label' => $this->t('admin.shared.version_prime_cms'),
                'value' => Application::VERSION,
                'badge' => $this->t('admin.ctrl_demo_demodashboarddata.phase_7'),
                'variant' => 'brand',
            ],
            [
                'label' => $this->t('admin.shared.version_php'),
                'value' => PHP_VERSION,
                'badge' => 'OK',
                'variant' => 'success',
            ],
            [
                'label' => $this->t('admin.shared.environnement'),
                'value' => $app->environment(),
                'badge' => $app->isDebug() ? $this->t('admin.ctrl_demo_demodashboarddata.debug_actif') : $this->t('admin.ctrl_demo_demodashboarddata.production'),
                'variant' => $app->isDebug() ? 'amber' : 'neutral',
            ],
            [
                'label' => $this->t('admin.ctrl_demo_demodashboarddata.base_de_donnees'),
                'value' => 'MySQL / MariaDB',
                'badge' => $this->t('admin.ctrl_demo_demodashboarddata.active'),
                'variant' => 'success',
            ],
            [
                'label' => $this->t('admin.ctrl_demo_demodashboarddata.authentification'),
                'value' => $this->t('admin.ctrl_demo_demodashboarddata.sessions_rbac_2fa'),
                'badge' => $this->t('admin.ctrl_demo_demodashboarddata.active'),
                'variant' => 'success',
            ],
        ];
    }
}
