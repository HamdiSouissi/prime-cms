<?php

declare(strict_types=1);

/**
 * Revision history and unified line diff view (`GET /admin/content/{type}/{id}/revisions`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var \Prime\Content\ContentType $contentType
 * @var \Prime\Content\Entry $entry
 * @var list<\Prime\Content\Revision> $revisions
 * @var \Prime\Content\Revision|null $selected
 * @var list<array{op: 'equal'|'added'|'removed', text: string}>|null $titleDiff
 * @var list<array{op: 'equal'|'added'|'removed', text: string}>|null $bodyDiff
 * @var array<int, \Prime\Auth\User> $authorsMap
 */
$this->layout('admin::layouts/app');
$baseUrl = $contentType->adminListUrl();
$editUrl = $entry->editUrl();
?>
<?php $this->start('content'); ?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => 'Révisions — ' . $entry->title,
    'subtitle' => 'Comparez une version antérieure avec la version actuelle ou restaurez-la en un clic.',
    'breadcrumbs' => $breadcrumbs,
    'actions' => $this->insert('admin::components/button', [
        'label' => 'Retour à l’éditeur',
        'variant' => 'secondary',
        'icon' => 'chevron-left',
        'href' => $editUrl,
    ]),
])) ?>

<?php if ($revisions === []): ?>
    <?= $this->raw($this->insert('admin::components/empty-state', [
        'icon' => 'history',
        'accent' => 'sky',
        'title' => 'Aucune révision enregistrée',
        'description' => 'Chaque sauvegarde manuelle ou automatique enregistre automatiquement un instantané consultable ici.',
        'actionLabel' => 'Retour à l’éditeur',
        'actionUrl' => $editUrl,
    ])) ?>
<?php else: ?>
    <div class="pc-taxonomy-grid">
        <section class="pc-card">
            <div class="pc-card__header">
                <h2 class="pc-card__title"><?= t('admin.content.revisions.title') ?> (<?= $this->e((string) count($revisions)) ?>)</h2>
            </div>
            <div class="pc-card__body" style="padding:12px;">
                <div class="pc-stack pc-stack--xs">
                    <?php foreach ($revisions as $rev): ?>
                        <?php
                        $isCurrent = $selected !== null && $selected->id === $rev->id;
                        $revAuthor = $rev->authorId !== null ? ($authorsMap[$rev->authorId] ?? null) : null;
                        ?>
                        <a
                            href="<?= $this->e(sprintf('%s/%d/revisions?compare=%d', $baseUrl, $entry->id, $rev->id)) ?>"
                            class="pc-content-tab<?= $isCurrent ? ' is-active' : '' ?>"
                            style="border-radius:10px;justify-content:space-between;padding:10px 12px;"
                        >
                            <span>
                                <strong>#<?= $this->e((string) $rev->id) ?></strong> · <?= $this->e($rev->createdAt) ?>
                                <br>
                                <span class="pc-text-xs pc-text-muted">
                                    <?= $this->e($revAuthor !== null ? $revAuthor->displayName : ($rev->authorName ?? 'Système')) ?>
                                    <?= $rev->isAutosave ? ' · Auto' : '' ?>
                                </span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <div>
            <?php if ($selected !== null): ?>
                <div class="pc-diff-card">
                    <div class="pc-diff-card__header">
                        <div>
                            <strong><?= $this->e($this->t('admin.content.revisions.comparaison', ['id' => $selected->id])) ?></strong>
                            <div class="pc-text-xs pc-text-muted"><?= $this->e($this->t('admin.content.revisions.creee_le', ['date' => $selected->createdAt])) ?></div>
                        </div>
                        <div class="pc-row pc-row--sm">
                            <a
                                href="<?= $this->e(sprintf('%s/%d/preview?revision=%d', $baseUrl, $entry->id, $selected->id)) ?>"
                                target="_blank"
                                rel="noopener"
                                class="pc-btn pc-btn--secondary pc-btn--sm"
                            <?= $this->e($this->t('core.actions.preview')) ?></a>
                            <form method="post" action="<?= $this->e(sprintf('%s/%d/revisions/%d/restore', $baseUrl, $entry->id, $selected->id)) ?>">
                                <?= $this->raw($this->csrfField()) ?>
                                <button type="submit" class="pc-btn pc-btn--primary pc-btn--sm">
                                    <?= $this->raw($this->icon('rotate-ccw', 15)) ?>
                                    <span>Restaurer</span>
                                </button>
                            </form>
                        </div>
                    </div>

                    <?php if ($titleDiff !== null): ?>
                        <div style="padding:10px 14px;border-bottom:1px solid var(--ink-200);font-size:12px;font-weight:600;color:var(--ink-500);">Titre</div>
                        <div class="pc-diff-lines">
                            <?php foreach ($titleDiff as $row): ?>
                                <?php $marker = $row['op'] === 'added' ? '+' : ($row['op'] === 'removed' ? '−' : ' '); ?>
                                <div class="pc-diff-line pc-diff-line--<?= $this->e($row['op']) ?>">
                                    <span class="pc-diff-marker"><?= $this->e($marker) ?></span>
                                    <span><?= $this->e($row['text']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($bodyDiff !== null): ?>
                        <div style="padding:10px 14px;border-top:1px solid var(--ink-200);border-bottom:1px solid var(--ink-200);font-size:12px;font-weight:600;color:var(--ink-500);">Contenu</div>
                        <div class="pc-diff-lines">
                            <?php foreach ($bodyDiff as $row): ?>
                                <?php $marker = $row['op'] === 'added' ? '+' : ($row['op'] === 'removed' ? '−' : ' '); ?>
                                <div class="pc-diff-line pc-diff-line--<?= $this->e($row['op']) ?>">
                                    <span class="pc-diff-marker"><?= $this->e($marker) ?></span>
                                    <span><?= $this->e($row['text']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php $this->stop(); ?>
