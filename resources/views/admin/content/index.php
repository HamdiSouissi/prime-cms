<?php

declare(strict_types=1);

/**
 * Dynamic content listing (`GET /admin/content/{type}`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var \Prime\Content\ContentType $contentType
 * @var list<\Prime\Content\Entry> $entries
 * @var int $total
 * @var int $page
 * @var int $totalPages
 * @var array<string, int> $counts
 * @var int $allTotal
 * @var array{status: string, q: string, author: ?int, term: string, month: string, sort: string, dir: string} $filters
 * @var list<string> $availableMonths
 * @var array<int, \Prime\Auth\User> $authorsMap
 * @var array<int, \Prime\Content\EntryLock> $locksMap
 * @var array<int, int> $depths
 * @var list<array{value: string, label: string}> $filterTerms
 * @var list<\Prime\Content\Taxonomy> $associatedTaxonomies
 * @var array<int, bool> $editableMap
 * @var array<int, bool> $deletableMap
 * @var int $currentUserId
 * @var bool $canCreate
 * @var bool $canPublish
 * @var bool $canDelete
 */
$this->layout('admin::layouts/app');

$baseUrl = $contentType->adminListUrl();
$isTrash = $filters['status'] === 'trash';

$actionsHtml = '';
if ($isTrash && $canDelete && ($counts['trash'] ?? 0) > 0) {
    $actionsHtml .= '<button type="button" class="pc-btn pc-btn--danger pc-btn--sm"'
        . ' data-prime-confirm-delete'
        . ' data-prime-action="' . $this->e($baseUrl . '/trash/empty') . '"'
        . ' data-prime-item-name="tous les éléments de la corbeille"'
        . ' data-prime-warning="Tous les contenus dans la corbeille seront définitivement supprimés.">'
        . $this->icon('trash', 16) . '<span>Vider la corbeille</span></button>';
}
if ($canCreate) {
    $actionsHtml .= $this->insert('admin::components/button', [
        'label' => $contentType->label('add_new'),
        'variant' => 'primary',
        'icon' => 'plus',
        'href' => $contentType->adminCreateUrl(),
    ]);
}
?>
<?php $this->start('content'); ?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => $contentType->label('plural'),
    'subtitle' => 'Gérez, rédigez, planifiez et publiez vos contenus.',
    'breadcrumbs' => $breadcrumbs,
    'actions' => $actionsHtml,
])) ?>

<!-- Status tabs -->
<nav class="pc-content-tabs" aria-label="Filtrer par statut">
    <?php
    $tabs = [
        ['key' => 'all', 'label' => 'Tous', 'count' => $allTotal],
        ['key' => 'published', 'label' => 'Publiés', 'count' => $counts['published'] ?? 0],
        ['key' => 'draft', 'label' => 'Brouillons', 'count' => $counts['draft'] ?? 0],
        ['key' => 'pending', 'label' => 'En attente', 'count' => $counts['pending'] ?? 0],
        ['key' => 'scheduled', 'label' => 'Planifiés', 'count' => $counts['scheduled'] ?? 0],
        ['key' => 'private', 'label' => 'Privés', 'count' => $counts['private'] ?? 0],
        ['key' => 'trash', 'label' => 'Corbeille', 'count' => $counts['trash'] ?? 0],
    ];
    foreach ($tabs as $tab):
        $active = $filters['status'] === $tab['key'];
        $tabUrl = $tab['key'] === 'all' ? $baseUrl : $baseUrl . '?status=' . rawurlencode($tab['key']);
    ?>
        <a href="<?= $this->e($tabUrl) ?>" class="pc-content-tab<?= $active ? ' is-active' : '' ?>"<?= $active ? ' aria-current="page"' : '' ?>>
            <span><?= $this->e($tab['label']) ?></span>
            <span class="pc-content-tab__count"><?= $this->e((string) $tab['count']) ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<section class="pc-card">
    <!-- Filter toolbar -->
    <form method="get" action="<?= $this->e($baseUrl) ?>" class="pc-content-filters">
        <?php if ($filters['status'] !== 'all'): ?>
            <input type="hidden" name="status" value="<?= $this->e($filters['status']) ?>">
        <?php endif; ?>

        <input
            type="text"
            name="q"
            value="<?= $this->e($filters['q']) ?>"
            class="pc-input pc-content-filters__search"
            placeholder="<?= $this->e($contentType->label('search_placeholder')) ?>"
            aria-label="Rechercher"
        >

        <?php if ($authorsMap !== []): ?>
            <select name="author" class="pc-select" aria-label="Filtrer par auteur">
                <option value="">Tous les auteurs</option>
                <?php foreach ($authorsMap as $uid => $author): ?>
                    <option value="<?= $this->e((string) $uid) ?>"<?= $filters['author'] === $uid ? ' selected' : '' ?>>
                        <?= $this->e($author->displayName) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <?php if ($filterTerms !== []): ?>
            <select name="term" class="pc-select" aria-label="Filtrer par terme">
                <option value="">Toutes les taxonomies</option>
                <?php foreach ($filterTerms as $ft): ?>
                    <option value="<?= $this->e($ft['value']) ?>"<?= $filters['term'] === $ft['value'] ? ' selected' : '' ?>>
                        <?= $this->e($ft['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <?php if ($availableMonths !== []): ?>
            <select name="month" class="pc-select" aria-label="Filtrer par mois">
                <option value="">Tous les mois</option>
                <?php foreach ($availableMonths as $ym): ?>
                    <option value="<?= $this->e($ym) ?>"<?= $filters['month'] === $ym ? ' selected' : '' ?>>
                        <?= $this->e($ym) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <select name="sort" class="pc-select" aria-label="Trier par">
            <option value="updated_at"<?= $filters['sort'] === 'updated_at' ? ' selected' : '' ?>>Dernière modification</option>
            <option value="published_at"<?= $filters['sort'] === 'published_at' ? ' selected' : '' ?>>Date de publication</option>
            <option value="title"<?= $filters['sort'] === 'title' ? ' selected' : '' ?>>Titre</option>
            <?php if ($contentType->hierarchical): ?>
                <option value="menu_order"<?= $filters['sort'] === 'menu_order' ? ' selected' : '' ?>>Ordre du menu</option>
            <?php endif; ?>
        </select>

        <button type="submit" class="pc-btn pc-btn--secondary pc-btn--sm">Filtrer</button>

        <?php if ($filters['q'] !== '' || $filters['author'] !== null || $filters['term'] !== '' || $filters['month'] !== ''): ?>
            <a href="<?= $this->e($baseUrl) ?>" class="pc-btn pc-btn--ghost pc-btn--sm">Réinitialiser</a>
        <?php endif; ?>
    </form>

    <?php if ($entries === []): ?>
        <?= $this->raw($this->insert('admin::components/empty-state', [
            'icon' => $contentType->icon,
            'accent' => $contentType->accent,
            'title' => $isTrash ? 'La corbeille est vide' : $contentType->label('empty_title'),
            'description' => $isTrash
                ? 'Les éléments placés dans la corbeille apparaîtront ici.'
                : $contentType->label('empty_description'),
            'actionLabel' => (!$isTrash && $canCreate) ? $contentType->label('add_new') : null,
            'actionUrl' => (!$isTrash && $canCreate) ? $contentType->adminCreateUrl() : null,
        ])) ?>
    <?php else: ?>
        <form method="post" action="<?= $this->e($baseUrl . '/bulk') ?>" data-prime-table-bulk>
            <?= $this->raw($this->csrfField()) ?>

            <div class="pc-table-bulk" data-prime-table-bulk-bar hidden>
                <span class="pc-text-sm" data-prime-table-bulk-count>0 sélectionné(s)</span>
                <select name="bulk_action" class="pc-select pc-select--sm" aria-label="Action groupée">
                    <option value="">Choisir une action…</option>
                    <?php if ($isTrash): ?>
                        <option value="restore">Restaurer</option>
                        <?php if ($canDelete): ?>
                            <option value="delete">Supprimer définitivement</option>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if ($canPublish): ?>
                            <option value="publish">Publier</option>
                        <?php endif; ?>
                        <option value="draft">Passer en brouillon</option>
                        <?php if ($canDelete): ?>
                            <option value="trash">Mettre à la corbeille</option>
                        <?php endif; ?>
                    <?php endif; ?>
                </select>
                <button type="submit" class="pc-btn pc-btn--secondary pc-btn--sm">Appliquer</button>
            </div>

            <div class="pc-table-wrap">
                <table class="pc-table pc-content-table">
                    <thead>
                        <tr>
                            <th style="width: 36px;">
                                <input type="checkbox" data-prime-table-select-all aria-label="Tout sélectionner">
                            </th>
                            <th>Titre</th>
                            <th>Statut</th>
                            <th>Auteur</th>
                            <th>Mise à jour</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entries as $entry): ?>
                            <?php
                            $author = $entry->authorId !== null ? ($authorsMap[$entry->authorId] ?? null) : null;
                            $lock = $locksMap[$entry->id] ?? null;
                            $lockHolder = $lock !== null ? ($authorsMap[$lock->userId] ?? null) : null;
                            $depth = $depths[$entry->id] ?? 0;
                            $editUrl = $entry->editUrl();
                            $previewUrl = $entry->previewUrl();
                            $canEditRow = $editableMap[$entry->id] ?? false;
                            $canDeleteRow = $deletableMap[$entry->id] ?? false;
                            $isPendingReadOnly = !$canEditRow && $entry->status === 'pending' && $entry->authorId === $currentUserId;
                            ?>
                            <tr>
                                <td>
                                    <input
                                        type="checkbox"
                                        name="ids[]"
                                        value="<?= $this->e((string) $entry->id) ?>"
                                        data-prime-table-select-row
                                        aria-label="Sélectionner <?= $this->e($entry->title) ?>"
                                    >
                                </td>
                                <td data-prime-label="Titre">
                                    <?php $thumb = is_array($featuredThumbs[$entry->id] ?? null) ? $featuredThumbs[$entry->id] : null; ?>
                                    <div style="display:flex;align-items:center;gap:10px;">
                                        <?php if ($thumb !== null): ?>
                                            <img
                                                class="pc-content-thumb"
                                                src="<?= $this->e((string) $thumb['url']) ?>"
                                                alt="<?= $this->e((string) $thumb['alt']) ?>"
                                                width="40"
                                                height="40"
                                                loading="lazy"
                                            >
                                        <?php endif; ?>
                                        <div class="pc-entry-title-cell">
                                        <?php if ($canEditRow || $isPendingReadOnly): ?>
                                            <a href="<?= $this->e($editUrl) ?>" class="pc-entry-title-link">
                                                <?php if ($depth > 0): ?>
                                                    <span class="pc-entry-indent"><?= $this->e(str_repeat('— ', $depth)) ?></span>
                                                <?php endif; ?>
                                                <span><?= $this->e($entry->title) ?></span>
                                            </a>
                                        <?php else: ?>
                                            <span class="pc-entry-title-link">
                                                <?php if ($depth > 0): ?>
                                                    <span class="pc-entry-indent"><?= $this->e(str_repeat('— ', $depth)) ?></span>
                                                <?php endif; ?>
                                                <span><?= $this->e($entry->title) ?></span>
                                            </span>
                                        <?php endif; ?>
                                        <span class="pc-entry-slug">/<?= $this->e($entry->slug) ?></span>
                                        <?php if ($isPendingReadOnly): ?>
                                            <span class="pc-text-xs pc-text-muted">Soumis pour relecture (non modifiable tant qu’en attente)</span>
                                        <?php endif; ?>
                                        <?php if ($lock !== null): ?>
                                            <span class="pc-entry-lock-pill">
                                                <?= $this->raw($this->icon('lock', 12)) ?>
                                                <span>En édition par <?= $this->e($lockHolder !== null ? $lockHolder->displayName : ($lock->userName !== '' ? $lock->userName : 'un utilisateur')) ?></span>
                                            </span>
                                        <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td data-prime-label="Statut">
                                    <?= $this->raw($this->insert('admin::components/badge', [
                                        'label' => $entry->statusLabel(),
                                        'variant' => $entry->statusVariant(),
                                        'dot' => true,
                                    ])) ?>
                                    <?php if ($entry->status === 'scheduled' && $entry->publishedAt !== null): ?>
                                        <div class="pc-text-xs pc-text-muted"><?= $this->e($entry->publishedAt) ?> UTC</div>
                                    <?php endif; ?>
                                </td>
                                <td data-prime-label="Auteur">
                                    <?= $this->e($author !== null ? $author->displayName : ($entry->authorName ?? '—')) ?>
                                </td>
                                <td data-prime-label="Mise à jour">
                                    <span class="pc-text-xs pc-text-muted"><?= $this->e($entry->updatedAt) ?></span>
                                </td>
                                <td data-prime-label="Actions">
                                    <div class="pc-row-actions">
                                        <?php if ($isTrash): ?>
                                            <?php if ($canDeleteRow): ?>
                                                <button
                                                    type="submit"
                                                    form="pc-restore-<?= $this->e((string) $entry->id) ?>"
                                                    class="pc-btn pc-btn--secondary pc-btn--sm"
                                                >Restaurer</button>
                                                <button
                                                    type="button"
                                                    class="pc-btn pc-btn--danger pc-btn--sm"
                                                    data-prime-confirm-delete
                                                    data-prime-action="<?= $this->e(sprintf('%s/%d/delete', $baseUrl, $entry->id)) ?>"
                                                    data-prime-item-name="« <?= $this->e($entry->title) ?> »"
                                                    data-prime-warning="Ce contenu, ses révisions et ses métadonnées seront définitivement supprimés."
                                                >Supprimer</button>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php if ($canEditRow): ?>
                                                <a href="<?= $this->e($editUrl) ?>" class="pc-btn pc-btn--secondary pc-btn--sm">Modifier</a>
                                            <?php elseif ($isPendingReadOnly): ?>
                                                <a href="<?= $this->e($editUrl) ?>" class="pc-btn pc-btn--secondary pc-btn--sm">Consulter</a>
                                            <?php endif; ?>
                                            <a href="<?= $this->e($previewUrl) ?>" class="pc-btn pc-btn--ghost pc-btn--sm" target="_blank" rel="noopener">Aperçu</a>
                                            <?php if ($canCreate): ?>
                                                <button
                                                    type="submit"
                                                    form="pc-duplicate-<?= $this->e((string) $entry->id) ?>"
                                                    class="pc-btn pc-btn--ghost pc-btn--sm"
                                                >Dupliquer</button>
                                            <?php endif; ?>
                                            <?php if ($canDeleteRow): ?>
                                                <button
                                                    type="submit"
                                                    form="pc-trash-<?= $this->e((string) $entry->id) ?>"
                                                    class="pc-btn pc-btn--ghost pc-btn--sm"
                                                >Corbeille</button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </form>

        <!-- Detached CSRF-protected row action forms -->
        <?php foreach ($entries as $entry): ?>
            <?php if ($isTrash): ?>
                <form id="pc-restore-<?= $this->e((string) $entry->id) ?>" method="post" action="<?= $this->e(sprintf('%s/%d/restore', $baseUrl, $entry->id)) ?>" hidden>
                    <?= $this->raw($this->csrfField()) ?>
                </form>
            <?php else: ?>
                <?php if ($canCreate): ?>
                    <form id="pc-duplicate-<?= $this->e((string) $entry->id) ?>" method="post" action="<?= $this->e(sprintf('%s/%d/duplicate', $baseUrl, $entry->id)) ?>" hidden>
                        <?= $this->raw($this->csrfField()) ?>
                    </form>
                <?php endif; ?>
                <?php if ($canDelete): ?>
                    <form id="pc-trash-<?= $this->e((string) $entry->id) ?>" method="post" action="<?= $this->e(sprintf('%s/%d/trash', $baseUrl, $entry->id)) ?>" hidden>
                        <?= $this->raw($this->csrfField()) ?>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($totalPages > 1): ?>
            <div class="pc-card__footer" style="display:flex;justify-content:space-between;align-items:center;">
                <span class="pc-text-xs pc-text-muted">Page <?= $this->e((string) $page) ?> / <?= $this->e((string) $totalPages) ?> (<?= $this->e((string) $total) ?> résultats)</span>
                <div class="pc-row pc-row--sm">
                    <?php if ($page > 1): ?>
                        <a class="pc-btn pc-btn--secondary pc-btn--sm" href="<?= $this->e($baseUrl . '?' . http_build_query(array_filter(['status' => $filters['status'] !== 'all' ? $filters['status'] : null, 'q' => $filters['q'] !== '' ? $filters['q'] : null, 'page' => $page - 1]))) ?>">Précédent</a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <a class="pc-btn pc-btn--secondary pc-btn--sm" href="<?= $this->e($baseUrl . '?' . http_build_query(array_filter(['status' => $filters['status'] !== 'all' ? $filters['status'] : null, 'q' => $filters['q'] !== '' ? $filters['q'] : null, 'page' => $page + 1]))) ?>">Suivant</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<dialog id="pc-content-confirm-modal" class="pc-modal" data-prime-confirm-modal aria-label="Confirmation de suppression">
    <div class="pc-modal__header">
        <h2 class="pc-modal__title" data-prime-confirm-heading>Confirmer la suppression</h2>
        <button type="button" class="pc-btn pc-btn--ghost pc-btn--icon pc-btn--sm" data-prime-modal-close aria-label="Fermer">
            <?= $this->raw($this->icon('x', 16)) ?>
        </button>
    </div>
    <div class="pc-modal__body">
        <p data-prime-confirm-body>Cette action est définitive et irréversible.</p>
    </div>
    <div class="pc-modal__footer">
        <button type="button" class="pc-btn pc-btn--secondary" data-prime-modal-close>Annuler</button>
        <form method="post" action="" data-prime-confirm-form>
            <?= $this->raw($this->csrfField()) ?>
            <button type="submit" class="pc-btn pc-btn--danger">Supprimer définitivement</button>
        </form>
    </div>
</dialog>

<?php $this->stop(); ?>
