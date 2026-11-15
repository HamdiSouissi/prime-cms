<?php

declare(strict_types=1);

/**
 * Accessible dropdown component.
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $id
 * @var string $triggerHtml Trusted trigger button HTML (must include `data-prime-dropdown-trigger`)
 * @var string $menuHtml Trusted menu body HTML
 * @var string|null $align right|left
 * @var string|null $menuLabel Accessible label for the menu
 */
$align = (string) ($align ?? 'right');
$menuLabelText = (string) ($menuLabel ?? 'Actions');
?>
<div class="pc-dropdown" data-prime-dropdown>
    <?= $this->raw($triggerHtml) ?>
    <div
        id="<?= $this->e($id) ?>"
        class="pc-dropdown__menu<?= $align === 'left' ? ' pc-dropdown__menu--left' : '' ?>"
        role="menu"
        aria-label="<?= $this->e($menuLabelText) ?>"
        data-prime-dropdown-menu
        hidden
    >
        <?= $this->raw($menuHtml) ?>
    </div>
</div>
