<?php

declare(strict_types=1);

/**
 * Empty state block with tinted icon tile, title, helper text, and optional CTA.
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $icon
 * @var string|null $accent brand|violet|teal|sky|amber|success|neutral
 * @var string $title
 * @var string $description
 * @var string|null $actionLabel
 * @var string|null $actionUrl
 * @var string|null $secondaryLabel
 * @var string|null $secondaryUrl
 */
$accent = (string) ($accent ?? 'brand');
$ctaLabel = isset($actionLabel) && is_string($actionLabel) && $actionLabel !== '' ? $actionLabel : null;
$ctaUrl = isset($actionUrl) && is_string($actionUrl) && $actionUrl !== '' ? $actionUrl : null;
$secLabel = isset($secondaryLabel) && is_string($secondaryLabel) && $secondaryLabel !== '' ? $secondaryLabel : null;
$secUrl = isset($secondaryUrl) && is_string($secondaryUrl) && $secondaryUrl !== '' ? $secondaryUrl : null;
?>
<div class="pc-empty">
    <span class="pc-empty__icon pc-accent-tile--<?= $this->e($accent) ?>" aria-hidden="true">
        <?= $this->raw($this->icon($icon, 26)) ?>
    </span>
    <h2 class="pc-empty__title"><?= $this->e($title) ?></h2>
    <p class="pc-empty__description"><?= $this->e($description) ?></p>
    <?php if ($ctaLabel !== null || $secLabel !== null): ?>
        <div class="pc-empty__actions">
            <?php if ($ctaLabel !== null && $ctaUrl !== null): ?>
                <?= $this->raw($this->insert('admin::components/button', [
                    'label' => $ctaLabel,
                    'variant' => 'primary',
                    'href' => $ctaUrl,
                ])) ?>
            <?php endif; ?>
            <?php if ($secLabel !== null && $secUrl !== null): ?>
                <?= $this->raw($this->insert('admin::components/button', [
                    'label' => $secLabel,
                    'variant' => 'secondary',
                    'href' => $secUrl,
                ])) ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
