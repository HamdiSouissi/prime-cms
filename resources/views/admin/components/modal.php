<?php

declare(strict_types=1);

/**
 * Accessible native `<dialog>` modal component.
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $id
 * @var string $title
 * @var string $body Trusted HTML content
 * @var string|null $footer Trusted HTML footer buttons
 * @var string|null $size sm|md|lg
 */
$size = (string) ($size ?? 'md');
$sizeClass = in_array($size, ['sm', 'lg'], true) ? ' pc-modal--' . $size : '';
$footerHtml = isset($footer) && is_string($footer) && $footer !== '' ? $footer : null;
?>
<dialog id="<?= $this->e($id) ?>" class="pc-modal<?= $this->e($sizeClass) ?>" aria-labelledby="<?= $this->e($id) ?>-title">
    <header class="pc-modal__header">
        <h2 class="pc-modal__title" id="<?= $this->e($id) ?>-title"><?= $this->e($title) ?></h2>
        <button type="button" class="pc-btn pc-btn--ghost pc-btn--icon pc-btn--sm" data-prime-modal-close aria-label="<?= t('admin.attr.fermer_la_fenetre') ?>">
            <?= $this->raw($this->icon('x', 18)) ?>
        </button>
    </header>
    <div class="pc-modal__body">
        <?= $this->raw($body) ?>
    </div>
    <?php if ($footerHtml !== null): ?>
        <footer class="pc-modal__footer">
            <?= $this->raw($footerHtml) ?>
        </footer>
    <?php endif; ?>
</dialog>
