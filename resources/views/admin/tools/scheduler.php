<?php

declare(strict_types=1);

/**
 * « Outils › Tâches planifiées ».
 *
 * @var \Prime\View\TemplateContext $this
 * @var list<array{name: string, description: string, expression: string, owner: string, last_run: ?int, next_run: ?int, error: ?string}> $tasks
 * @var ?int $heartbeat
 * @var bool $cronStale
 * @var int $now
 * @var array<string, \Prime\Settings\SettingsField> $schedulerFields
 * @var string $basePath
 * @var list<array{label: string, url: ?string}> $breadcrumbs
 */


$this->layout('admin::layouts/app');
$formatter = locale_formatter();
?>
<?php $this->start('content'); ?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => $this->t('admin.tools.title'),
    'subtitle' => $this->t('admin.tools.subtitle'),
    'breadcrumbs' => $breadcrumbs,
])) ?>

<?= $this->raw($this->insert('admin::tools/_nav', ['activeTab' => 'scheduler', 'failedCount' => 0])) ?>

<div class="pc-tools-stack">
    <?php if ($cronStale): ?>
        <div class="pc-alert pc-alert--warning" role="status">
            <?= $this->raw($this->icon('alert-triangle', 18)) ?>
            <div>
                <p>
                    <?= $this->e($heartbeat === null
                        ? $this->t('admin.tools.scheduler.never_ran')
                        : $this->t('admin.tools.scheduler.stale', ['ago' => $formatter->relative($heartbeat, $now)])) ?>
                </p>
                <code class="pc-cron-line">* * * * * cd <?= $this->e($basePath) ?> &amp;&amp; php bin/prime schedule:run &gt;&gt; /dev/null 2&gt;&amp;1</code>
            </div>
        </div>
    <?php else: ?>
        <div class="pc-alert pc-alert--success" role="status">
            <?= $this->raw($this->icon('check-circle', 18)) ?>
            <p><?= $this->e($this->t('admin.tools.scheduler.healthy', ['ago' => $formatter->relative((int) $heartbeat, $now)])) ?></p>
        </div>
    <?php endif; ?>

    <section class="pc-card" aria-labelledby="tasks-title">
        <header class="pc-card__header">
            <div>
                <h2 class="pc-card__title" id="tasks-title"><?= $this->e($this->t('admin.tools.scheduler.tasks_title')) ?></h2>
                <p class="pc-card__subtitle"><?= $this->e($this->choice('admin.tools.scheduler.tasks_count', count($tasks))) ?></p>
            </div>
        </header>
        <div class="pc-card__body pc-card__body--flush">
            <?php if ($tasks === []): ?>
                <p class="pc-empty-inline"><?= $this->e($this->t('admin.tools.scheduler.empty')) ?></p>
            <?php else: ?>
                <div class="pc-table-wrap">
                    <table class="pc-table">
                        <thead>
                            <tr>
                                <th scope="col"><?= $this->e($this->t('admin.tools.scheduler.columns.task')) ?></th>
                                <th scope="col"><?= $this->e($this->t('admin.tools.scheduler.columns.frequency')) ?></th>
                                <th scope="col"><?= $this->e($this->t('admin.tools.scheduler.columns.last_run')) ?></th>
                                <th scope="col"><?= $this->e($this->t('admin.tools.scheduler.columns.next_run')) ?></th>
                                <th scope="col"><?= $this->e($this->t('admin.tools.scheduler.columns.status')) ?></th>
                                <th scope="col"><span class="sr-only"><?= $this->e($this->t('admin.mail.columns.actions')) ?></span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tasks as $task): ?>
                                <tr>
                                    <td>
                                        <span class="pc-text-strong pc-mono"><?= $this->e($task['name']) ?></span>
                                        <?php if ($task['description'] !== ''): ?>
                                            <span class="pc-field__help pc-help-block"><?= $this->e($task['description']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><code class="pc-mono"><?= $this->e($task['expression']) ?></code></td>
                                    <td class="pc-text-sm pc-nowrap">
                                        <?= $task['last_run'] !== null ? $this->e($formatter->relative($task['last_run'], $now)) : '<span class="pc-text-muted">' . $this->e($this->t('admin.tools.scheduler.never')) . '</span>' ?>
                                    </td>
                                    <td class="pc-text-sm pc-nowrap">
                                        <?= $task['next_run'] !== null ? $this->e($formatter->relative($task['next_run'], $now)) : '—' ?>
                                    </td>
                                    <td>
                                        <?php if ($task['error'] !== null): ?>
                                            <span class="pc-badge pc-badge--danger pc-badge--pill" title="<?= $this->e($task['error']) ?>">
                                                <span class="pc-badge__dot" aria-hidden="true"></span><?= $this->e($this->t('admin.tools.scheduler.status_error')) ?>
                                            </span>
                                            <span class="pc-field__help pc-mail-error"><?= $this->e($task['error']) ?></span>
                                        <?php elseif ($task['last_run'] !== null): ?>
                                            <span class="pc-badge pc-badge--success pc-badge--pill"><span class="pc-badge__dot" aria-hidden="true"></span><?= $this->e($this->t('admin.tools.scheduler.status_ok')) ?></span>
                                        <?php else: ?>
                                            <span class="pc-badge pc-badge--neutral pc-badge--pill"><?= $this->e($this->t('admin.tools.scheduler.status_waiting')) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="pc-table__actions">
                                        <form method="post" action="/admin/tools/scheduler/<?= $this->e(rawurlencode($task['name'])) ?>/run">
                                            <?= $this->raw($this->csrfField()) ?>
                                            <button type="submit" class="pc-btn pc-btn--secondary pc-btn--sm" aria-label="<?= $this->e($this->t('admin.tools.scheduler.run_now_label', ['name' => $task['name']])) ?>">
                                                <?= $this->raw($this->icon('arrow-right', 14)) ?>
                                                <span><?= $this->e($this->t('admin.tools.scheduler.run')) ?></span>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($schedulerFields !== []): ?>
        <form method="post" action="/admin/tools/scheduler/settings" class="pc-card" aria-labelledby="scheduler-settings-title">
            <?= $this->raw($this->csrfField()) ?>
            <header class="pc-card__header">
                <h2 class="pc-card__title" id="scheduler-settings-title"><?= $this->e($this->t('admin.tools.scheduler.settings_title')) ?></h2>
            </header>
            <div class="pc-card__body pc-settings-grid">
                <?php foreach ($schedulerFields as $name => $field): ?>
                    <?= $this->raw($this->insert('admin::settings/partials/field', [
                        'field' => $field,
                        'value' => setting($name, $field->default),
                        'options' => $field->options,
                        'error' => null,
                        'secretSet' => false,
                    ])) ?>
                <?php endforeach; ?>
            </div>
            <footer class="pc-card__footer">
                <span></span>
                <button type="submit" class="pc-btn pc-btn--primary pc-btn--sm">
                    <?= $this->raw($this->icon('save', 14)) ?>
                    <span><?= $this->e($this->t('admin.settings.save')) ?></span>
                </button>
            </footer>
        </form>
    <?php endif; ?>
</div>

<?php $this->stop(); ?>
