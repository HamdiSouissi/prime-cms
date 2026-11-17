<?php

declare(strict_types=1);

/**
 * Sub-navigation shared by the « Outils » screens.
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $activeTab
 * @var int $failedCount
 */
$links = [
    'scheduler' => ['/admin/tools/scheduler', 'clock'],
    'queue' => ['/admin/tools/queue', 'layers'],
    'system' => ['/admin/tools/system', 'activity'],
];
?>
<nav class="pc-subnav" aria-label="<?= $this->e($this->t('admin.tools.title')) ?>">
    <?php foreach ($links as $tab => [$url, $icon]): ?>
        <a class="pc-subnav__link" href="<?= $this->e($url) ?>"<?= $activeTab === $tab ? ' aria-current="page"' : '' ?>>
            <?= $this->raw($this->icon($icon, 16)) ?>
            <span><?= $this->e($this->t('admin.tools.tabs.' . $tab)) ?></span>
            <?php if ($tab === 'queue' && ($failedCount ?? 0) > 0): ?>
                <span class="pc-subnav__count" aria-label="<?= $this->e($this->choice('admin.tools.queue.failed_count', (int) $failedCount)) ?>"><?= $this->e((string) $failedCount) ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>
