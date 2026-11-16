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
                'label' => 'Pages publiées',
                'value' => '18',
                'icon' => 'file-text',
                'accent' => 'teal',
                'trend' => '+3 ce mois',
                'trendDirection' => 'up',
                'hint' => '2 brouillons',
                'url' => '/admin/content/page',
            ],
            [
                'label' => 'Articles',
                'value' => '42',
                'icon' => 'newspaper',
                'accent' => 'violet',
                'trend' => '+12 %',
                'trendDirection' => 'up',
                'hint' => '5 programmés',
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
                'trend' => '100 % actifs',
                'trendDirection' => 'up',
                'hint' => '3 rôles',
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
                'title' => 'Page « À propos de notre équipe » mise à jour',
                'meta' => 'Modifiée par Claire Moreau · il y a 18 minutes',
                'author' => 'Claire Moreau',
                'initials' => 'CM',
                'status' => $this->t('admin.shared.publie'),
                'statusVariant' => 'success',
                'accent' => 'teal',
            ],
            [
                'title' => 'Article « Guide complet du design system Prime »',
                'meta' => 'Enregistré en brouillon par Lucas Bernard · il y a 1 heure',
                'author' => 'Lucas Bernard',
                'initials' => 'LB',
                'status' => $this->t('admin.shared.brouillon'),
                'statusVariant' => 'amber',
                'accent' => 'violet',
            ],
            [
                'title' => 'Lot de 8 visuels importé dans la médiathèque',
                'meta' => 'Optimisé automatiquement · il y a 3 heures',
                'author' => 'Nadia Benali',
                'initials' => 'NB',
                'status' => 'Traité',
                'statusVariant' => 'sky',
                'accent' => 'sky',
            ],
            [
                'title' => 'Vérification des en-têtes de sécurité HTTP',
                'meta' => 'CSP stricte et protection XSS vérifiées · hier à 17:40',
                'author' => 'Système',
                'initials' => 'SY',
                'status' => 'Conforme',
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
                'badge' => 'Phase 7',
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
                'badge' => $app->isDebug() ? 'Debug actif' : 'Production',
                'variant' => $app->isDebug() ? 'amber' : 'neutral',
            ],
            [
                'label' => 'Base de données',
                'value' => 'MySQL / MariaDB',
                'badge' => 'Active',
                'variant' => 'success',
            ],
            [
                'label' => 'Authentification',
                'value' => 'Sessions + RBAC + 2FA',
                'badge' => 'Active',
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
                'title' => 'Pages',
                'subtitle' => 'Gérez l’arborescence et les pages statiques de votre site.',
                'icon' => 'file-text',
                'accent' => 'teal',
                'phase' => 'Phase 6 — Contenus',
                'description' => 'La gestion complète des pages (hiérarchie, statuts, révisions et éditeur de blocs) arrivera en Phase 6.',
                'planned' => [
                    'Liste filtrable, recherche et actions groupées',
                    'Statuts brouillon, planifié, publié et corbeille',
                    'Historique des révisions et éditeur de blocs Prime Editor',
                ],
            ],
            'articles' => [
                'title' => 'Articles',
                'subtitle' => 'Rédigez, planifiez et classez vos publications éditoriales.',
                'icon' => 'newspaper',
                'accent' => 'teal',
                'phase' => 'Phase 6 — Contenus',
                'description' => 'Le module Articles avec catégories, étiquettes, révisions et planification sera livré en Phase 6.',
                'planned' => [
                    'Taxonomies hiérarchiques et étiquettes',
                    'Planification automatique et champs personnalisés',
                    'Éditeur de blocs Prime Editor intégré',
                ],
            ],
            'media' => [
                'title' => $this->t('admin.shared.medias'),
                'subtitle' => 'Centralisez et optimisez vos images, PDF et documents.',
                'icon' => 'image',
                'accent' => 'teal',
                'phase' => 'Phase 7 — Médiathèque',
                'description' => 'La médiathèque avec téléversement sécurisé, dossiers et variantes WebP arrivera en Phase 7.',
                'planned' => [
                    'Téléversement sécurisé avec validation MIME stricte',
                    'Génération automatique de miniatures et variantes WebP (GD)',
                    'Organisation en dossiers et sélecteur de média réutilisable',
                ],
            ],
            'appearance' => [
                'title' => 'Apparence',
                'subtitle' => 'Personnalisez l’identité visuelle, les thèmes et la navigation.',
                'icon' => 'palette',
                'accent' => 'violet',
                'phase' => 'Phase 8 — Thèmes et rendu du site',
                'description' => 'Le système de thèmes, la gestion des menus, des zones de widgets et le thème Aurora arriveront en Phase 8.',
                'planned' => [
                    'Activation, options et prévisualisation des thèmes',
                    'Éditeur visuel de menus multi-niveaux',
                    'Zones de widgets configurables sans code',
                ],
            ],
            'appearance/themes' => [
                'title' => 'Thèmes',
                'subtitle' => 'Installez et activez les thèmes de votre site public.',
                'icon' => 'palette',
                'accent' => 'violet',
                'phase' => 'Phase 8 — Thèmes et rendu du site',
                'parentTitle' => 'Apparence',
                'parentUrl' => '/admin/appearance',
                'description' => 'La bibliothèque de thèmes, la hiérarchie de templates et le thème Aurora seront disponibles en Phase 8.',
                'planned' => [
                    'Catalogue de thèmes locaux et thème par défaut Aurora',
                    'Options de personnalisation des couleurs et typographies',
                ],
            ],
            'appearance/menus' => [
                'title' => 'Menus',
                'subtitle' => 'Organisez les liens de navigation de votre site.',
                'icon' => 'layers',
                'accent' => 'violet',
                'phase' => 'Phase 8 — Thèmes et rendu du site',
                'parentTitle' => 'Apparence',
                'parentUrl' => '/admin/appearance',
                'description' => 'La gestion des menus de navigation publics arrivera en Phase 8.',
                'planned' => [
                    'Emplacements de menus déclarés par le thème actif',
                    'Réorganisation hiérarchique accessible au clavier',
                ],
            ],
            'appearance/widgets' => [
                'title' => 'Widgets',
                'subtitle' => 'Composez les zones latérales et pieds de page.',
                'icon' => 'layers',
                'accent' => 'violet',
                'phase' => 'Phase 8 — Thèmes et rendu du site',
                'parentTitle' => 'Apparence',
                'parentUrl' => '/admin/appearance',
                'description' => 'La gestion des zones de widgets réutilisables arrivera en Phase 8.',
                'planned' => [
                    'Zones dynamiques définies par le thème',
                    'Widgets natifs et extensibles par plugins',
                ],
            ],
            'plugins' => [
                'title' => 'Plugins',
                'subtitle' => 'Étendez Prime CMS avec des modules isolés et vérifiés.',
                'icon' => 'plug',
                'accent' => 'violet',
                'phase' => 'Phase 5 — Système de plugins',
                'description' => 'Le gestionnaire de plugins (manifeste plugin.json, cycle de vie, dépendances et isolation) arrivera en Phase 5.',
                'planned' => [
                    'Découverte, activation, désactivation et désinstallation propres',
                    'Migrations dédiées et installation par archive ZIP',
                    'Isolation des erreurs et plugin d’exemple officiel',
                ],
            ],
            'settings' => [
                'title' => 'Réglages',
                'subtitle' => 'Configurez le fonctionnement global de votre instance Prime CMS.',
                'icon' => 'settings',
                'accent' => 'neutral',
                'phase' => 'Phase 9 — Réglages, langues, cache, mails, tâches',
                'description' => 'Les écrans de configuration persistés, l’i18n, le cache, le client SMTP et les tâches planifiées arriveront en Phase 9.',
                'planned' => [
                    'Identité du site, langues (FR, EN, IT) et fuseau horaire',
                    'Cache de pages et d’objets, client SMTP et tâches planifiées',
                    'Journal d’audit complet des actions d’administration',
                ],
            ],
        ];
    }
}
