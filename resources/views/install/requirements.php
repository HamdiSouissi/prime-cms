<?php

declare(strict_types=1);

/**
 * Step 2 — System requirements verification.
 *
 * @var \Prime\View\TemplateContext $this
 * @var array{
 *   passed: bool,
 *   blocking_errors: int,
 *   warnings: int,
 *   checks: list<array{id: string, group: string, label: string, current: string, required: string, status: string, blocking: bool, fix: ?string}>
 * } $report
 */
$this->layout('install::layout', [
    'pageTitle' => $pageTitle,
    'currentStep' => $currentStep,
    'currentStepNumber' => $currentStepNumber,
    'totalSteps' => $totalSteps,
    'steps' => $steps,
]);

$grouped = [
    'php' => ['title' => 'Environnement PHP', 'items' => []],
    'extensions' => ['title' => 'Extensions PHP', 'items' => []],
    'paths' => ['title' => 'Dossiers et écriture', 'items' => []],
];

foreach ($report['checks'] as $item) {
    $grp = $item['group'];
    if (isset($grouped[$grp])) {
        $grouped[$grp]['items'][] = $item;
    }
}
?>
<?php $this->start('content'); ?>
<div class="pc-install__header">
    <h1 class="pc-install__title"><?= $this->e($this->t('core.install.requirements.verification_prerequis')) ?></h1>
    <p class="pc-install__lead">
        <?= $this->e($this->t('core.install.requirements.assurons_nous_environnement_dispose_bonne')) ?>
    </p>
</div>

<?php if ($report['passed']): ?>
    <?= $this->raw($this->insert('admin::components/alert', [
        'variant' => $report['warnings'] > 0 ? 'warning' : 'success',
        'title' => $report['warnings'] > 0
            ? 'Prérequis satisfaits avec avertissement'
            : 'Tous les prérequis sont satisfaits',
        'message' => $report['warnings'] > 0
            ? 'Vous pouvez poursuivre l\'installation. Consultez les avertissements non bloquants ci-dessous.'
            : 'Votre serveur est prêt à accueillir Prime CMS.',
    ])) ?>
<?php else: ?>
    <?= $this->raw($this->insert('admin::components/alert', [
        'variant' => 'danger',
        'title' => 'Action requise avant de continuer',
        'message' => sprintf(
            '%d exigence%s bloquante%s doit être corrigée sur votre serveur. Suivez les indications ci-dessous puis cliquez sur « Revérifier ».',
            $report['blocking_errors'],
            $report['blocking_errors'] > 1 ? 's' : '',
            $report['blocking_errors'] > 1 ? 's' : '',
        ),
    ])) ?>
<?php endif; ?>

<div class="pc-req-groups">
    <?php foreach ($grouped as $group): ?>
        <section aria-label="<?= $this->e($group['title']) ?>">
            <h2 class="pc-req-group__title"><?= $this->e($group['title']) ?></h2>
            <ul class="pc-req-list">
                <?php foreach ($group['items'] as $check): ?>
                    <?php
                    $badgeVariant = match ($check['status']) {
                        'ok' => 'success',
                        'warning' => 'amber',
                        default => 'danger',
                    };
                    $badgeLabel = match ($check['status']) {
                        'ok' => 'OK',
                        'warning' => 'Avertissement',
                        default => 'Erreur',
                    };
                    ?>
                    <li class="pc-req-item">
                        <div class="pc-req-item__row">
                            <span class="pc-req-item__name"><?= $this->e($check['label']) ?></span>
                            <span class="pc-req-item__meta">
                                <span><?= $this->e($check['current']) ?></span>
                                <?= $this->raw($this->insert('admin::components/badge', [
                                    'label' => $badgeLabel,
                                    'variant' => $badgeVariant,
                                ])) ?>
                            </span>
                        </div>
                        <?php if ($check['fix'] !== null): ?>
                            <p class="pc-req-item__fix"><?= $this->e($check['fix']) ?></p>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>
</div>

<div class="pc-install__actions">
    <a href="/install" class="pc-btn pc-btn--ghost">
        <?= $this->raw($this->icon('chevron-left', 16)) ?>
        <span>Retour</span>
    </a>

    <div class="pc-install__actions-group">
        <a href="/install/requirements" class="pc-btn pc-btn--secondary"><?= $this->e($this->t('core.install.requirements.reverifier')) ?></a>
        <?php if ($report['passed']): ?>
            <a href="/install/database" class="pc-btn pc-btn--primary">
                <span>Continuer</span>
                <?= $this->raw($this->icon('arrow-right', 16)) ?>
            </a>
        <?php else: ?>
            <button type="button" class="pc-btn pc-btn--primary" disabled aria-disabled="true">
                <span>Continuer</span>
                <?= $this->raw($this->icon('arrow-right', 16)) ?>
            </button>
        <?php endif; ?>
    </div>
</div>
<?php $this->stop(); ?>
