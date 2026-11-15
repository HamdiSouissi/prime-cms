<?php

declare(strict_types=1);

/**
 * Interactive Design System styleguide (`GET /admin/styleguide`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var list<string> $iconNames
 */
$this->layout('admin::layouts/app');
?>
<?php $this->start('content'); ?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => 'Design System Prime CMS',
    'subtitle' => 'Bibliothèque vivante de tous les composants, jetons visuels et interactions accessibles.',
    'breadcrumbs' => [
        ['label' => 'Tableau de bord', 'url' => '/admin'],
        ['label' => 'Design System', 'url' => null],
    ],
    'actions' => $this->insert('admin::components/button', [
        'label' => 'Tester un toast',
        'variant' => 'secondary',
        'icon' => 'bell',
        'attrs' => [
            'data-prime-toast-trigger' => '1',
            'data-prime-toast-type' => 'success',
            'data-prime-toast-title' => 'Composant prêt',
            'data-prime-toast-message' => 'Le système de notifications fonctionne sans script inline.',
        ],
    ]) . $this->insert('admin::components/button', [
        'label' => 'Ouvrir la palette',
        'variant' => 'primary',
        'icon' => 'command',
        'attrs' => ['data-prime-command-palette-open' => '1'],
    ]),
])) ?>

<!-- Anchor navigation -->
<nav class="pc-sg-nav" aria-label="Sections du Design System">
    <a class="pc-sg-nav__link" href="#sg-tokens">Couleurs &amp; typo</a>
    <a class="pc-sg-nav__link" href="#sg-buttons">Boutons</a>
    <a class="pc-sg-nav__link" href="#sg-forms">Formulaires</a>
    <a class="pc-sg-nav__link" href="#sg-badges">Badges</a>
    <a class="pc-sg-nav__link" href="#sg-cards">Cartes &amp; KPI</a>
    <a class="pc-sg-nav__link" href="#sg-table">Tableau</a>
    <a class="pc-sg-nav__link" href="#sg-tabs">Onglets</a>
    <a class="pc-sg-nav__link" href="#sg-overlays">Modale &amp; tiroir</a>
    <a class="pc-sg-nav__link" href="#sg-feedback">Toasts &amp; alertes</a>
    <a class="pc-sg-nav__link" href="#sg-states">États &amp; utilitaires</a>
    <a class="pc-sg-nav__link" href="#sg-icons">Icônes (<?= $this->e((string) count($iconNames)) ?>)</a>
</nav>

<div class="pc-stack pc-stack--lg">
    <!-- 1. Colors & Typography -->
    <?php ob_start(); ?>
    <div class="pc-stack pc-stack--lg">
        <div class="pc-swatch-grid">
            <?php
            $swatches = [
                ['Brand 50', '--brand-50', 'brand-50'],
                ['Brand 500 (Logo)', '--brand-500', 'brand-500'],
                ['Brand 600 (CTA AA)', '--brand-600', 'brand-600'],
                ['Brand 700 (Hover)', '--brand-700', 'brand-700'],
                ['Ink 900 (Sidebar)', '--ink-900', 'ink-900'],
                ['Ink 700 (Texte)', '--ink-700', 'ink-700'],
                ['Ink 500 (Secondaire)', '--ink-500', 'ink-500'],
                ['Canvas (Fond)', '--canvas', 'canvas'],
                ['Violet', '--violet', 'violet'],
                ['Teal', '--teal', 'teal'],
                ['Sky', '--sky', 'sky'],
                ['Amber', '--amber', 'amber'],
                ['Success', '--success', 'success'],
                ['Danger', '--danger', 'danger'],
            ];
            ?>
            <?php foreach ($swatches as [$name, $token, $mod]): ?>
                <div class="pc-swatch">
                    <div class="pc-swatch__color pc-swatch__color--<?= $this->e($mod) ?>"></div>
                    <div class="pc-swatch__meta">
                        <div class="pc-swatch__name"><?= $this->e($name) ?></div>
                        <div class="pc-swatch__token"><?= $this->e($token) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <hr class="pc-divider">

        <div class="pc-stack pc-stack--sm">
            <h1>Titre H1 — Plus Jakarta Sans 24px / 700</h1>
            <h2>Titre H2 — Plus Jakarta Sans 20px / 600</h2>
            <h3>Titre H3 — Plus Jakarta Sans 16px / 600</h3>
            <p>Texte courant Inter 14px — conçu pour la lisibilité prolongée dans les écrans d’administration, avec un interligne équilibré et un contraste WCAG AA vérifié.</p>
            <p class="pc-text-muted pc-text-sm">Texte secondaire 13px — métadonnées, sous-titres et descriptions d’aide.</p>
            <p class="pc-mono">JetBrains Mono — /admin/styleguide · php bin/prime assets:build</p>
        </div>
    </div>
    <?php $tokensHtml = (string) ob_get_clean(); ?>
    <?= $this->raw($this->insert('admin::components/card', [
        'id' => 'sg-tokens',
        'class' => 'pc-sg-section',
        'title' => 'Couleurs et typographie',
        'subtitle' => 'Palette officielle corail + encre chaude + accents vibrants (Section 8 de CONTEXT.md)',
        'body' => $tokensHtml,
    ])) ?>

    <!-- 2. Buttons -->
    <?php ob_start(); ?>
    <div class="pc-stack">
        <div class="pc-row">
            <?= $this->raw($this->insert('admin::components/button', ['label' => 'Enregistrer', 'variant' => 'primary', 'icon' => 'check'])) ?>
            <?= $this->raw($this->insert('admin::components/button', ['label' => 'Prévisualiser', 'variant' => 'secondary', 'icon' => 'eye'])) ?>
            <?= $this->raw($this->insert('admin::components/button', ['label' => 'Annuler', 'variant' => 'ghost'])) ?>
            <?= $this->raw($this->insert('admin::components/button', ['label' => 'Supprimer', 'variant' => 'danger', 'icon' => 'trash'])) ?>
            <?= $this->raw($this->insert('admin::components/button', ['label' => 'Modifier l’élément', 'variant' => 'secondary', 'icon' => 'pencil', 'iconOnly' => true])) ?>
        </div>
        <div class="pc-row">
            <?= $this->raw($this->insert('admin::components/button', ['label' => 'Petit (S)', 'variant' => 'secondary', 'size' => 'sm'])) ?>
            <?= $this->raw($this->insert('admin::components/button', ['label' => 'Moyen (M)', 'variant' => 'secondary', 'size' => 'md'])) ?>
            <?= $this->raw($this->insert('admin::components/button', ['label' => 'Grand (L)', 'variant' => 'secondary', 'size' => 'lg'])) ?>
            <?= $this->raw($this->insert('admin::components/button', ['label' => 'Désactivé', 'variant' => 'primary', 'disabled' => true])) ?>
            <?= $this->raw($this->insert('admin::components/button', ['label' => 'Publication en cours…', 'variant' => 'primary', 'loading' => true])) ?>
        </div>
    </div>
    <?php $buttonsHtml = (string) ob_get_clean(); ?>
    <?= $this->raw($this->insert('admin::components/card', [
        'id' => 'sg-buttons',
        'class' => 'pc-sg-section',
        'title' => 'Boutons',
        'subtitle' => 'Primaire (--brand-600 AA), secondaire, fantôme, danger, icône seule avec infobulle, tailles S/M/L et états',
        'body' => $buttonsHtml,
    ])) ?>

    <!-- 3. Forms -->
    <?php ob_start(); ?>
    <form class="pc-stack" action="#sg-forms" method="get">
        <div class="pc-grid pc-grid--2">
            <?= $this->raw($this->insert('admin::components/field', [
                'id' => 'sg-title',
                'name' => 'title',
                'label' => 'Titre de la page',
                'placeholder' => 'Ex. À propos de notre studio',
                'help' => 'Affiché dans le navigateur et les résultats de recherche.',
                'required' => true,
            ])) ?>
            <?= $this->raw($this->insert('admin::components/field', [
                'id' => 'sg-slug',
                'name' => 'slug',
                'label' => 'Permalien',
                'value' => 'a-propos!',
                'error' => 'Le permalien ne doit contenir que des lettres minuscules, chiffres et tirets.',
                'required' => true,
            ])) ?>
            <?= $this->raw($this->insert('admin::components/field', [
                'id' => 'sg-password',
                'name' => 'password',
                'type' => 'password',
                'label' => 'Mot de passe',
                'value' => 'PrimeCMS2026!',
                'help' => 'Cliquez sur l’icône œil pour afficher ou masquer.',
            ])) ?>
            <?= $this->raw($this->insert('admin::components/field', [
                'id' => 'sg-status',
                'name' => 'status',
                'type' => 'select',
                'label' => 'Statut de publication',
                'value' => 'published',
                'options' => [
                    'draft' => 'Brouillon',
                    'scheduled' => 'Planifié',
                    'published' => 'Publié',
                ],
            ])) ?>
        </div>

        <?= $this->raw($this->insert('admin::components/field', [
            'id' => 'sg-excerpt',
            'name' => 'excerpt',
            'type' => 'textarea',
            'label' => 'Extrait',
            'placeholder' => 'Résumé concis affiché dans les listes d’articles…',
        ])) ?>

        <div class="pc-grid pc-grid--3">
            <?= $this->raw($this->insert('admin::components/choice', [
                'id' => 'sg-cb-1',
                'name' => 'comments',
                'type' => 'checkbox',
                'label' => 'Autoriser les commentaires',
                'description' => 'Modération manuelle activée',
                'checked' => true,
            ])) ?>
            <?= $this->raw($this->insert('admin::components/choice', [
                'id' => 'sg-radio-1',
                'name' => 'visibility',
                'type' => 'radio',
                'label' => 'Visibilité publique',
                'description' => 'Accessible à tous les visiteurs',
                'checked' => true,
            ])) ?>
            <?= $this->raw($this->insert('admin::components/choice', [
                'id' => 'sg-switch-1',
                'name' => 'indexable',
                'type' => 'switch',
                'label' => 'Indexation moteurs',
                'description' => 'Inclure dans le plan du site XML',
                'checked' => true,
            ])) ?>
        </div>
    </form>
    <?php $formsHtml = (string) ob_get_clean(); ?>
    <?= $this->raw($this->insert('admin::components/card', [
        'id' => 'sg-forms',
        'class' => 'pc-sg-section',
        'title' => 'Champs et contrôles de formulaire',
        'subtitle' => 'Libellés explicites, aides, validation accessible, bascule mot de passe, cases, radios et interrupteur',
        'body' => $formsHtml,
    ])) ?>

    <!-- 4. Badges -->
    <?php ob_start(); ?>
    <div class="pc-row">
        <?= $this->raw($this->insert('admin::components/badge', ['label' => 'Neutre', 'variant' => 'neutral'])) ?>
        <?= $this->raw($this->insert('admin::components/badge', ['label' => 'Corail / Brand', 'variant' => 'brand', 'dot' => true])) ?>
        <?= $this->raw($this->insert('admin::components/badge', ['label' => 'Violet / Plugins', 'variant' => 'violet', 'dot' => true])) ?>
        <?= $this->raw($this->insert('admin::components/badge', ['label' => 'Teal / Contenu', 'variant' => 'teal', 'dot' => true])) ?>
        <?= $this->raw($this->insert('admin::components/badge', ['label' => 'Sky / Info', 'variant' => 'sky', 'dot' => true])) ?>
        <?= $this->raw($this->insert('admin::components/badge', ['label' => 'Amber / Brouillon', 'variant' => 'amber', 'dot' => true])) ?>
        <?= $this->raw($this->insert('admin::components/badge', ['label' => 'Publié', 'variant' => 'success', 'dot' => true])) ?>
        <?= $this->raw($this->insert('admin::components/badge', ['label' => 'Erreur critique', 'variant' => 'danger', 'dot' => true])) ?>
    </div>
    <?php $badgesHtml = (string) ob_get_clean(); ?>
    <?= $this->raw($this->insert('admin::components/card', [
        'id' => 'sg-badges',
        'class' => 'pc-sg-section',
        'title' => 'Badges et statuts',
        'subtitle' => 'Tous les accents vibrants avec contraste de texte AA garanti',
        'body' => $badgesHtml,
    ])) ?>

    <!-- 5. Stat tiles -->
    <section id="sg-cards" class="pc-sg-section pc-stack">
        <h2>Cartes et tuiles de statistiques</h2>
        <div class="pc-grid pc-grid--stats">
            <?= $this->raw($this->insert('admin::components/stat-tile', [
                'label' => 'Pages publiées',
                'value' => '18',
                'icon' => 'file-text',
                'accent' => 'teal',
                'trend' => '+14 %',
                'trendDirection' => 'up',
                'hint' => 'Ce mois-ci',
            ])) ?>
            <?= $this->raw($this->insert('admin::components/stat-tile', [
                'label' => 'Plugins actifs',
                'value' => '7',
                'icon' => 'plug',
                'accent' => 'violet',
                'trend' => 'À jour',
                'trendDirection' => 'flat',
                'hint' => '0 alerte',
            ])) ?>
            <?= $this->raw($this->insert('admin::components/stat-tile', [
                'label' => 'Médias',
                'value' => '264',
                'icon' => 'image',
                'accent' => 'sky',
                'trend' => '+28',
                'trendDirection' => 'up',
                'hint' => '128 Mo',
            ])) ?>
            <?= $this->raw($this->insert('admin::components/stat-tile', [
                'label' => 'Taux d’erreur',
                'value' => '0,2 %',
                'icon' => 'activity',
                'accent' => 'amber',
                'trend' => '-0,4 %',
                'trendDirection' => 'down',
                'hint' => '30 derniers jours',
            ])) ?>
        </div>
    </section>

    <!-- 6. Table with sticky header, bulk actions, row dropdown, pagination -->
    <section id="sg-table" class="pc-sg-section pc-stack">
        <h2>Tableau de données</h2>
        <div class="pc-table-wrap" data-prime-table-bulk>
            <div class="pc-table-bulk" data-prime-table-bulk-bar hidden>
                <span class="pc-table-bulk__count" data-prime-table-bulk-count>0 élément sélectionné</span>
                <div class="pc-row pc-row--sm">
                    <?= $this->raw($this->insert('admin::components/button', [
                        'label' => 'Publier la sélection',
                        'variant' => 'secondary',
                        'size' => 'sm',
                        'attrs' => [
                            'data-prime-toast-trigger' => '1',
                            'data-prime-toast-type' => 'success',
                            'data-prime-toast-message' => 'Action groupée simulée avec succès.',
                        ],
                    ])) ?>
                    <?= $this->raw($this->insert('admin::components/button', [
                        'label' => 'Désélectionner',
                        'variant' => 'ghost',
                        'size' => 'sm',
                        'attrs' => ['data-prime-table-bulk-clear' => '1'],
                    ])) ?>
                </div>
            </div>

            <div class="pc-table-scroll">
                <table class="pc-table">
                    <thead>
                        <tr>
                            <th class="pc-table__check">
                                <input type="checkbox" data-prime-table-select-all aria-label="Tout sélectionner">
                            </th>
                            <th>
                                <button type="button" class="pc-table__sort">
                                    <span>Titre</span>
                                    <?= $this->raw($this->icon('arrow-up-down', 14)) ?>
                                </button>
                            </th>
                            <th>Auteur</th>
                            <th>Statut</th>
                            <th>Modification</th>
                            <th class="pc-table__actions"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $rows = [
                            ['id' => '1', 'title' => 'Accueil principal', 'slug' => '/', 'author' => 'Claire Moreau', 'status' => 'Publié', 'variant' => 'success', 'date' => '15 nov. 2026'],
                            ['id' => '2', 'title' => 'Guide de démarrage rapide', 'slug' => '/guide', 'author' => 'Lucas Bernard', 'status' => 'Brouillon', 'variant' => 'amber', 'date' => '14 nov. 2026'],
                            ['id' => '3', 'title' => 'Politique de confidentialité', 'slug' => '/confidentialite', 'author' => 'Nadia Benali', 'status' => 'Planifié', 'variant' => 'sky', 'date' => '12 nov. 2026'],
                        ];
                        ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td class="pc-table__check">
                                    <input
                                        type="checkbox"
                                        value="<?= $this->e($row['id']) ?>"
                                        data-prime-table-select-row
                                        aria-label="Sélectionner <?= $this->e($row['title']) ?>"
                                    >
                                </td>
                                <td>
                                    <div class="pc-text-strong"><?= $this->e($row['title']) ?></div>
                                    <div class="pc-text-xs pc-mono pc-text-muted"><?= $this->e($row['slug']) ?></div>
                                </td>
                                <td><?= $this->e($row['author']) ?></td>
                                <td>
                                    <?= $this->raw($this->insert('admin::components/badge', [
                                        'label' => $row['status'],
                                        'variant' => $row['variant'],
                                        'dot' => true,
                                    ])) ?>
                                </td>
                                <td class="pc-text-muted pc-text-sm"><?= $this->e($row['date']) ?></td>
                                <td class="pc-table__actions">
                                    <?= $this->raw($this->insert('admin::components/dropdown', [
                                        'id' => 'sg-row-menu-' . $row['id'],
                                        'menuLabel' => 'Actions pour ' . $row['title'],
                                        'triggerHtml' => '<button type="button" class="pc-btn pc-btn--ghost pc-btn--icon pc-btn--sm" data-prime-dropdown-trigger aria-label="Actions pour ' . $this->e($row['title']) . '" aria-haspopup="menu" aria-expanded="false">' . $this->icon('more-horizontal', 16) . '</button>',
                                        'menuHtml' => '<button type="button" class="pc-dropdown__item" role="menuitem" data-prime-toast-trigger data-prime-toast-type="info" data-prime-toast-message="Édition de « ' . $this->e($row['title']) . ' » (Phase 6).">' . $this->icon('pencil', 15) . '<span>Modifier</span></button>'
                                            . '<button type="button" class="pc-dropdown__item" role="menuitem" data-prime-toast-trigger data-prime-toast-type="info" data-prime-toast-message="Élément dupliqué (démo).">' . $this->icon('copy', 15) . '<span>Dupliquer</span></button>'
                                            . '<div class="pc-dropdown__separator" role="separator"></div>'
                                            . '<button type="button" class="pc-dropdown__item pc-dropdown__item--danger" role="menuitem" data-prime-toast-trigger data-prime-toast-type="danger" data-prime-toast-message="Suppression simulée.">' . $this->icon('trash', 15) . '<span>Supprimer</span></button>',
                                    ])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="pc-table-footer">
                <span>Affichage de 1 à 3 sur 18 résultats</span>
                <nav class="pc-pagination" aria-label="Pagination du tableau">
                    <button type="button" class="pc-pagination__btn" disabled aria-label="Page précédente">
                        <?= $this->raw($this->icon('chevron-left', 15)) ?>
                    </button>
                    <button type="button" class="pc-pagination__btn" aria-current="page">1</button>
                    <button type="button" class="pc-pagination__btn">2</button>
                    <button type="button" class="pc-pagination__btn">3</button>
                    <button type="button" class="pc-pagination__btn" aria-label="Page suivante">
                        <?= $this->raw($this->icon('chevron-right', 15)) ?>
                    </button>
                </nav>
            </div>
        </div>
    </section>

    <!-- 7. Tabs -->
    <?php ob_start(); ?>
    <?= $this->raw($this->insert('admin::components/tabs', [
        'id' => 'sg-demo-tabs',
        'label' => 'Onglets de démonstration',
        'tabs' => [
            [
                'id' => 'general',
                'label' => 'Général',
                'badge' => 'Actif',
                'content' => '<p>Les onglets prennent en charge les flèches <kbd class="pc-kbd">←</kbd> <kbd class="pc-kbd">→</kbd>, <kbd class="pc-kbd">Début</kbd> et <kbd class="pc-kbd">Fin</kbd> conformément au motif WAI-ARIA Tabs.</p>',
            ],
            [
                'id' => 'seo',
                'label' => 'Référencement',
                'content' => '<p>Métadonnées Open Graph, balise canonique et prévisualisation des résultats de recherche.</p>',
            ],
            [
                'id' => 'revisions',
                'label' => 'Révisions',
                'badge' => '4',
                'content' => '<p>Historique chronologique des modifications et restauration sécurisée.</p>',
            ],
        ],
    ])) ?>
    <?php $tabsHtml = (string) ob_get_clean(); ?>
    <?= $this->raw($this->insert('admin::components/card', [
        'id' => 'sg-tabs',
        'class' => 'pc-sg-section',
        'title' => 'Onglets accessibles',
        'body' => $tabsHtml,
    ])) ?>

    <!-- 8. Overlays: Modal, Drawer, Dropdown -->
    <?php ob_start(); ?>
    <div class="pc-row">
        <?= $this->raw($this->insert('admin::components/button', [
            'label' => 'Ouvrir la modale',
            'variant' => 'primary',
            'attrs' => ['data-prime-modal-open' => 'sg-demo-modal'],
        ])) ?>
        <?= $this->raw($this->insert('admin::components/button', [
            'label' => 'Ouvrir le tiroir latéral',
            'variant' => 'secondary',
            'attrs' => ['data-prime-drawer-open' => 'sg-demo-drawer'],
        ])) ?>
        <?= $this->raw($this->insert('admin::components/dropdown', [
            'id' => 'sg-demo-dropdown',
            'align' => 'left',
            'triggerHtml' => '<button type="button" class="pc-btn pc-btn--secondary" data-prime-dropdown-trigger aria-haspopup="menu" aria-expanded="false"><span>Menu déroulant</span>' . $this->icon('chevron-down', 16) . '</button>',
            'menuHtml' => '<button type="button" class="pc-dropdown__item" role="menuitem" data-prime-toast-trigger data-prime-toast-type="info" data-prime-toast-message="Option Exporter cliquée.">' . $this->icon('download', 15) . '<span>Exporter en JSON</span></button>'
                . '<button type="button" class="pc-dropdown__item" role="menuitem" data-prime-toast-trigger data-prime-toast-type="info" data-prime-toast-message="Option Filtrer cliquée.">' . $this->icon('filter', 15) . '<span>Filtrer les résultats</span></button>',
        ])) ?>
    </div>
    <?php $overlaysHtml = (string) ob_get_clean(); ?>
    <?= $this->raw($this->insert('admin::components/card', [
        'id' => 'sg-overlays',
        'class' => 'pc-sg-section',
        'title' => 'Modale (<dialog>), tiroir latéral et menu déroulant',
        'subtitle' => 'Fermeture via Échap, clic extérieur et restauration automatique du focus',
        'body' => $overlaysHtml,
    ])) ?>

    <!-- 9. Toasts & Alerts -->
    <?php ob_start(); ?>
    <div class="pc-stack">
        <div class="pc-row">
            <?= $this->raw($this->insert('admin::components/button', [
                'label' => 'Toast succès',
                'variant' => 'secondary',
                'size' => 'sm',
                'attrs' => [
                    'data-prime-toast-trigger' => '1',
                    'data-prime-toast-type' => 'success',
                    'data-prime-toast-title' => 'Enregistré',
                    'data-prime-toast-message' => 'Vos modifications ont bien été prises en compte.',
                ],
            ])) ?>
            <?= $this->raw($this->insert('admin::components/button', [
                'label' => 'Toast info',
                'variant' => 'secondary',
                'size' => 'sm',
                'attrs' => [
                    'data-prime-toast-trigger' => '1',
                    'data-prime-toast-type' => 'info',
                    'data-prime-toast-title' => 'Information',
                    'data-prime-toast-message' => 'Une synchronisation planifiée démarre dans 5 minutes.',
                ],
            ])) ?>
            <?= $this->raw($this->insert('admin::components/button', [
                'label' => 'Toast avertissement',
                'variant' => 'secondary',
                'size' => 'sm',
                'attrs' => [
                    'data-prime-toast-trigger' => '1',
                    'data-prime-toast-type' => 'warning',
                    'data-prime-toast-title' => 'Attention',
                    'data-prime-toast-message' => 'Certains médias dépassent la taille recommandée.',
                ],
            ])) ?>
            <?= $this->raw($this->insert('admin::components/button', [
                'label' => 'Toast erreur',
                'variant' => 'secondary',
                'size' => 'sm',
                'attrs' => [
                    'data-prime-toast-trigger' => '1',
                    'data-prime-toast-type' => 'danger',
                    'data-prime-toast-title' => 'Erreur',
                    'data-prime-toast-message' => 'Impossible de joindre le service distant.',
                ],
            ])) ?>
        </div>

        <?= $this->raw($this->insert('admin::components/alert', ['variant' => 'info', 'title' => 'Information', 'message' => 'Message informatif neutre pour guider l’utilisateur.'])) ?>
        <?= $this->raw($this->insert('admin::components/alert', ['variant' => 'success', 'title' => 'Succès', 'message' => 'Les feuilles de style ont été compilées avec succès.'])) ?>
        <?= $this->raw($this->insert('admin::components/alert', ['variant' => 'warning', 'title' => 'Avertissement', 'message' => 'Vous visualisez un environnement de prévisualisation locale.'])) ?>
        <?= $this->raw($this->insert('admin::components/alert', ['variant' => 'danger', 'title' => 'Action irréversible', 'message' => 'La suppression définitive retirera cette ressource de la corbeille.'])) ?>
    </div>
    <?php $feedbackHtml = (string) ob_get_clean(); ?>
    <?= $this->raw($this->insert('admin::components/card', [
        'id' => 'sg-feedback',
        'class' => 'pc-sg-section',
        'title' => 'Toasts et alertes inline',
        'body' => $feedbackHtml,
    ])) ?>

    <!-- 10. Empty state, Skeletons, Avatars, Breadcrumb, Progress, Kbd, Tooltips -->
    <?php ob_start(); ?>
    <div class="pc-stack pc-stack--lg">
        <div class="pc-grid pc-grid--2">
            <div class="pc-stack">
                <h3>Avatars (S / M / L)</h3>
                <div class="pc-row">
                    <?= $this->raw($this->insert('admin::components/avatar', ['initials' => 'CM', 'size' => 'sm', 'accent' => 'brand'])) ?>
                    <?= $this->raw($this->insert('admin::components/avatar', ['initials' => 'LB', 'size' => 'md', 'accent' => 'violet'])) ?>
                    <?= $this->raw($this->insert('admin::components/avatar', ['initials' => 'NB', 'size' => 'lg', 'accent' => 'teal'])) ?>
                    <?= $this->raw($this->insert('admin::components/avatar', ['initials' => 'HS', 'size' => 'lg', 'accent' => 'sky'])) ?>
                </div>

                <h3>Fil d’Ariane</h3>
                <?= $this->raw($this->insert('admin::components/breadcrumb', [
                    'items' => [
                        ['label' => 'Tableau de bord', 'url' => '/admin'],
                        ['label' => 'Apparence', 'url' => '/admin/appearance'],
                        ['label' => 'Thèmes', 'url' => null],
                    ],
                ])) ?>

                <h3>Raccourcis &amp; infobulles</h3>
                <div class="pc-row">
                    <?= $this->raw($this->insert('admin::components/kbd', ['keys' => 'Ctrl', 'platformMod' => true])) ?>
                    <?= $this->raw($this->insert('admin::components/kbd', ['keys' => 'K'])) ?>
                    <?= $this->raw($this->insert('admin::components/kbd', ['keys' => '/'])) ?>
                    <?= $this->raw($this->insert('admin::components/button', [
                        'label' => 'Survolez ou focalisez-moi',
                        'variant' => 'secondary',
                        'size' => 'sm',
                        'tooltip' => 'Infobulle accessible au clavier et au survol',
                    ])) ?>
                </div>
            </div>

            <div class="pc-stack">
                <h3>Barres de progression</h3>
                <?= $this->raw($this->insert('admin::components/progress', ['label' => 'Optimisation des médias', 'value' => 72, 'accent' => 'teal'])) ?>
                <?= $this->raw($this->insert('admin::components/progress', ['label' => 'Couverture du design system', 'value' => 100, 'accent' => 'success'])) ?>
                <?= $this->raw($this->insert('admin::components/progress', ['label' => 'Quota de stockage', 'value' => 86, 'accent' => 'amber'])) ?>

                <h3>Skeletons de chargement</h3>
                <div class="pc-row">
                    <?= $this->raw($this->insert('admin::components/skeleton', ['variant' => 'circle'])) ?>
                    <div class="pc-stack pc-stack--sm" style="flex: 1;">
                        <?= $this->raw($this->insert('admin::components/skeleton', ['variant' => 'title'])) ?>
                        <?= $this->raw($this->insert('admin::components/skeleton', ['variant' => 'line'])) ?>
                    </div>
                </div>
            </div>
        </div>

        <hr class="pc-divider">

        <?= $this->raw($this->insert('admin::components/empty-state', [
            'icon' => 'inbox',
            'accent' => 'violet',
            'title' => 'Aucun élément trouvé',
            'description' => 'Exemple de bloc d’état vide sobre, clair et actionnable sans illustration superflue.',
            'actionLabel' => 'Créer un premier élément',
            'actionUrl' => '/admin/pages',
        ])) ?>
    </div>
    <?php $statesHtml = (string) ob_get_clean(); ?>
    <?= $this->raw($this->insert('admin::components/card', [
        'id' => 'sg-states',
        'class' => 'pc-sg-section',
        'title' => 'États vides, skeletons, avatars, progression et utilitaires',
        'body' => $statesHtml,
    ])) ?>

    <!-- 11. Lucide SVG Sprite Showcase -->
    <?php ob_start(); ?>
    <div class="pc-icon-grid">
        <?php foreach ($iconNames as $iconName): ?>
            <div class="pc-icon-cell">
                <?= $this->raw($this->icon($iconName, 22)) ?>
                <span class="pc-icon-cell__name"><?= $this->e($iconName) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php $iconsHtml = (string) ob_get_clean(); ?>
    <?= $this->raw($this->insert('admin::components/card', [
        'id' => 'sg-icons',
        'class' => 'pc-sg-section',
        'title' => 'Sprite d’icônes Lucide (' . count($iconNames) . ' icônes)',
        'subtitle' => 'Trait 1,5 px · currentColor · licence ISC locale',
        'body' => $iconsHtml,
    ])) ?>
</div>

<!-- Demo Modal -->
<?= $this->raw($this->insert('admin::components/modal', [
    'id' => 'sg-demo-modal',
    'title' => 'Confirmer la publication',
    'body' => '<p>Cette modale utilise l’élément natif <code>&lt;dialog&gt;</code> avec fermeture par la touche <strong>Échap</strong>, clic sur l’arrière-plan et restauration automatique du focus.</p>',
    'footer' => $this->insert('admin::components/button', [
        'label' => 'Annuler',
        'variant' => 'ghost',
        'attrs' => ['data-prime-modal-close' => '1'],
    ]) . $this->insert('admin::components/button', [
        'label' => 'Confirmer',
        'variant' => 'primary',
        'attrs' => [
            'data-prime-modal-close' => '1',
            'data-prime-toast-trigger' => '1',
            'data-prime-toast-type' => 'success',
            'data-prime-toast-message' => 'Action confirmée depuis la modale.',
        ],
    ]),
])) ?>

<!-- Demo Drawer -->
<?= $this->raw($this->insert('admin::components/drawer', [
    'id' => 'sg-demo-drawer',
    'title' => 'Propriétés rapides',
    'body' => '<div class="pc-stack">'
        . $this->insert('admin::components/field', [
            'id' => 'sg-drawer-title',
            'name' => 'drawer_title',
            'label' => 'Nom affiché',
            'value' => 'Page d’accueil',
        ])
        . $this->insert('admin::components/choice', [
            'id' => 'sg-drawer-switch',
            'name' => 'drawer_featured',
            'type' => 'switch',
            'label' => 'Mettre en avant',
            'checked' => true,
        ])
        . '</div>',
    'footer' => $this->insert('admin::components/button', [
        'label' => 'Fermer',
        'variant' => 'secondary',
        'attrs' => ['data-prime-drawer-close' => '1'],
    ]) . $this->insert('admin::components/button', [
        'label' => 'Enregistrer',
        'variant' => 'primary',
        'attrs' => [
            'data-prime-drawer-close' => '1',
            'data-prime-toast-trigger' => '1',
            'data-prime-toast-type' => 'success',
            'data-prime-toast-message' => 'Modifications enregistrées depuis le tiroir.',
        ],
    ]),
])) ?>

<?php $this->stop(); ?>
