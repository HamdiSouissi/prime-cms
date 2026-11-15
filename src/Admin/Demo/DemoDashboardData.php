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
                'url' => '/admin/pages',
            ],
            [
                'label' => 'Articles',
                'value' => '42',
                'icon' => 'newspaper',
                'accent' => 'violet',
                'trend' => '+12 %',
                'trendDirection' => 'up',
                'hint' => '5 programmés',
                'url' => '/admin/articles',
            ],
            [
                'label' => 'Médias',
                'value' => '264',
                'icon' => 'image',
                'accent' => 'sky',
                'trend' => '128 Mo',
                'trendDirection' => 'flat',
                'hint' => 'WebP & PNG',
                'url' => '/admin/media',
            ],
            [
                'label' => 'Utilisateurs',
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
                'status' => 'Publié',
                'statusVariant' => 'success',
                'accent' => 'teal',
            ],
            [
                'title' => 'Article « Guide complet du design system Prime »',
                'meta' => 'Enregistré en brouillon par Lucas Bernard · il y a 1 heure',
                'author' => 'Lucas Bernard',
                'initials' => 'LB',
                'status' => 'Brouillon',
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
                'title' => 'Créer une page',
                'description' => 'Nouveau contenu éditorial',
                'icon' => 'file-text',
                'accent' => 'teal',
                'url' => '/admin/pages',
            ],
            [
                'title' => 'Rédiger un article',
                'description' => 'Actualité ou dossier blog',
                'icon' => 'newspaper',
                'accent' => 'violet',
                'url' => '/admin/articles',
            ],
            [
                'title' => 'Ouvrir les médias',
                'description' => 'Images et documents',
                'icon' => 'image',
                'accent' => 'sky',
                'url' => '/admin/media',
            ],
            [
                'title' => 'Guide des composants',
                'description' => 'Styleguide du design system',
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
                'label' => 'Version de Prime CMS',
                'value' => Application::VERSION,
                'badge' => 'Phase 2',
                'variant' => 'brand',
            ],
            [
                'label' => 'Version de PHP',
                'value' => PHP_VERSION,
                'badge' => 'OK',
                'variant' => 'success',
            ],
            [
                'label' => 'Environnement',
                'value' => $app->environment(),
                'badge' => $app->isDebug() ? 'Debug actif' : 'Production',
                'variant' => $app->isDebug() ? 'amber' : 'neutral',
            ],
            [
                'label' => 'Base de données',
                'value' => 'Non connectée',
                'badge' => 'Phase 3',
                'variant' => 'sky',
            ],
            [
                'label' => 'Authentification',
                'value' => 'Aperçu local uniquement',
                'badge' => 'Phase 4',
                'variant' => 'violet',
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
                'phase' => 'Phase 5 — Pages & Articles',
                'description' => 'La gestion complète des pages (hiérarchie, statuts, révisions et aperçus) arrivera en Phase 5.',
                'planned' => [
                    'Liste filtrable, recherche et actions groupées',
                    'Statuts brouillon, planifié, publié et corbeille',
                    'Historique des révisions et restauration en un clic',
                ],
            ],
            'articles' => [
                'title' => 'Articles',
                'subtitle' => 'Rédigez, planifiez et classez vos publications éditoriales.',
                'icon' => 'newspaper',
                'accent' => 'teal',
                'phase' => 'Phase 5 — Pages & Articles',
                'description' => 'Le module Articles avec catégories, étiquettes et planification sera livré en Phase 5.',
                'planned' => [
                    'Taxonomies hiérarchiques et étiquettes',
                    'Planification automatique et flux RSS natif',
                    'Métadonnées SEO et aperçu social intégré',
                ],
            ],
            'media' => [
                'title' => 'Médias',
                'subtitle' => 'Centralisez et optimisez vos images, PDF et documents.',
                'icon' => 'image',
                'accent' => 'teal',
                'phase' => 'Phase 6 — Médiathèque',
                'description' => 'La médiathèque avec téléversement sécurisé, dossiers et variantes WebP arrivera en Phase 6.',
                'planned' => [
                    'Téléversement glisser-déposer avec validation MIME stricte',
                    'Génération automatique de miniatures WebP',
                    'Texte alternatif obligatoire pour l’accessibilité',
                ],
            ],
            'appearance' => [
                'title' => 'Apparence',
                'subtitle' => 'Personnalisez l’identité visuelle, les thèmes et la navigation.',
                'icon' => 'palette',
                'accent' => 'violet',
                'phase' => 'Phase 7 — Thèmes & Apparence',
                'description' => 'Le moteur de thèmes, la gestion des menus et des zones de widgets arriveront en Phase 7.',
                'planned' => [
                    'Activation et prévisualisation des thèmes',
                    'Éditeur visuel de menus multi-niveaux',
                    'Zones de widgets configurables sans code',
                ],
            ],
            'appearance/themes' => [
                'title' => 'Thèmes',
                'subtitle' => 'Installez et activez les thèmes de votre site public.',
                'icon' => 'palette',
                'accent' => 'violet',
                'phase' => 'Phase 7 — Thèmes & Apparence',
                'parentTitle' => 'Apparence',
                'parentUrl' => '/admin/appearance',
                'description' => 'La bibliothèque et l’activation de thèmes seront disponibles en Phase 7.',
                'planned' => [
                    'Catalogue de thèmes locaux et aperçu en direct',
                    'Personnalisation des couleurs et typographies',
                ],
            ],
            'appearance/menus' => [
                'title' => 'Menus',
                'subtitle' => 'Organisez les liens de navigation de votre site.',
                'icon' => 'layers',
                'accent' => 'violet',
                'phase' => 'Phase 7 — Thèmes & Apparence',
                'parentTitle' => 'Apparence',
                'parentUrl' => '/admin/appearance',
                'description' => 'La gestion des menus de navigation publics arrivera en Phase 7.',
                'planned' => [
                    'Emplacements de menus déclarés par le thème actif',
                    'Réorganisation par glisser-déposer accessible au clavier',
                ],
            ],
            'appearance/widgets' => [
                'title' => 'Widgets',
                'subtitle' => 'Composez les zones latérales et pieds de page.',
                'icon' => 'layers',
                'accent' => 'violet',
                'phase' => 'Phase 7 — Thèmes & Apparence',
                'parentTitle' => 'Apparence',
                'parentUrl' => '/admin/appearance',
                'description' => 'La gestion des widgets réutilisables arrivera en Phase 7.',
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
                'phase' => 'Phase 8 — Système de plugins',
                'description' => 'Le gestionnaire de plugins (manifeste, cycle de vie, isolation et hooks) arrivera en Phase 8.',
                'planned' => [
                    'Activation, désactivation et désinstallation propres',
                    'Déclaration de permissions et de routes isolées',
                    'Diagnostic des hooks enregistrés',
                ],
            ],
            'users' => [
                'title' => 'Utilisateurs',
                'subtitle' => 'Administrez les comptes, rôles et permissions de votre équipe.',
                'icon' => 'users',
                'accent' => 'sky',
                'phase' => 'Phase 4 — Authentification & RBAC',
                'description' => 'L’authentification Argon2id, les sessions sécurisées et les rôles RBAC arriveront en Phase 4.',
                'planned' => [
                    'Comptes utilisateurs avec hachage Argon2id natif',
                    'Rôles et matrice de permissions granulaires',
                    'Journal des connexions et sessions actives',
                ],
            ],
            'settings' => [
                'title' => 'Réglages',
                'subtitle' => 'Configurez le fonctionnement global de votre instance Prime CMS.',
                'icon' => 'settings',
                'accent' => 'neutral',
                'phase' => 'Phase 9 — Réglages & Administration',
                'description' => 'Les écrans de configuration persistés en base de données arriveront en Phase 9.',
                'planned' => [
                    'Identité du site, langue et fuseau horaire',
                    'Structure des permaliens et redirections',
                    'Politiques de sécurité et configuration e-mail',
                ],
            ],
            'settings/general' => [
                'title' => 'Réglages généraux',
                'subtitle' => 'Nom du site, description, langue et fuseau horaire.',
                'icon' => 'settings',
                'accent' => 'neutral',
                'phase' => 'Phase 9 — Réglages & Administration',
                'parentTitle' => 'Réglages',
                'parentUrl' => '/admin/settings',
                'description' => 'Les paramètres généraux du site seront disponibles en Phase 9.',
                'planned' => [
                    'Titre, slogan et identité de marque',
                    'Formats de date, d’heure et de langue',
                ],
            ],
            'settings/permalinks' => [
                'title' => 'Permaliens',
                'subtitle' => 'Personnalisez la structure des URL publiques.',
                'icon' => 'settings',
                'accent' => 'neutral',
                'phase' => 'Phase 9 — Réglages & Administration',
                'parentTitle' => 'Réglages',
                'parentUrl' => '/admin/settings',
                'description' => 'La personnalisation des permaliens sera livrée en Phase 9.',
                'planned' => [
                    'Modèles d’URL pour pages, articles et archives',
                    'Redirections automatiques 301 en cas de changement de slug',
                ],
            ],
            'settings/security' => [
                'title' => 'Sécurité',
                'subtitle' => 'Renforcez les accès, sessions et en-têtes HTTP.',
                'icon' => 'shield',
                'accent' => 'neutral',
                'phase' => 'Phase 9 — Réglages & Administration',
                'parentTitle' => 'Réglages',
                'parentUrl' => '/admin/settings',
                'description' => 'Le panneau avancé de sécurité arrivera en Phase 9.',
                'planned' => [
                    'Politique de mots de passe et limitation de débit',
                    'Journal d’audit des actions d’administration',
                ],
            ],
            'settings/emails' => [
                'title' => 'E-mails',
                'subtitle' => 'Configurez l’envoi des notifications transactionnelles.',
                'icon' => 'inbox',
                'accent' => 'neutral',
                'phase' => 'Phase 9 — Réglages & Administration',
                'parentTitle' => 'Réglages',
                'parentUrl' => '/admin/settings',
                'description' => 'La configuration SMTP et les modèles d’e-mails arriveront en Phase 9.',
                'planned' => [
                    'Transport SMTP authentifié avec envoi de test',
                    'Expéditeur par défaut et modèles personnalisables',
                ],
            ],
        ];
    }
}
