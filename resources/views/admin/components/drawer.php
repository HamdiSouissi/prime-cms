<?php

declare(strict_types=1);

/**
 * Accessible slide-over side drawer panel.
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $id
 * @var string $title
 * @var string $body Trusted HTML content
 * @var string|null $footer Trusted HTML footer buttons
 */
$footerHtml = isset($footer) && is_string($footer) && $footer !== '' ? $footer : null;
?>
<div
    id="<?= $this->e($id) ?>"
    class="pc-drawer"
    role="dialog"
    aria-modal="true"
    aria-labelledby="<?= $this->e($id) ?>-title"
    data-prime-drawer
    hidden
>
    <div class="pc-drawer__backdrop" data-prime-drawer-close aria-hidden="true"></div>
    <div class="pc-drawer__panel" data-prime-drawer-panel>
        <header class="pc-drawer__header">
            <h2 class="pc-drawer__title" id="<?= $this->e($id) ?>-title"><?= $this->e($title) ?></h2>
            <button type="button" class="pc-btn pc-btn--ghost pc-btn--icon pc-btn--sm" data-prime-drawer-close aria-label="<?= t('admin.attr.fermer_le_panneau') ?>">
                <?= $this->raw($this->icon('x', 18)) ?>
            </button>
        </header>
        <div class="pc-drawer__body">
            <?= $this->raw($body) ?>
        </div>
        <?php if ($footerHtml !== null): ?>
            <footer class="pc-drawer__footer">
                <?= $this->raw($footerHtml) ?>
            </footer>
        <?php endif; ?>
    </div>
</div>
