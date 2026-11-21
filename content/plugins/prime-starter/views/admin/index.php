<?php

declare(strict_types=1);

/**
 * « Carnet » — list of notes (`GET /admin/prime-starter`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var list<array{id: int, title: string, body: string, color: string, pinned: bool, public: bool, updated_at: string}> $notes
 * @var bool $publicEnabled
 * @var string $publicUrl
 * @var string $pageHeading
 * @var list<array{label: string, url: ?string}> $breadcrumbs
 */
$this->layout('admin::layouts/app');

$actions = '';
if ($publicEnabled) {
    $actions .= '<a href="' . $this->e($publicUrl) . '" target="_blank" rel="noopener" class="pc-btn pc-btn--secondary">'
        . $this->icon('external-link', 16) . '<span>' . $this->e($this->t('plugin::prime-starter.ui.view_public')) . '</span></a>';
}
$actions .= '<a href="/admin/prime-starter/new" class="pc-btn pc-btn--primary">'
    . $this->icon('plus', 16) . '<span>' . $this->e($this->t('plugin::prime-starter.ui.new')) . '</span></a>';
?>
<?php $this->start('content'); ?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => $pageHeading,
    'subtitle' => $this->t('plugin::prime-starter.ui.subtitle'),
    'breadcrumbs' => $breadcrumbs,
    'actions' => $actions,
])) ?>

<?php if ($notes === []): ?>
    <?= $this->raw($this->insert('admin::components/card', [
        'body' => $this->insert('admin::components/empty-state', [
            'icon' => 'file-text',
            'accent' => 'teal',
            'title' => $this->t('plugin::prime-starter.ui.empty_title'),
            'description' => $this->t('plugin::prime-starter.ui.empty_description'),
        ]),
    ])) ?>
<?php else: ?>
    <div class="pc-card">
        <div class="pc-table-wrap">
            <table class="pc-table pc-table--stacked">
                <caption class="pc-sr-only"><?= $this->e($this->t('plugin::prime-starter.ui.table_caption')) ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?= $this->e($this->t('plugin::prime-starter.fields.title')) ?></th>
                        <th scope="col"><?= $this->e($this->t('plugin::prime-starter.fields.visibility')) ?></th>
                        <th scope="col"><?= $this->e($this->t('plugin::prime-starter.fields.updated_at')) ?></th>
                        <th scope="col" class="pc-table__actions"><span class="pc-sr-only"><?= $this->e($this->t('plugin::prime-starter.ui.actions')) ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($notes as $note): ?>
                        <tr>
                            <td data-label="<?= $this->e($this->t('plugin::prime-starter.fields.title')) ?>">
                                <span class="ps-row-title">
                                    <span class="ps-swatch ps-swatch--<?= $this->e($note['color']) ?>" aria-hidden="true"></span>
                                    <a class="pc-text-strong" href="/admin/prime-starter/notes/<?= $this->e((string) $note['id']) ?>/edit"><?= $this->e($note['title']) ?></a>
                                    <?php if ($note['pinned']): ?>
                                        <?= $this->raw($this->insert('admin::components/badge', ['label' => $this->t('plugin::prime-starter.fields.pinned'), 'variant' => 'amber'])) ?>
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td data-label="<?= $this->e($this->t('plugin::prime-starter.fields.visibility')) ?>">
                                <?= $this->raw($this->insert('admin::components/badge', $note['public']
                                    ? ['label' => $this->t('plugin::prime-starter.fields.public'), 'variant' => 'success']
                                    : ['label' => $this->t('plugin::prime-starter.fields.private'), 'variant' => 'neutral'])) ?>
                            </td>
                            <td data-label="<?= $this->e($this->t('plugin::prime-starter.fields.updated_at')) ?>">
                                <time datetime="<?= $this->e(str_replace(' ', 'T', $note['updated_at']) . 'Z') ?>"><?= $this->e($this->formatDateTime($note['updated_at'] . ' UTC')) ?></time>
                            </td>
                            <td class="pc-table__actions">
                                <div class="pc-table__row-actions">
                                    <a class="pc-btn pc-btn--ghost pc-btn--sm" href="/admin/prime-starter/notes/<?= $this->e((string) $note['id']) ?>/edit"
                                       aria-label="<?= $this->e($this->t('plugin::prime-starter.ui.edit_named', ['title' => $note['title']])) ?>"><?= $this->e($this->t('core.actions.edit')) ?></a>
                                    <form method="post" action="/admin/prime-starter/notes/<?= $this->e((string) $note['id']) ?>/delete" class="pc-inline-form"
                                          data-prime-confirm-form="<?= $this->e($this->t('plugin::prime-starter.ui.confirm_delete', ['title' => $note['title']])) ?>">
                                        <?= $this->raw($this->csrfField()) ?>
                                        <button type="submit" class="pc-btn pc-btn--ghost pc-btn--sm pc-text-danger"
                                                aria-label="<?= $this->e($this->t('plugin::prime-starter.ui.delete_named', ['title' => $note['title']])) ?>"><?= $this->e($this->t('core.actions.delete')) ?></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php $this->stop(); ?>
