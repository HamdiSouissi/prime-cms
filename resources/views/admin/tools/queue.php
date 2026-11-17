<?php

declare(strict_types=1);

/**
 * « Outils › File d'attente ».
 *
 * @var \Prime\View\TemplateContext $this
 * @var list<array{id: int, queue: string, handler: string, attempts: int, max_attempts: int, available_at: string, reserved: bool, failed_at: ?string, error: ?string, created_at: string}> $pendingJobs
 * @var list<array{id: int, queue: string, handler: string, attempts: int, max_attempts: int, available_at: string, reserved: bool, failed_at: ?string, error: ?string, created_at: string}> $failedJobs
 * @var array{pending: int, reserved: int, failed: int, delayed: int, oldest_pending: ?string} $queueStats
 * @var bool $queueAvailable
 * @var list<array{label: string, url: ?string}> $breadcrumbs
 */
$this->layout('admin::layouts/app');
?>
<?php $this->start('content'); ?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => $this->t('admin.tools.title'),
    'subtitle' => $this->t('admin.tools.subtitle'),
    'breadcrumbs' => $breadcrumbs,
])) ?>

<?= $this->raw($this->insert('admin::tools/_nav', ['activeTab' => 'queue', 'failedCount' => $queueStats['failed']])) ?>

<div class="pc-tools-stack">
    <?php if (!$queueAvailable): ?>
        <div class="pc-alert pc-alert--danger" role="alert">
            <?= $this->raw($this->icon('alert-triangle', 18)) ?>
            <p><?= $this->e($this->t('admin.tools.queue.unavailable')) ?></p>
        </div>
    <?php endif; ?>

    <div class="pc-grid pc-grid--stats">
        <?php foreach (['pending' => 'sky', 'delayed' => 'amber', 'reserved' => 'violet', 'failed' => 'danger'] as $key => $accent): ?>
            <?= $this->raw($this->insert('admin::components/stat-tile', [
                'label' => $this->t('admin.tools.queue.stats.' . $key),
                'value' => (string) $queueStats[$key],
                'icon' => $key === 'failed' ? 'alert-triangle' : ($key === 'delayed' ? 'clock' : 'layers'),
                'accent' => $accent,
            ])) ?>
        <?php endforeach; ?>
    </div>

    <section class="pc-card" aria-labelledby="failed-jobs-title">
        <header class="pc-card__header">
            <div>
                <h2 class="pc-card__title" id="failed-jobs-title"><?= $this->e($this->t('admin.tools.queue.failed_title')) ?></h2>
                <p class="pc-card__subtitle"><?= $this->e($this->t('admin.tools.queue.failed_subtitle')) ?></p>
            </div>
            <?php if ($failedJobs !== []): ?>
                <form method="post" action="/admin/tools/queue/retry-all">
                    <?= $this->raw($this->csrfField()) ?>
                    <button type="submit" class="pc-btn pc-btn--secondary pc-btn--sm">
                        <?= $this->raw($this->icon('rotate-ccw', 14)) ?>
                        <span><?= $this->e($this->t('admin.tools.queue.retry_all')) ?></span>
                    </button>
                </form>
            <?php endif; ?>
        </header>
        <div class="pc-card__body pc-card__body--flush">
            <?php if ($failedJobs === []): ?>
                <p class="pc-empty-inline"><?= $this->e($this->t('admin.tools.queue.failed_empty')) ?></p>
            <?php else: ?>
                <?= $this->raw($this->insert('admin::tools/_jobs', ['jobs' => $failedJobs, 'failed' => true])) ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="pc-card" aria-labelledby="pending-jobs-title">
        <header class="pc-card__header">
            <div>
                <h2 class="pc-card__title" id="pending-jobs-title"><?= $this->e($this->t('admin.tools.queue.pending_title')) ?></h2>
                <?php if ($queueStats['oldest_pending'] !== null): ?>
                    <p class="pc-card__subtitle"><?= $this->e($this->t('admin.tools.queue.oldest', ['date' => format_datetime($queueStats['oldest_pending'] . ' UTC')])) ?></p>
                <?php endif; ?>
            </div>
        </header>
        <div class="pc-card__body pc-card__body--flush">
            <?php if ($pendingJobs === []): ?>
                <p class="pc-empty-inline"><?= $this->e($this->t('admin.tools.queue.pending_empty')) ?></p>
            <?php else: ?>
                <?= $this->raw($this->insert('admin::tools/_jobs', ['jobs' => $pendingJobs, 'failed' => false])) ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php $this->stop(); ?>
