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

    /**
     * Metadata for every Phase 2 placeholder module & submenu route.
     *
     * @return array<string, array{
     *   title: string,
     *   subtitle: string,
     *   icon: string,
     *   accent: string,
     *   phase: string,
     *   parentTitle?: string,
     *   parentUrl?: string,
     *   description: string,
     *   planned: list<string>
     * }>
     */
    public function modulePlaceholders(): array
    {
        return [
            'pages' => [
                'title' => $this->t('admin.ctrl_demo_demodashboarddata.module_pages'),
                'subtitle' => $this->t('admin.ctrl_demo_demodashboarddata.gerez_l_arborescence_et_les_pages_statiques_de_votre_site'),
                'icon' => 'file-text',
                'accent' => 'teal',
                'phase' => $this->t('admin.ctrl_demo_demodashboarddata.phase_6_contenus'),
                'description' => $this->t('admin.ctrl_demo_demodashboarddata.la_gestion_complete_des_pages_hierarchie_statuts_revisions_e'),
                'planned' => [
                    $this->t('admin.ctrl_demo_demodashboarddata.liste_filtrable_recherche_et_actions_groupees'),
                    $this->t('admin.ctrl_demo_demodashboarddata.statuts_brouillon_planifie_publie_et_corbeille'),
                    $this->t('admin.ctrl_demo_demodashboarddata.historique_des_revisions_et_editeur_de_blocs_prime_editor'),
                ],
            ],
            'articles' => [
                'title' => $this->t('admin.ctrl_demo_demodashboarddata.module_articles'),
                'subtitle' => $this->t('admin.ctrl_demo_demodashboarddata.redigez_planifiez_et_classez_vos_publications_editoriales'),
                'icon' => 'newspaper',
                'accent' => 'teal',
                'phase' => $this->t('admin.ctrl_demo_demodashboarddata.phase_6_contenus'),
                'description' => $this->t('admin.ctrl_demo_demodashboarddata.le_module_articles_avec_categories_etiquettes_revisions_et_p'),
                'planned' => [
                    $this->t('admin.ctrl_demo_demodashboarddata.taxonomies_hierarchiques_et_etiquettes'),
                    $this->t('admin.ctrl_demo_demodashboarddata.planification_automatique_et_champs_personnalises'),
                    $this->t('admin.ctrl_demo_demodashboarddata.editeur_de_blocs_prime_editor_integre'),
                ],
            ],
            'media' => [
                'title' => $this->t('admin.shared.medias'),
                'subtitle' => $this->t('admin.ctrl_demo_demodashboarddata.centralisez_et_optimisez_vos_images_pdf_et_documents'),
                'icon' => 'image',
                'accent' => 'teal',
                'phase' => $this->t('admin.ctrl_demo_demodashboarddata.phase_7_mediatheque'),
                'description' => $this->t('admin.ctrl_demo_demodashboarddata.la_mediatheque_avec_televersement_securise_dossiers_et_varia'),
                'planned' => [
                    $this->t('admin.ctrl_demo_demodashboarddata.televersement_securise_avec_validation_mime_stricte'),
                    $this->t('admin.ctrl_demo_demodashboarddata.generation_automatique_de_miniatures_et_variantes_webp_gd'),
                    $this->t('admin.ctrl_demo_demodashboarddata.organisation_en_dossiers_et_selecteur_de_media_reutilisable'),
                ],
            ],
            'appearance' => [
                'title' => $this->t('admin.ctrl_demo_demodashboarddata.module_apparence'),
                'subtitle' => $this->t('admin.ctrl_demo_demodashboarddata.personnalisez_l_identite_visuelle_les_themes_et_la_navigatio'),
                'icon' => 'palette',
                'accent' => 'violet',
                'phase' => $this->t('admin.ctrl_demo_demodashboarddata.phase_8_themes_et_rendu_du_site'),
                'description' => $this->t('admin.ctrl_demo_demodashboarddata.le_systeme_de_themes_la_gestion_des_menus_des_zones_de_widge'),
                'planned' => [
                    $this->t('admin.ctrl_demo_demodashboarddata.activation_options_et_previsualisation_des_themes'),
                    $this->t('admin.ctrl_demo_demodashboarddata.editeur_visuel_de_menus_multi_niveaux'),
                    $this->t('admin.ctrl_demo_demodashboarddata.zones_de_widgets_configurables_sans_code'),
                ],
            ],
            'appearance/themes' => [
                'title' => $this->t('admin.ctrl_demo_demodashboarddata.module_themes'),
                'subtitle' => $this->t('admin.ctrl_demo_demodashboarddata.installez_et_activez_les_themes_de_votre_site_public'),
                'icon' => 'palette',
                'accent' => 'violet',
                'phase' => $this->t('admin.ctrl_demo_demodashboarddata.phase_8_themes_et_rendu_du_site'),
                'parentTitle' => $this->t('admin.ctrl_demo_demodashboarddata.module_apparence'),
                'parentUrl' => '/admin/appearance',
                'description' => $this->t('admin.ctrl_demo_demodashboarddata.la_bibliotheque_de_themes_la_hierarchie_de_templates_et_le_t'),
                'planned' => [
                    $this->t('admin.ctrl_demo_demodashboarddata.catalogue_de_themes_locaux_et_theme_par_defaut_aurora'),
                    'Options de personnalisation des couleurs et typographies',
                ],
            ],
            'appearance/menus' => [
                'title' => $this->t('admin.ctrl_demo_demodashboarddata.module_menus'),
                'subtitle' => $this->t('admin.ctrl_demo_demodashboarddata.organisez_les_liens_de_navigation_de_votre_site'),
                'icon' => 'layers',
                'accent' => 'violet',
                'phase' => $this->t('admin.ctrl_demo_demodashboarddata.phase_8_themes_et_rendu_du_site'),
                'parentTitle' => $this->t('admin.ctrl_demo_demodashboarddata.module_apparence'),
                'parentUrl' => '/admin/appearance',
                'description' => $this->t('admin.ctrl_demo_demodashboarddata.la_gestion_des_menus_de_navigation_publics_arrivera_en_phase'),
                'planned' => [
                    $this->t('admin.ctrl_demo_demodashboarddata.emplacements_de_menus_declares_par_le_theme_actif'),
                    $this->t('admin.ctrl_demo_demodashboarddata.reorganisation_hierarchique_accessible_au_clavier'),
                ],
            ],
            'appearance/widgets' => [
                'title' => $this->t('admin.ctrl_demo_demodashboarddata.module_widgets'),
                'subtitle' => $this->t('admin.ctrl_demo_demodashboarddata.composez_les_zones_laterales_et_pieds_de_page'),
                'icon' => 'layers',
                'accent' => 'violet',
                'phase' => $this->t('admin.ctrl_demo_demodashboarddata.phase_8_themes_et_rendu_du_site'),
                'parentTitle' => $this->t('admin.ctrl_demo_demodashboarddata.module_apparence'),
                'parentUrl' => '/admin/appearance',
                'description' => $this->t('admin.ctrl_demo_demodashboarddata.la_gestion_des_zones_de_widgets_reutilisables_arrivera_en_ph'),
                'planned' => [
                    $this->t('admin.ctrl_demo_demodashboarddata.zones_dynamiques_definies_par_le_theme'),
                    'Widgets natifs et extensibles par plugins',
                ],
            ],
            'plugins' => [
                'title' => $this->t('admin.ctrl_demo_demodashboarddata.module_plugins'),
                'subtitle' => $this->t('admin.ctrl_demo_demodashboarddata.etendez_prime_cms_avec_des_modules_isoles_et_verifies'),
                'icon' => 'plug',
                'accent' => 'violet',
                'phase' => $this->t('admin.ctrl_demo_demodashboarddata.phase_5_systeme_de_plugins'),
                'description' => $this->t('admin.ctrl_demo_demodashboarddata.le_gestionnaire_de_plugins_manifeste_plugin_json_cycle_de_vi'),
                'planned' => [
                    $this->t('admin.ctrl_demo_demodashboarddata.decouverte_activation_desactivation_et_desinstallation_propr'),
                    $this->t('admin.ctrl_demo_demodashboarddata.migrations_dediees_et_installation_par_archive_zip'),
                    'Isolation des erreurs et plugin d’exemple officiel',
                ],
            ],
            'settings' => [
                'title' => $this->t('admin.ctrl_demo_demodashboarddata.module_reglages'),
                'subtitle' => $this->t('admin.ctrl_demo_demodashboarddata.configurez_le_fonctionnement_global_de_votre_instance_prime_cms'),
                'icon' => 'settings',
                'accent' => 'neutral',
                'phase' => $this->t('admin.ctrl_demo_demodashboarddata.phase_9_reglages_langues_cache_mails_taches'),
                'description' => $this->t('admin.ctrl_demo_demodashboarddata.les_ecrans_de_configuration_persistes_l_i18n_le_cache_le_cli'),
                'planned' => [
                    $this->t('admin.ctrl_demo_demodashboarddata.identite_du_site_langues_fr_en_it_et_fuseau_horaire'),
                    $this->t('admin.ctrl_demo_demodashboarddata.cache_de_pages_et_d_objets_client_smtp_et_taches_planifiees'),
                    $this->t('admin.ctrl_demo_demodashboarddata.journal_d_audit_complet_des_actions_d_administration'),
                ],
            ],
            'settings/general' => [
                'title' => $this->t('admin.menu.settings.general'),
                'subtitle' => $this->t('admin.ctrl_demo_demodashboarddata.configurez_l_identite_du_site_ses_langues_et_son_fuseau'),
                'icon' => 'settings',
                'accent' => 'neutral',
                'phase' => $this->t('admin.ctrl_demo_demodashboarddata.phase_9_reglages_langues_cache_mails_taches'),
                'parentTitle' => $this->t('admin.ctrl_demo_demodashboarddata.module_reglages'),
                'parentUrl' => '/admin/settings',
                'description' => $this->t('admin.ctrl_demo_demodashboarddata.les_champs_declares_par_le_noyau_et_les_extensions_dans'),
                'planned' => [
                    $this->t('admin.ctrl_demo_demodashboarddata.formulaire_genere_depuis_le_registre_des_reglages'),
                    $this->t('admin.ctrl_demo_demodashboarddata.enregistrement_valide_puis_chiffre_pour_les_champs_secret'),
                ],
            ],
            'settings/security' => [
                'title' => $this->t('admin.menu.settings.security'),
                'subtitle' => $this->t('admin.ctrl_demo_demodashboarddata.renforcez_les_comptes_les_connexions_et_le_journal'),
                'icon' => 'shield',
                'accent' => 'neutral',
                'phase' => $this->t('admin.ctrl_demo_demodashboarddata.phase_9_reglages_langues_cache_mails_taches'),
                'parentTitle' => $this->t('admin.ctrl_demo_demodashboarddata.module_reglages'),
                'parentUrl' => '/admin/settings',
                'description' => $this->t('admin.ctrl_demo_demodashboarddata.duree_de_session_double_facteur_et_verrouillages_attendent'),
                'planned' => [
                    $this->t('admin.ctrl_demo_demodashboarddata.politiques_de_mot_de_passe_et_double_facteur_par_role'),
                    $this->t('admin.ctrl_demo_demodashboarddata.journal_d_audit_complet_des_actions_d_administration'),
                ],
            ],
            'settings/emails' => [
                'title' => $this->t('admin.menu.settings.emails'),
                'subtitle' => $this->t('admin.ctrl_demo_demodashboarddata.expediteur_transport_smtp_et_journal_des_envois'),
                'icon' => 'inbox',
                'accent' => 'neutral',
                'phase' => $this->t('admin.ctrl_demo_demodashboarddata.phase_9_reglages_langues_cache_mails_taches'),
                'parentTitle' => $this->t('admin.ctrl_demo_demodashboarddata.module_reglages'),
                'parentUrl' => '/admin/settings',
                'description' => $this->t('admin.ctrl_demo_demodashboarddata.le_transport_le_test_d_envoi_et_le_journal_des_messages'),
                'planned' => [
                    $this->t('admin.ctrl_demo_demodashboarddata.test_d_envoi_reel_depuis_l_administration'),
                    $this->t('admin.ctrl_demo_demodashboarddata.journal_des_messages_avec_statut_et_erreur_smtp'),
                ],
            ],
        ];
    }
}
