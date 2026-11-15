<?php

declare(strict_types=1);

/**
 * Accessible breadcrumb navigation.
 *
 * @var \Prime\View\TemplateContext $this
 * @var list<array{label: string, url: ?string}> $items
 */
$crumbItems = is_array($items ?? null) ? array_values($items) : [];
$lastIndex = count($crumbItems) - 1;
?>
<?php if ($crumbItems !== []): ?>
<nav class="pc-breadcrumb" aria-label="Fil d’Ariane">
    <ol class="pc-breadcrumb__list">
        <?php foreach ($crumbItems as $index => $crumb): ?>
            <li class="pc-breadcrumb__item">
                <?php if ($index > 0): ?>
                    <span class="pc-breadcrumb__sep" aria-hidden="true">
                        <?= $this->raw($this->icon('chevron-right', 14)) ?>
                    </span>
                <?php endif; ?>
                <?php if ($index === $lastIndex || empty($crumb['url'])): ?>
                    <span class="pc-breadcrumb__current" aria-current="page"><?= $this->e($crumb['label']) ?></span>
                <?php else: ?>
                    <a class="pc-breadcrumb__link" href="<?= $this->e((string) $crumb['url']) ?>"><?= $this->e($crumb['label']) ?></a>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</nav>
<?php endif; ?>
