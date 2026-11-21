<?php

declare(strict_types=1);

/**
 * Create / edit a note.
 *
 * @var \Prime\View\TemplateContext $this
 * @var int|null $noteId
 * @var array{title: string, body: string, color: string, pinned: bool, public: bool} $values
 * @var array<string, string> $errors
 * @var list<string> $colors
 * @var string $action
 * @var string $pageHeading
 * @var list<array{label: string, url: ?string}> $breadcrumbs
 */
$this->layout('admin::layouts/app');

$colorOptions = [];
foreach ($colors as $color) {
    $colorOptions[$color] = $this->t('plugin::prime-starter.colors.' . $color);
}
?>
<?php $this->start('content'); ?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => $pageHeading,
    'breadcrumbs' => $breadcrumbs,
])) ?>

<?php if ($errors !== []): ?>
    <?= $this->raw($this->insert('admin::components/alert', [
        'variant' => 'danger',
        'title' => $this->t('plugin::prime-starter.validation.summary'),
        'message' => implode(' ', $errors),
    ])) ?>
<?php endif; ?>

<form method="post" action="<?= $this->e($action) ?>" class="pc-card ps-form" novalidate>
    <?= $this->raw($this->csrfField()) ?>
    <div class="pc-stack pc-stack--md">
        <?= $this->raw($this->insert('admin::components/field', [
            'id' => 'ps-title',
            'name' => 'title',
            'label' => $this->t('plugin::prime-starter.fields.title'),
            'value' => $values['title'],
            'required' => true,
            'maxlength' => 160,
            'autofocus' => $noteId === null,
            'error' => $errors['title'] ?? null,
        ])) ?>
        <?= $this->raw($this->insert('admin::components/field', [
            'id' => 'ps-body',
            'name' => 'body',
            'type' => 'textarea',
            'label' => $this->t('plugin::prime-starter.fields.body'),
            'value' => $values['body'],
            'help' => $this->t('plugin::prime-starter.fields.body_help'),
            'error' => $errors['body'] ?? null,
        ])) ?>
        <?= $this->raw($this->insert('admin::components/field', [
            'id' => 'ps-color',
            'name' => 'color',
            'type' => 'select',
            'label' => $this->t('plugin::prime-starter.fields.color'),
            'value' => $values['color'],
            'options' => $colorOptions,
        ])) ?>
        <input type="hidden" name="pinned" value="0">
        <?= $this->raw($this->insert('admin::components/choice', [
            'id' => 'ps-pinned',
            'name' => 'pinned',
            'type' => 'switch',
            'label' => $this->t('plugin::prime-starter.fields.pinned'),
            'description' => $this->t('plugin::prime-starter.fields.pinned_help'),
            'checked' => $values['pinned'],
        ])) ?>
        <input type="hidden" name="public" value="0">
        <?= $this->raw($this->insert('admin::components/choice', [
            'id' => 'ps-public',
            'name' => 'public',
            'type' => 'switch',
            'label' => $this->t('plugin::prime-starter.fields.public'),
            'description' => $this->t('plugin::prime-starter.fields.public_help'),
            'checked' => $values['public'],
        ])) ?>
        <div class="pc-form-actions">
            <button type="submit" class="pc-btn pc-btn--primary"><?= $this->e($noteId === null ? $this->t('plugin::prime-starter.ui.create') : $this->t('plugin::prime-starter.ui.save')) ?></button>
            <a href="/admin/prime-starter" class="pc-btn pc-btn--ghost"><?= $this->e($this->t('core.actions.cancel')) ?></a>
        </div>
    </div>
</form>

<?php $this->stop(); ?>
