<?php

declare(strict_types=1);

/**
 * Reusable button / link-button component.
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $label
 * @var string|null $variant primary|secondary|ghost|danger
 * @var string|null $size sm|md|lg
 * @var string|null $icon Lucide icon name
 * @var bool|null $iconOnly
 * @var string|null $href Render as `<a>` when provided
 * @var string|null $type Button type when not a link
 * @var bool|null $disabled
 * @var bool|null $loading
 * @var string|null $tooltip
 * @var string|null $class
 * @var array<string, string>|null $attrs Extra HTML attributes (escaped)
 */
$variant = (string) ($variant ?? 'secondary');
$size = (string) ($size ?? 'md');
$iconName = isset($icon) && is_string($icon) && $icon !== '' ? $icon : null;
$isIconOnly = (bool) ($iconOnly ?? false);
$hrefUrl = isset($href) && is_string($href) && $href !== '' ? $href : null;
$buttonType = (string) ($type ?? 'button');
$isDisabled = (bool) ($disabled ?? false);
$isLoading = (bool) ($loading ?? false);
$tooltipText = isset($tooltip) && is_string($tooltip) && $tooltip !== '' ? $tooltip : ($isIconOnly ? $label : null);
$extraClass = (string) ($class ?? '');
$extraAttrs = is_array($attrs ?? null) ? $attrs : [];

$classes = ['pc-btn', 'pc-btn--' . $variant];
if ($size === 'sm' || $size === 'lg') {
    $classes[] = 'pc-btn--' . $size;
}
if ($isIconOnly) {
    $classes[] = 'pc-btn--icon';
}
if ($isLoading) {
    $classes[] = 'is-loading';
}
if ($extraClass !== '') {
    $classes[] = $extraClass;
}

$attrPairs = ['class="' . $this->e(implode(' ', $classes)) . '"'];
if ($isIconOnly) {
    $attrPairs[] = 'aria-label="' . $this->e($label) . '"';
}
if ($tooltipText !== null) {
    $attrPairs[] = 'data-tooltip="' . $this->e($tooltipText) . '"';
}
if ($isLoading) {
    $attrPairs[] = 'aria-busy="true"';
}
foreach ($extraAttrs as $attrKey => $attrVal) {
    if (!is_string($attrKey) || !preg_match('/^[a-zA-Z0-9_-]+$/', $attrKey)) {
        continue;
    }
    $attrPairs[] = $attrKey . '="' . $this->e((string) $attrVal) . '"';
}

$iconSize = $size === 'sm' ? 16 : 18;
$attrString = implode(' ', $attrPairs);
?>
<?php if ($hrefUrl !== null): ?>
<a href="<?= $this->e($hrefUrl) ?>" <?= $this->raw($attrString) ?><?= $isDisabled ? ' aria-disabled="true" tabindex="-1"' : '' ?>>
    <?php if ($isLoading): ?>
        <span class="pc-btn__spinner" aria-hidden="true"></span>
    <?php elseif ($iconName !== null): ?>
        <?= $this->raw($this->icon($iconName, $iconSize)) ?>
    <?php endif; ?>
    <?php if (!$isIconOnly): ?>
        <span><?= $this->e($label) ?></span>
    <?php endif; ?>
</a>
<?php else: ?>
<button type="<?= $this->e($buttonType) ?>" <?= $this->raw($attrString) ?><?= ($isDisabled || $isLoading) ? ' disabled' : '' ?>>
    <?php if ($isLoading): ?>
        <span class="pc-btn__spinner" aria-hidden="true"></span>
    <?php elseif ($iconName !== null): ?>
        <?= $this->raw($this->icon($iconName, $iconSize)) ?>
    <?php endif; ?>
    <?php if (!$isIconOnly): ?>
        <span><?= $this->e($label) ?></span>
    <?php endif; ?>
</button>
<?php endif; ?>
