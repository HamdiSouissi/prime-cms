<?php

declare(strict_types=1);

/**
 * Jobs table (pending or failed).
 *
 * @var \Prime\View\TemplateContext $this
 * @var list<array{id: int, queue: string, handler: string, attempts: int, max_attempts: int, available_at: string, reserved: bool, failed_at: ?string, error: ?string, created_at: string}> $jobs
 * @var bool $failed
 */
?>
<div class="pc-table-wrap">
    <table class="pc-table">
        <thead>
            <tr>
                <th scope="col">#</th>
                <th scope="col"><?= $this->e($this->t('admin.tools.queue.columns.handler')) ?></th>
                <th scope="col"><?= $this->e($this->t('admin.tools.queue.columns.attempts')) ?></th>
                <th scope="col"><?= $this->e($this->t($failed ? 'admin.tools.queue.columns.failed_at' : 'admin.tools.queue.columns.available_at')) ?></th>
                <th scope="col"><span class="sr-only"><?= $this->e($this->t('admin.mail.columns.actions')) ?></span></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($jobs as $job): ?>
                <tr>
                    <td class="pc-mono pc-text-sm"><?= $this->e((string) $job['id']) ?></td>
                    <td>
                        <span class="pc-mono pc-text-sm pc-text-strong"><?= $this->e($job['handler']) ?></span>
                        <span class="pc-field__help pc-help-block"><?= $this->e($this->t('admin.tools.queue.on_queue', ['queue' => $job['queue']])) ?><?= $job['reserved'] ? ' · ' . $this->e($this->t('admin.tools.queue.running')) : '' ?></span>
                        <?php if ($job['error'] !== null && $job['error'] !== ''): ?>
                            <span class="pc-field__help pc-mail-error"><?= $this->e(mb_strimwidth($job['error'], 0, 240, '…')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="pc-text-sm"><?= $this->e($job['attempts'] . ' / ' . $job['max_attempts']) ?></td>
                    <td class="pc-text-sm pc-nowrap"><?= $this->e(format_datetime((string) ($failed ? $job['failed_at'] : $job['available_at']) . ' UTC')) ?></td>
                    <td class="pc-table__actions">
                        <div class="pc-row pc-row--sm">
                            <?php if ($failed): ?>
                                <form method="post" action="/admin/tools/queue/<?= $this->e((string) $job['id']) ?>/retry">
                                    <?= $this->raw($this->csrfField()) ?>
                                    <button type="submit" class="pc-btn pc-btn--secondary pc-btn--sm">
                                        <?= $this->raw($this->icon('rotate-ccw', 14)) ?>
                                        <span><?= $this->e($this->t('admin.tools.queue.retry')) ?></span>
                                    </button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="/admin/tools/queue/<?= $this->e((string) $job['id']) ?>/delete" data-prime-confirm-form="<?= $this->e($this->t('admin.tools.queue.delete_confirm', ['id' => $job['id']])) ?>">
                                <?= $this->raw($this->csrfField()) ?>
                                <button type="submit" class="pc-btn pc-btn--danger-outline pc-btn--sm" aria-label="<?= $this->e($this->t('admin.tools.queue.delete_label', ['id' => $job['id']])) ?>">
                                    <?= $this->raw($this->icon('trash', 14)) ?>
                                    <span><?= $this->e($this->t('admin.tools.queue.delete')) ?></span>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
