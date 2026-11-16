<?php

declare(strict_types=1);

/**
 * Split taxonomy management view (`GET /admin/taxonomies/{taxonomy}`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var \Prime\Content\Taxonomy $taxonomy
 * @var list<\Prime\Content\Term> $terms
 * @var list<\Prime\Content\Term> $allTerms
 * @var array<int, int> $depths
 * @var \Prime\Content\Term|null $editing
 * @var string $search
 */
$this->layout('admin::layouts/app');
$baseUrl = $taxonomy->adminUrl();
$isEdit = $editing !== null;
$formAction = $isEdit ? sprintf('%s/%d', $baseUrl, $editing->id) : $baseUrl;
?>
<?php $this->start('content'); ?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => $taxonomy->label('plural'),
    'subtitle' => 'Organisez et classez vos contenus.',
    'breadcrumbs' => $breadcrumbs,
])) ?>

<div class="pc-taxonomy-grid">
    <section class="pc-card">
        <div class="pc-card__header">
            <h2 class="pc-card__title"><?= $this->e($isEdit ? 'Modifier : ' . $editing->name : $taxonomy->label('add_new')) ?></h2>
        </div>
        <div class="pc-card__body">
            <form method="post" action="<?= $this->e($formAction) ?>" class="pc-stack pc-stack--md">
                <?= $this->raw($this->csrfField()) ?>

                <div class="pc-field">
                    <label class="pc-label" for="pc-term-name"><?= t('admin.taxonomies.name') ?> *</label>
                    <input
                        id="pc-term-name"
                        type="text"
                        name="name"
                        value="<?= $this->e($editing?->name ?? '') ?>"
                        class="pc-input"
                        required
                    >
                </div>

                <div class="pc-field">
                    <label class="pc-label" for="pc-term-slug">Slug</label>
                    <input
                        id="pc-term-slug"
                        type="text"
                        name="slug"
                        value="<?= $this->e($editing?->slug ?? '') ?>"
                        class="pc-input pc-mono"
                        placeholder="genere-automatiquement"
                    >
                </div>

                <?php if ($taxonomy->hierarchical): ?>
                    <div class="pc-field">
                        <label class="pc-label" for="pc-term-parent"><?= $this->e($taxonomy->label('parent_label')) ?></label>
                        <select id="pc-term-parent" name="parent_id" class="pc-select">
                            <option value="0"><?= $this->e($this->t('admin.taxonomies.index.aucun_racine')) ?></option>
                            <?php foreach ($allTerms as $candidate): ?>
                                <?php if ($editing !== null && $candidate->id === $editing->id) { continue; } ?>
                                <option value="<?= $this->e((string) $candidate->id) ?>"<?= $editing?->parentId === $candidate->id ? ' selected' : '' ?>>
                                    <?= $this->e(str_repeat('— ', $depths[$candidate->id] ?? 0) . $candidate->name) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="pc-field">
                    <label class="pc-label" for="pc-term-desc">Description</label>
                    <textarea id="pc-term-desc" name="description" rows="3" class="pc-textarea"><?= $this->e($editing?->description ?? '') ?></textarea>
                </div>

                <div class="pc-row pc-row--sm">
                    <button type="submit" class="pc-btn pc-btn--primary">
                        <?= $this->e($isEdit ? 'Enregistrer' : 'Ajouter') ?>
                    </button>
                    <?php if ($isEdit): ?>
                        <a href="<?= $this->e($baseUrl) ?>" class="pc-btn pc-btn--secondary"><?= $this->e($this->t('core.actions.cancel')) ?></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </section>

    <section class="pc-card">
        <form method="get" action="<?= $this->e($baseUrl) ?>" class="pc-content-filters">
            <input
                type="text"
                name="q"
                value="<?= $this->e($search) ?>"
                class="pc-input pc-content-filters__search"
                placeholder="<?= $this->e($this->t('admin.taxonomies.index.rechercher_placeholder', ['type' => mb_strtolower($taxonomy->label('plural'))])) ?>"
                aria-label="<?= $this->e($this->t('core.actions.search')) ?>"
            >
            <button type="submit" class="pc-btn pc-btn--secondary pc-btn--sm"><?= $this->e($this->t('core.actions.search')) ?></button>
            <?php if ($search !== ''): ?>
                <a href="<?= $this->e($baseUrl) ?>" class="pc-btn pc-btn--ghost pc-btn--sm"><?= $this->e($this->t('core.actions.reset')) ?></a>
            <?php endif; ?>
        </form>

        <?php if ($terms === []): ?>
            <?= $this->raw($this->insert('admin::components/empty-state', [
                'icon' => $taxonomy->icon,
                'accent' => 'teal',
                'title' => $taxonomy->label('empty_title'),
                'description' => 'Utilisez le formulaire ci-contre pour créer votre premier terme.',
            ])) ?>
        <?php else: ?>
            <div class="pc-table-wrap">
                <table class="pc-table pc-content-table">
                    <thead>
                        <tr>
                            <th>Nom</th>
                            <th>Slug</th>
                            <th><?= $this->e($this->t('admin.taxonomies.index.contenus')) ?></th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($terms as $term): ?>
                            <?php $depth = $depths[$term->id] ?? 0; ?>
                            <tr>
                                <td data-prime-label="Nom">
                                    <strong>
                                        <?php if ($depth > 0): ?>
                                            <span class="pc-entry-indent"><?= $this->e(str_repeat('— ', $depth)) ?></span>
                                        <?php endif; ?>
                                        <?= $this->e($term->name) ?>
                                    </strong>
                                    <?php if ($term->description !== null && $term->description !== ''): ?>
                                        <div class="pc-text-xs pc-text-muted"><?= $this->e($term->description) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td data-prime-label="Slug">
                                    <span class="pc-entry-slug"><?= $this->e($term->slug) ?></span>
                                </td>
                                <td data-prime-label="Contenus">
                                    <?= $this->raw($this->insert('admin::components/badge', [
                                        'label' => (string) $term->count,
                                        'variant' => 'neutral',
                                    ])) ?>
                                </td>
                                <td data-prime-label="Actions">
                                    <div class="pc-row-actions">
                                        <a href="<?= $this->e(sprintf('%s/%d/edit', $baseUrl, $term->id)) ?>" class="pc-btn pc-btn--secondary pc-btn--sm">Modifier</a>
                                        <form method="post" action="<?= $this->e(sprintf('%s/%d/delete', $baseUrl, $term->id)) ?>" class="pc-row-action-form">
                                            <?= $this->raw($this->csrfField()) ?>
                                            <button type="submit" class="pc-btn pc-btn--ghost pc-btn--sm"><?= $this->e($this->t('core.actions.delete')) ?></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php $this->stop(); ?>
