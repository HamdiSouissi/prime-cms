<?php

declare(strict_types=1);

/**
 * Accessible horizontal progress bar.
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $label
 * @var int $value 0..100
 * @var string|null $accent brand|teal|violet|sky|amber|success|danger
 */
$percent = max(0, min(100, (int) ($value ?? 0)));
$accent = (string) ($accent ?? 'brand');
$barModifier = in_array($accent, ['teal', 'violet', 'sky', 'amber', 'success', 'danger'], true)
    ? ' pc-progress__bar--' . $accent
    : '';
?>
<div class="pc-progress">
    <div class="pc-progress__meta">
        <span class="pc-progress__label"><?= $this->e($label) ?></span>
        <span><?= $this->e((string) $percent) ?> %</span>
    </div>
    <div
        class="pc-progress__track"
        role="progressbar"
        aria-label="<?= $this->e($label) ?>"
        aria-valuenow="<?= $this->e((string) $percent) ?>"
        aria-valuemin="0"
        aria-valuemax="100"
    >
        <div class="pc-progress__bar<?= $this->e($barModifier) ?>" style="width: <?= $this->e((string) $percent) ?>%"></div>
    </div>
</div>
