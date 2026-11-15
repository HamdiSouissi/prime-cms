<?php

declare(strict_types=1);

/**
 * User avatar (image or initials) in sm / md / lg sizes.
 *
 * @var \Prime\View\TemplateContext $this
 * @var string|null $initials
 * @var string|null $src
 * @var string|null $alt
 * @var string|null $size sm|md|lg
 * @var string|null $accent brand|violet|teal|sky
 */
$size = (string) ($size ?? 'md');
$accent = (string) ($accent ?? 'brand');
$imgSrc = isset($src) && is_string($src) && $src !== '' ? $src : null;
$imgAlt = (string) ($alt ?? '');
$letters = (string) ($initials ?? 'PC');
$accentClass = in_array($accent, ['violet', 'teal', 'sky'], true) ? ' pc-avatar--' . $accent : '';
?>
<span class="pc-avatar pc-avatar--<?= $this->e($size) . $this->e($accentClass) ?>"<?= $imgSrc === null ? ' aria-hidden="true"' : '' ?>>
    <?php if ($imgSrc !== null): ?>
        <img src="<?= $this->e($imgSrc) ?>" alt="<?= $this->e($imgAlt) ?>" loading="lazy">
    <?php else: ?>
        <?= $this->e($letters) ?>
    <?php endif; ?>
</span>
