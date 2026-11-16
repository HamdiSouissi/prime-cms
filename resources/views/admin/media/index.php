<?php

declare(strict_types=1);

/**
 * Admin Media Library (`/admin/media`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var bool $canUpload
 * @var bool $canDeleteAny
 * @var array{bytes: int, megabytes: float, phpLimited: bool, phpLimitBytes: ?int, configuredBytes: int} $limit
 * @var list<string> $allowedExtensions
 * @var list<string> $months
 * @var array{all: int, unclassified: int} $counts
 * @var list<array<string, mixed>> $flatFolders
 * @var list<\Prime\Media\Media> $initialItems
 * @var int $initialTotal
 * @var bool $initialHasMore
 * @var array<string, mixed> $initialFilters
 * @var string $bootstrapJson
 */
$this->layout('admin::layouts/app');
$acceptAttr = implode(',', array_map(static fn (string $ext): string => '.' . $ext, $allowedExtensions));
?>

<div class="pc-media-page" data-prime-media-library>
    <script type="application/json" data-prime-media-bootstrap><?= $this->raw($bootstrapJson) ?></script>

    <?php if ($canUpload): ?>
        <div class="pc-media-drop-overlay" data-prime-media-drop-overlay hidden aria-hidden="true">
            <div class="pc-media-drop-overlay__card">
                <?= $this->raw($this->icon('upload', 36)) ?>
                <p class="pc-media-drop-overlay__title">Déposez vos fichiers pour les téléverser</p>
                <p class="pc-text-sm pc-text-muted">Jusqu’à <?= $this->e(number_format($limit['megabytes'], 0, ',', ' ')) ?> Mo par fichier · Téléversement parallèle</p>
            </div>
        </div>
    <?php endif; ?>

    <header class="pc-page-header">
        <div class="pc-page-header__left">
            <h1 class="pc-page-header__title">Médiathèque</h1>
            <p class="pc-page-header__subtitle">
                <span data-prime-media-total-label><?= $this->e($initialTotal > 1 ? $initialTotal . ' médias' : $initialTotal . ' média') ?></span>
                · Max <?= $this->e(number_format($limit['megabytes'], 1, ',', ' ')) ?> Mo/fichier
                <?php if ($limit['phpLimited']): ?>
                    <span class="pc-text-muted">(limité par PHP)</span>
                <?php endif; ?>
            </p>
        </div>
        <div class="pc-page-header__actions">
            <?php if ($canUpload): ?>
                <input type="file" multiple accept="<?= $this->e($acceptAttr) ?>" hidden data-prime-media-upload-input>
                <button type="button" class="pc-btn pc-btn--primary" data-prime-media-upload-trigger>
                    <?= $this->raw($this->icon('upload', 16)) ?>
                    <span>Ajouter des fichiers</span>
                </button>
            <?php endif; ?>
        </div>
    </header>

    <div class="pc-media-layout">
        <!-- Folder tree sidebar (desktop) -->
        <aside class="pc-media-sidebar" aria-label="Dossiers de la médiathèque">
            <div class="pc-media-sidebar__head">
                <h2 class="pc-media-sidebar__title">Dossiers</h2>
                <?php if ($canUpload): ?>
                    <button type="button" class="pc-btn pc-btn--ghost pc-btn--icon pc-btn--sm" data-prime-media-folder-create aria-label="Créer un dossier" title="Nouveau dossier">
                        <?= $this->raw($this->icon('folder-plus', 16)) ?>
                    </button>
                <?php endif; ?>
            </div>
            <ul class="pc-folder-tree" data-prime-media-folder-tree></ul>
        </aside>

        <!-- Main content area -->
        <section class="pc-media-main" aria-label="Liste des médias">
            <!-- Mobile folder dropdown (< 960px) -->
            <div class="pc-media-mobile-folder">
                <label class="pc-sr-only" for="pc-media-folder-mobile">Dossier</label>
                <select id="pc-media-folder-mobile" class="pc-select pc-select--sm" data-prime-media-folder-mobile></select>
                <?php if ($canUpload): ?>
                    <button type="button" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-media-folder-create aria-label="Créer un dossier">
                        <?= $this->raw($this->icon('folder-plus', 15)) ?>
                    </button>
                <?php endif; ?>
            </div>

            <!-- Toolbar: search, filters, sort, grid/list toggle -->
            <div class="pc-media-toolbar">
                <div class="pc-media-toolbar__search">
                    <span class="pc-media-toolbar__search-icon" aria-hidden="true"><?= $this->raw($this->icon('search', 15)) ?></span>
                    <label class="pc-sr-only" for="pc-media-search">Rechercher un média</label>
                    <input
                        id="pc-media-search"
                        type="search"
                        class="pc-input pc-input--sm"
                        placeholder="Rechercher par nom, titre, alt…"
                        value="<?= $this->e((string) ($initialFilters['q'] ?? '')) ?>"
                        data-prime-media-search
                    >
                </div>

                <div class="pc-media-toolbar__filters">
                    <select class="pc-select pc-select--sm" aria-label="Filtrer par type" data-prime-media-filter-kind>
                        <option value="">Tous les types</option>
                        <option value="image"<?= ($initialFilters['kind'] ?? '') === 'image' ? ' selected' : '' ?>>Images</option>
                        <option value="document"<?= ($initialFilters['kind'] ?? '') === 'document' ? ' selected' : '' ?>>Documents</option>
                        <option value="audio"<?= ($initialFilters['kind'] ?? '') === 'audio' ? ' selected' : '' ?>>Audio</option>
                        <option value="video"<?= ($initialFilters['kind'] ?? '') === 'video' ? ' selected' : '' ?>>Vidéos</option>
                        <option value="archive"<?= ($initialFilters['kind'] ?? '') === 'archive' ? ' selected' : '' ?>>Archives</option>
                    </select>

                    <select class="pc-select pc-select--sm" aria-label="Filtrer par date" data-prime-media-filter-month>
                        <option value="">Toutes les dates</option>
                    </select>

                    <button
                        type="button"
                        class="pc-media-chip<?= !empty($initialFilters['mine']) ? ' is-active' : '' ?>"
                        aria-pressed="<?= !empty($initialFilters['mine']) ? 'true' : 'false' ?>"
                        data-prime-media-filter-mine
                    >Mes fichiers</button>

                    <button
                        type="button"
                        class="pc-media-chip<?= !empty($initialFilters['unused']) ? ' is-active' : '' ?>"
                        aria-pressed="<?= !empty($initialFilters['unused']) ? 'true' : 'false' ?>"
                        data-prime-media-filter-unused
                    >Non utilisés</button>
                </div>

                <div class="pc-media-toolbar__right">
                    <select class="pc-select pc-select--sm" aria-label="Trier les médias" data-prime-media-sort>
                        <option value="created_at:desc">Plus récents</option>
                        <option value="created_at:asc">Plus anciens</option>
                        <option value="name:asc">Nom (A → Z)</option>
                        <option value="name:desc">Nom (Z → A)</option>
                        <option value="size:desc">Plus lourds</option>
                        <option value="size:asc">Plus légers</option>
                    </select>

                    <div class="pc-media-view-toggle" role="group" aria-label="Mode d’affichage">
                        <button type="button" class="pc-media-view-btn is-active" data-prime-media-view="grid" aria-pressed="true" aria-label="Vue grille" title="Vue grille">
                            <?= $this->raw($this->icon('grid', 15)) ?>
                        </button>
                        <button type="button" class="pc-media-view-btn" data-prime-media-view="list" aria-pressed="false" aria-label="Vue liste" title="Vue liste">
                            <?= $this->raw($this->icon('list', 15)) ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Bulk actions bar -->
            <div class="pc-media-bulk-bar" data-prime-media-bulk-bar hidden role="region" aria-label="Actions groupées">
                <strong class="pc-text-sm" data-prime-media-bulk-count>0 média sélectionné</strong>
                <div class="pc-media-bulk-bar__actions">
                    <?php if ($canUpload || $canDeleteAny): ?>
                        <div class="pc-media-bulk-bar__move">
                            <label class="pc-sr-only" for="pc-media-bulk-folder">Déplacer vers le dossier</label>
                            <select id="pc-media-bulk-folder" class="pc-select pc-select--sm" data-prime-media-bulk-folder></select>
                            <button type="button" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-media-bulk-move>Déplacer</button>
                        </div>
                        <button type="button" class="pc-btn pc-btn--danger pc-btn--sm" data-prime-media-bulk-delete>
                            <?= $this->raw($this->icon('trash', 14)) ?>
                            <span>Supprimer</span>
                        </button>
                    <?php endif; ?>
                    <button type="button" class="pc-btn pc-btn--ghost pc-btn--sm" data-prime-media-bulk-clear>Désélectionner</button>
                </div>
            </div>

            <!-- Grid view -->
            <div class="pc-media-grid" role="grid" aria-label="Grille des médias" data-prime-media-grid></div>

            <!-- List view -->
            <div class="pc-card pc-media-list" data-prime-media-list hidden>
                <div class="pc-table-wrap">
                    <table class="pc-table">
                        <thead>
                            <tr>
                                <th class="pc-table__check" scope="col"><span class="pc-sr-only">Sélection</span></th>
                                <th scope="col">Fichier</th>
                                <th scope="col">Type</th>
                                <th scope="col">Dimensions</th>
                                <th scope="col">Poids</th>
                                <th scope="col">Date</th>
                                <th scope="col">Utilisations</th>
                                <th scope="col" class="pc-table__actions"><span class="pc-sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody data-prime-media-list-body></tbody>
                    </table>
                </div>
            </div>

            <!-- Empty state -->
            <div class="pc-empty-state" data-prime-media-empty hidden>
                <div class="pc-empty-state__icon"><?= $this->raw($this->icon('image', 28)) ?></div>
                <h2 class="pc-empty-state__title" data-prime-media-empty-title>Votre médiathèque est vide</h2>
                <p class="pc-empty-state__text" data-prime-media-empty-desc>Glissez-déposez des fichiers n’importe où sur cette page pour commencer.</p>
                <div class="pc-empty-state__actions">
                    <button type="button" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-media-empty-reset hidden>Réinitialiser les filtres</button>
                    <?php if ($canUpload): ?>
                        <button type="button" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-media-empty-upload>
                            <?= $this->raw($this->icon('upload', 15)) ?>
                            <span>Parcourir l’ordinateur</span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Progressive pagination ("Charger plus" + IntersectionObserver sentinel) -->
            <div class="pc-media-more" data-prime-media-more-wrap hidden>
                <button type="button" class="pc-btn pc-btn--secondary" data-prime-media-more-btn>Charger plus</button>
            </div>
            <div class="pc-media-sentinel" data-prime-media-sentinel aria-hidden="true"></div>
        </section>
    </div>

    <!-- Floating parallel upload queue panel -->
    <aside class="pc-upload-queue" data-prime-media-queue-panel hidden aria-label="File de téléversement">
        <header class="pc-upload-queue__header">
            <strong class="pc-text-sm" data-prime-media-queue-title>Téléversement</strong>
            <button type="button" class="pc-btn pc-btn--ghost pc-btn--xs" data-prime-media-queue-clear>Effacer terminés</button>
        </header>
        <ul class="pc-upload-queue__list" data-prime-media-queue-list></ul>
    </aside>

    <div class="pc-sr-only" aria-live="polite" data-prime-media-live></div>
</div>

<!-- Detail slide-over drawer -->
<div
    id="pc-media-detail-drawer"
    class="pc-drawer pc-drawer--media"
    role="dialog"
    aria-modal="true"
    aria-labelledby="pc-media-detail-drawer-title"
    data-prime-drawer
    hidden
>
    <div class="pc-drawer__backdrop" data-prime-drawer-close aria-hidden="true"></div>
    <div class="pc-drawer__panel pc-drawer__panel--wide" data-prime-drawer-panel>
        <header class="pc-drawer__header">
            <h2 class="pc-drawer__title" id="pc-media-detail-drawer-title" data-prime-drawer-media-title>Détail du média</h2>
            <button type="button" class="pc-btn pc-btn--ghost pc-btn--icon pc-btn--sm" data-prime-drawer-close aria-label="Fermer le détail">
                <?= $this->raw($this->icon('x', 18)) ?>
            </button>
        </header>
        <div class="pc-drawer__body pc-media-drawer__body">
            <div class="pc-media-drawer__preview" data-prime-drawer-preview></div>
            <p class="pc-text-xs pc-text-muted pc-media-drawer__focal-hint" data-prime-drawer-focal-hint hidden>
                <?= $this->raw($this->icon('crosshair', 13)) ?>
                <span>Cliquez ou utilisez les flèches sur l’image pour ajuster le point focal, puis enregistrez.</span>
            </p>

            <div class="pc-media-drawer__section">
                <label class="pc-label" for="pc-drawer-url">URL publique</label>
                <div class="pc-input-group">
                    <input id="pc-drawer-url" type="text" class="pc-input pc-input--sm pc-mono" readonly data-prime-drawer-url>
                    <button type="button" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-drawer-copy-url>
                        <?= $this->raw($this->icon('copy', 14)) ?>
                        <span>Copier</span>
                    </button>
                </div>
            </div>

            <dl class="pc-media-specs" data-prime-drawer-specs></dl>

            <form class="pc-stack pc-stack--sm" data-prime-drawer-form>
                <div class="pc-field">
                    <label class="pc-label" for="pc-drawer-folder">Dossier</label>
                    <select id="pc-drawer-folder" class="pc-select pc-select--sm" data-prime-drawer-folder></select>
                </div>
                <div class="pc-field">
                    <label class="pc-label" for="pc-drawer-alt">Texte alternatif (alt)</label>
                    <input id="pc-drawer-alt" type="text" class="pc-input pc-input--sm" maxlength="255" placeholder="Décrivez brièvement l’image pour l’accessibilité…" data-prime-drawer-alt>
                </div>
                <div class="pc-field">
                    <label class="pc-label" for="pc-drawer-title">Titre</label>
                    <input id="pc-drawer-title" type="text" class="pc-input pc-input--sm" maxlength="255" data-prime-drawer-title>
                </div>
                <div class="pc-field">
                    <label class="pc-label" for="pc-drawer-caption">Légende</label>
                    <textarea id="pc-drawer-caption" class="pc-textarea" rows="2" data-prime-drawer-caption></textarea>
                </div>
                <div class="pc-field">
                    <label class="pc-label" for="pc-drawer-description">Description</label>
                    <textarea id="pc-drawer-description" class="pc-textarea" rows="2" data-prime-drawer-description></textarea>
                </div>
                <div>
                    <button type="submit" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-drawer-save>
                        <?= $this->raw($this->icon('save', 15)) ?>
                        <span>Enregistrer les métadonnées</span>
                    </button>
                </div>
            </form>

            <div class="pc-media-drawer__section">
                <h3 class="pc-media-drawer__subtitle">Utilisé dans</h3>
                <ul class="pc-media-usage-list" data-prime-drawer-usages></ul>
            </div>

            <div class="pc-media-drawer__actions">
                <button type="button" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-drawer-regenerate>
                    <?= $this->raw($this->icon('rotate-ccw', 14)) ?>
                    <span>Régénérer</span>
                </button>
                <button type="button" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-drawer-replace-btn>
                    <?= $this->raw($this->icon('upload', 14)) ?>
                    <span>Remplacer</span>
                </button>
                <input type="file" accept="<?= $this->e($acceptAttr) ?>" hidden data-prime-drawer-replace-input>
                <a href="#" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-drawer-download>
                    <?= $this->raw($this->icon('download', 14)) ?>
                    <span>Télécharger</span>
                </a>
                <button type="button" class="pc-btn pc-btn--danger pc-btn--sm" data-prime-drawer-delete>
                    <?= $this->raw($this->icon('trash', 14)) ?>
                    <span>Supprimer</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Folder create/rename/move modal -->
<dialog id="pc-media-folder-modal" class="pc-modal pc-modal--sm" aria-labelledby="pc-media-folder-modal-title">
    <header class="pc-modal__header">
        <h2 class="pc-modal__title" id="pc-media-folder-modal-title" data-prime-folder-modal-title>Nouveau dossier</h2>
        <button type="button" class="pc-btn pc-btn--ghost pc-btn--icon pc-btn--sm" data-prime-modal-close aria-label="Fermer">
            <?= $this->raw($this->icon('x', 18)) ?>
        </button>
    </header>
    <form data-prime-folder-modal-form>
        <div class="pc-modal__body pc-stack pc-stack--sm">
            <div class="pc-field">
                <label class="pc-label" for="pc-folder-modal-name">Nom du dossier</label>
                <input id="pc-folder-modal-name" type="text" class="pc-input" maxlength="120" required data-prime-folder-modal-name placeholder="Ex. Produits, Équipe, Communiqués…">
            </div>
            <div class="pc-field">
                <label class="pc-label" for="pc-folder-modal-parent">Dossier parent</label>
                <select id="pc-folder-modal-parent" class="pc-select" data-prime-folder-modal-parent></select>
            </div>
        </div>
        <footer class="pc-modal__footer">
            <button type="button" class="pc-btn pc-btn--ghost pc-btn--sm" data-prime-modal-close>Annuler</button>
            <button type="submit" class="pc-btn pc-btn--secondary pc-btn--sm">Enregistrer</button>
        </footer>
    </form>
</dialog>

<!-- Named confirmation modal (media delete, bulk delete, folder delete) -->
<dialog id="pc-media-confirm-modal" class="pc-modal pc-modal--sm" aria-labelledby="pc-media-confirm-title">
    <header class="pc-modal__header">
        <h2 class="pc-modal__title" id="pc-media-confirm-title" data-prime-confirm-title>Confirmer la suppression</h2>
        <button type="button" class="pc-btn pc-btn--ghost pc-btn--icon pc-btn--sm" data-prime-modal-close aria-label="Fermer">
            <?= $this->raw($this->icon('x', 18)) ?>
        </button>
    </header>
    <div class="pc-modal__body pc-stack pc-stack--sm">
        <div class="pc-alert pc-alert--warning" role="alert" data-prime-confirm-warning hidden></div>
        <p class="pc-text-sm" data-prime-confirm-message>Cette action est irréversible.</p>
    </div>
    <footer class="pc-modal__footer">
        <button type="button" class="pc-btn pc-btn--ghost pc-btn--sm" data-prime-modal-close>Annuler</button>
        <button type="button" class="pc-btn pc-btn--danger pc-btn--sm" data-prime-confirm-submit>Supprimer</button>
    </footer>
</dialog>
