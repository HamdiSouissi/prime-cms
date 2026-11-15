<?php

declare(strict_types=1);

/**
 * Inline alert banner component.
 *
 * @var \Prime\View\TemplateContext $this
 * @var string|null $variant info|success|warning|danger
 * @var string|null $title
 * @var string $message
 */
$variant = (string) ($variant ?? 'info');
$alertTitle = isset($title) && is_string($title) && $title !== '' ? $title : null;
$iconMap = [
    'info' => 'info',
    'success' => 'check-circle',
    'warning' => 'alert-triangle',
    'danger' => 'alert-circle',
];
$iconName = $iconMap[$variant] ?? 'info';
$role = $variant === 'danger' ? 'alert' : 'status';
?>
<div class="pc-alert pc-alert--<?= $this->e($variant) ?>" role="<?= $this->e($role) ?>">
    <span class="pc-alert__icon" aria-hidden="true">
        <?= $this->raw($this->icon($iconName, 18)) ?>
    </span>
    <div class="pc-alert__body">
        <?php if ($alertTitle !== null): ?>
            <p class="pc-alert__title"><?= $this->e($alertTitle) ?></p>
        <?php endif; ?>
        <p class="pc-alert__text"><?= $this->e($message) ?></p>
    </div>
</div>
