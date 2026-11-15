<?php

declare(strict_types=1);

/**
 * Badge / status pill component supporting all vibrant accents.
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $label
 * @var string|null $variant neutral|brand|violet|teal|sky|amber|success|danger
 * @var bool|null $dot
 * @var bool|null $pill
 */
$variant = (string) ($variant ?? 'neutral');
$showDot = (bool) ($dot ?? false);
$isPill = (bool) ($pill ?? true);
?>
<span class="pc-badge pc-badge--<?= $this->e($variant) ?><?= $isPill ? ' pc-badge--pill' : '' ?>">
    <?php if ($showDot): ?>
        <span class="pc-badge__dot" aria-hidden="true"></span>
    <?php endif; ?>
    <span><?= $this->e($label) ?></span>
</span>
