<?php

declare(strict_types=1);

/**
 * Skeleton loading placeholder.
 *
 * @var \Prime\View\TemplateContext $this
 * @var string|null $variant line|title|short|circle|card
 */
$variant = (string) ($variant ?? 'line');
?>
<span class="pc-skeleton pc-skeleton--<?= $this->e($variant) ?>" aria-hidden="true"></span>
