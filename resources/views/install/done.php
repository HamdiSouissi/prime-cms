<?php

declare(strict_types=1);

/**
 * Step 7 — One-time installation completion screen.
 *
 * @var \Prime\View\TemplateContext $this
 * @var array{site_name?: string, site_url?: string, env_written?: bool, migrations_count?: int} $completion
 */
$this->layout('install::layout', [
    'pageTitle' => $pageTitle,
    'currentStep' => $currentStep,
    'currentStepNumber' => $currentStepNumber,
    'totalSteps' => $totalSteps,
    'steps' => $steps,
]);

$siteName = (string) ($completion['site_name'] ?? 'Prime CMS');
$envWritten = (bool) ($completion['env_written'] ?? true);
?>
<?php $this->start('content'); ?>
<div class="pc-install-done">
    <span class="pc-install-done__icon" aria-hidden="true">
        <?= $this->raw($this->icon('check-circle', 28)) ?>
    </span>

    <div class="pc-install__header">
        <h1 class="pc-install__title"><?= $this->e($this->t('core.install.done.prime_cms_installe')) ?></h1>
        <p class="pc-install__lead">
            <?= $this->raw($this->t('core.install.done.site_pret', ['site' => '<strong>' . $this->e($siteName) . '</strong>'])) ?>
            <?= $this->e($this->t('core.install.done.reglages_initiaux_compte_administrateur_ont')) ?>
        </p>
    </div>

    <?= $this->raw($this->insert('admin::components/alert', [
        'variant' => 'info',
        'title' => $this->t('core.install.view.done_title'),
        'message' => $this->t('core.install.view.done_message'),
    ])) ?>

    <?php if (!$envWritten): ?>
        <?= $this->raw($this->insert('admin::components/alert', [
            'variant' => 'warning',
            'title' => $this->t('core.install.view.security_reminder'),
            'message' => $this->t('core.install.view.security_reminder_message'),
        ])) ?>
    <?php endif; ?>

    <div class="pc-install-done__actions">
        <a href="/" class="pc-btn pc-btn--primary">
            <?= $this->raw($this->icon('home', 16)) ?>
            <span><?= $this->e($this->t('core.install.done.voir_site')) ?></span>
        </a>
        <a href="/admin/login" class="pc-btn pc-btn--secondary">
            <?= $this->raw($this->icon('layout-dashboard', 16)) ?>
            <span><?= $this->e($this->t('core.install.done.ouvrir_tableau_bord')) ?></span>
        </a>
    </div>
</div>
<?php $this->stop(); ?>
