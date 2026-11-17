<?php

declare(strict_types=1);

/**
 * « Outils › État du système ».
 *
 * @var \Prime\View\TemplateContext $this
 * @var array<string, list<array{id: string, group: string, label: string, status: string, value: string, help: ?string}>> $checkGroups
 * @var string $overall
 * @var string $report
 * @var list<array{label: string, url: ?string}> $breadcrumbs
 */
$this->layout('admin::layouts/app');
$icons = ['ok' => 'check-circle', 'warning' => 'alert-triangle', 'error' => 'alert-circle', 'info' => 'info'];
$alert = ['ok' => 'success', 'warning' => 'warning', 'error' => 'danger'][$overall] ?? 'info';
?>
<?php $this->start('content'); ?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => $this->t('admin.tools.title'),
    'subtitle' => $this->t('admin.tools.subtitle'),
    'breadcrumbs' => $breadcrumbs,
])) ?>

<?= $this->raw($this->insert('admin::tools/_nav', ['activeTab' => 'system', 'failedCount' => 0])) ?>

<div class="pc-tools-stack">
    <div class="pc-alert pc-alert--<?= $this->e($alert) ?>" role="status">
        <?= $this->raw($this->icon($icons[$overall] ?? 'info', 18)) ?>
        <p><?= $this->e($this->t('admin.system.overall.' . $overall)) ?></p>
    </div>

    <?php foreach ($checkGroups as $group => $checks): ?>
        <?php $groupTitle = $this->t('admin.system.groups.' . $group); ?>
        <section class="pc-card" aria-labelledby="checks-<?= $this->e($group) ?>">
            <header class="pc-card__header">
                <h2 class="pc-card__title" id="checks-<?= $this->e($group) ?>"><?= $this->e($groupTitle !== 'admin.system.groups.' . $group ? $groupTitle : ucfirst($group)) ?></h2>
            </header>
            <ul class="pc-check-list">
                <?php foreach ($checks as $check): ?>
                    <li class="pc-check" data-prime-check="<?= $this->e($check['id']) ?>" data-status="<?= $this->e($check['status']) ?>">
                        <span class="pc-check__icon pc-check__icon--<?= $this->e($check['status']) ?>">
                            <?= $this->raw($this->icon($icons[$check['status']] ?? 'info', 18)) ?>
                            <span class="sr-only"><?= $this->e($this->t('admin.system.status.' . $check['status'])) ?></span>
                        </span>
                        <div>
                            <p class="pc-check__label"><?= $this->e($check['label']) ?></p>
                            <?php if ($check['help'] !== null && $check['help'] !== ''): ?>
                                <p class="pc-check__help"><?= $this->e($check['help']) ?></p>
                            <?php endif; ?>
                        </div>
                        <span class="pc-check__value"><?= $this->e($check['value']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>

    <section class="pc-card" aria-labelledby="report-title">
        <header class="pc-card__header">
            <div>
                <h2 class="pc-card__title" id="report-title"><?= $this->e($this->t('admin.system.report_title')) ?></h2>
                <p class="pc-card__subtitle"><?= $this->e($this->t('admin.system.report_subtitle')) ?></p>
            </div>
            <div class="pc-row pc-row--sm">
                <button
                    type="button"
                    class="pc-btn pc-btn--secondary pc-btn--sm"
                    data-prime-copy-text="<?= $this->e($report) ?>"
                    data-prime-copy-feedback="<?= $this->e($this->t('admin.system.report_copied')) ?>"
                >
                    <?= $this->raw($this->icon('copy', 14)) ?>
                    <span><?= $this->e($this->t('admin.system.report_copy')) ?></span>
                </button>
                <button
                    type="button"
                    class="pc-btn pc-btn--ghost pc-btn--sm"
                    data-prime-download-text="<?= $this->e($report) ?>"
                    data-prime-download-filename="prime-cms-system-report.txt"
                >
                    <?= $this->raw($this->icon('download', 14)) ?>
                    <span><?= $this->e($this->t('admin.system.report_download')) ?></span>
                </button>
            </div>
        </header>
        <div class="pc-card__body">
            <label class="sr-only" for="system-report"><?= $this->e($this->t('admin.system.report_title')) ?></label>
            <textarea id="system-report" class="pc-textarea pc-report" readonly><?= $this->e($report) ?></textarea>
        </div>
    </section>
</div>

<?php $this->stop(); ?>
