<?php

declare(strict_types=1);

/**
 * « Outils › Mises à jour ».
 *
 * @var \Prime\View\TemplateContext $this
 * @var array{installed: string, code: string, pending: list<array{source: string, migration: string}>, last_run: ?array<string, mixed>, mysqldump: bool} $status
 * @var bool $upToDate
 * @var list<array{label: string, url: ?string}> $breadcrumbs
 */
$this->layout('admin::layouts/app');
$last = $status['last_run'];
?>
<?php $this->start('content'); ?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => $this->t('admin.tools.title'),
    'subtitle' => $this->t('admin.tools.subtitle'),
    'breadcrumbs' => $breadcrumbs,
])) ?>

<?= $this->raw($this->insert('admin::tools/_nav', ['activeTab' => 'updates', 'failedCount' => 0])) ?>

<div class="pc-tools-stack">
    <section class="pc-card" aria-labelledby="updates-state">
        <header class="pc-card__header">
            <div>
                <h2 class="pc-card__title" id="updates-state"><?= $this->e($this->t('admin.updates.state_title')) ?></h2>
                <p class="pc-card__subtitle"><?= $this->e($this->t('admin.updates.state_help')) ?></p>
            </div>
            <?= $this->raw($this->insert('admin::components/badge', [
                'label' => $upToDate ? $this->t('admin.updates.up_to_date') : $this->t('admin.updates.needed'),
                'variant' => $upToDate ? 'success' : 'amber',
            ])) ?>
        </header>
        <div class="pc-card__body">
            <dl class="pc-kv-list">
                <div class="pc-kv-row"><dt><?= $this->e($this->t('admin.updates.installed')) ?></dt><dd><code class="pc-mono"><?= $this->e($status['installed']) ?></code></dd></div>
                <div class="pc-kv-row"><dt><?= $this->e($this->t('admin.updates.code')) ?></dt><dd><code class="pc-mono"><?= $this->e($status['code']) ?></code></dd></div>
                <div class="pc-kv-row"><dt><?= $this->e($this->t('admin.updates.last_run')) ?></dt><dd><?= $this->e(is_array($last)
                    ? $this->t('admin.updates.last_run_value', ['date' => $this->formatDateTime((string) ($last['at'] ?? '') . ' UTC'), 'from' => (string) ($last['from'] ?? ''), 'to' => (string) ($last['to'] ?? '')])
                    : $this->t('admin.updates.never')) ?></dd></div>
            </dl>
        </div>
    </section>

    <section class="pc-card" aria-labelledby="updates-pending">
        <header class="pc-card__header">
            <h2 class="pc-card__title" id="updates-pending"><?= $this->e($this->t('admin.updates.pending_title')) ?></h2>
        </header>
        <div class="pc-card__body">
            <?php if ($status['pending'] === []): ?>
                <p class="pc-text-sm"><?= $this->e($this->t('admin.updates.pending_none')) ?></p>
            <?php else: ?>
                <ul class="pc-plain-list">
                    <?php foreach ($status['pending'] as $row): ?>
                        <li><code class="pc-mono"><?= $this->e($row['migration']) ?></code>
                            <?= $this->raw($this->insert('admin::components/badge', ['label' => $row['source'] === 'core' ? $this->t('admin.updates.source_core') : $row['source'], 'variant' => 'neutral'])) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>

    <section class="pc-card" aria-labelledby="updates-run">
        <header class="pc-card__header">
            <div>
                <h2 class="pc-card__title" id="updates-run"><?= $this->e($this->t('admin.updates.run_title')) ?></h2>
                <p class="pc-card__subtitle"><?= $this->e($this->t('admin.updates.run_help')) ?></p>
            </div>
        </header>
        <form method="post" action="/admin/tools/updates/run" class="pc-card__body pc-stack pc-stack--md">
            <?= $this->raw($this->csrfField()) ?>
            <fieldset class="pc-plain-fieldset pc-stack pc-stack--sm">
                <legend class="pc-field__label"><?= $this->e($this->t('admin.updates.backup_legend')) ?></legend>
                <?php if ($status['mysqldump']): ?>
                    <?= $this->raw($this->insert('admin::components/choice', ['id' => 'backup-auto', 'name' => 'backup_mode', 'type' => 'radio', 'value' => 'auto', 'checked' => true, 'label' => $this->t('admin.updates.backup_auto'), 'description' => $this->t('admin.updates.backup_auto_help')])) ?>
                <?php endif; ?>
                <?= $this->raw($this->insert('admin::components/choice', ['id' => 'backup-manual', 'name' => 'backup_mode', 'type' => 'radio', 'value' => 'manual', 'checked' => !$status['mysqldump'], 'label' => $this->t('admin.updates.backup_manual')])) ?>
                <?= $this->raw($this->insert('admin::components/choice', ['id' => 'backup-done', 'name' => 'backup_done', 'value' => '1', 'label' => $this->t('admin.updates.backup_done')])) ?>
            </fieldset>
            <div>
                <button type="submit" class="pc-btn pc-btn--primary"><?= $this->raw($this->icon('download', 16)) ?><span><?= $this->e($this->t('admin.updates.run')) ?></span></button>
            </div>
            <p class="pc-field__help"><?= $this->e($this->t('admin.updates.cli_hint')) ?> <code class="pc-mono">php bin/prime update --backup</code></p>
        </form>
    </section>
</div>

<?php $this->stop(); ?>
