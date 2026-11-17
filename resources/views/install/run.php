<?php

declare(strict_types=1);

/**
 * Step 6 — Summary & installation execution (plus read-only `.env` fallback).
 *
 * @var \Prime\View\TemplateContext $this
 * @var array{
 *   database: array{host: string, port: int, database: string, username: string, prefix: string},
 *   site: array{name: string, url: string, locale: string, timezone: string},
 *   admin: array{display_name: string, email: string}
 * } $summary
 * @var bool $envWritable
 * @var ?string $manualEnvContent
 * @var ?array{step: string, message: string} $installError
 */
$this->layout('install::layout', [
    'pageTitle' => $pageTitle,
    'currentStep' => $currentStep,
    'currentStepNumber' => $currentStepNumber,
    'totalSteps' => $totalSteps,
    'steps' => $steps,
]);
?>
<?php $this->start('content'); ?>
<div class="pc-install__header">
    <h1 class="pc-install__title"><?= $this->e($this->t('core.install.run.pret_installation')) ?></h1>
    <p class="pc-install__lead">
        <?= $this->e($this->t('core.install.run.verifiez_recapitulatif')) ?>
        <?= $this->e($this->t('core.install.run.prime_cms_ecrira_configuration_creera')) ?>
    </p>
</div>

<?php if ($installError !== null): ?>
    <div class="pc-install__alert-block">
        <?= $this->raw($this->insert('admin::components/alert', [
            'variant' => $manualEnvContent !== null ? 'warning' : 'danger',
            'title' => $this->t('core.install.view.step_failed', ['step' => $installError['step']]),
            'message' => $installError['message'],
        ])) ?>
    </div>
<?php endif; ?>

<?php if ($manualEnvContent !== null): ?>
    <div class="pc-env-box">
        <div class="pc-env-box__header">
            <p class="pc-env-box__title"><?= $this->raw($this->t('core.install.run.creer_fichier_env')) ?></p>
            <button
                type="button"
                class="pc-btn pc-btn--secondary pc-btn--sm"
                data-prime-copy-target="install-manual-env-content"
                data-prime-copy-status="install-manual-env-status"
            >
                <?= $this->raw($this->icon('copy', 15)) ?>
                <span><?= $this->e($this->t('core.actions.copy')) ?></span>
            </button>
        </div>
        <label class="sr-only" for="install-manual-env-content"><?= $this->e($this->t('core.install.run.contenu_exact_fichier_env')) ?></label>
        <textarea
            id="install-manual-env-content"
            class="pc-env-box__textarea"
            readonly
            spellcheck="false"
        ><?= $this->e($manualEnvContent) ?></textarea>
        <p id="install-manual-env-status" class="pc-env-box__status" aria-live="polite"></p>
    </div>
<?php elseif (!$envWritable): ?>
    <div class="pc-install__alert-block">
        <?= $this->raw($this->insert('admin::components/alert', [
            'variant' => 'warning',
            'title' => $this->t('core.install.view.env_readonly'),
            'message' => $this->t('core.install.view.env_readonly_message'),
        ])) ?>
    </div>
<?php endif; ?>

<div class="pc-install-summary">
    <section class="pc-install-summary__section" aria-label="<?= t('admin.attr.base_de_donnees') ?>">
        <div class="pc-install-summary__heading">
            <h2 class="pc-install-summary__title"><?= $this->e($this->t('core.install.database.titre')) ?></h2>
            <a href="/install/database" class="pc-install-summary__edit"><?= $this->e($this->t('core.actions.edit')) ?></a>
        </div>
        <dl class="pc-install-dl">
            <dt><?= $this->e($this->t('core.install.view.summary_server')) ?></dt>
            <dd><?= $this->e($summary['database']['host'] . ':' . (string) $summary['database']['port']) ?></dd>
            <dt><?= $this->e($this->t('core.install.view.summary_database')) ?></dt>
            <dd><?= $this->e($summary['database']['database']) ?></dd>
            <dt><?= $this->e($this->t('core.install.run.utilisateur')) ?></dt>
            <dd><?= $this->e($summary['database']['username']) ?></dd>
            <dt><?= $this->e($this->t('core.install.run.prefixe_tables')) ?></dt>
            <dd><code><?= $this->e($summary['database']['prefix']) ?></code></dd>
        </dl>
    </section>

    <section class="pc-install-summary__section" aria-label="Site">
        <div class="pc-install-summary__heading">
            <h2 class="pc-install-summary__title"><?= $this->e($this->t('core.install.view.summary_site')) ?></h2>
            <a href="/install/site" class="pc-install-summary__edit"><?= $this->e($this->t('core.actions.edit')) ?></a>
        </div>
        <dl class="pc-install-dl">
            <dt><?= $this->e($this->t('core.install.run.nom_site')) ?></dt>
            <dd><?= $this->e($summary['site']['name']) ?></dd>
            <dt><?= t('core.install.run.public_url') ?></dt>
            <dd><?= $this->e($summary['site']['url']) ?></dd>
            <dt><?= $this->e($this->t('core.fields.language')) ?></dt>
            <dd><?= $this->e(strtoupper($summary['site']['locale'])) ?></dd>
            <dt><?= t('core.install.run.timezone') ?></dt>
            <dd><?= $this->e($summary['site']['timezone']) ?></dd>
        </dl>
    </section>

    <section class="pc-install-summary__section" aria-label="Administrateur">
        <div class="pc-install-summary__heading">
            <h2 class="pc-install-summary__title"><?= $this->e($this->t('core.roles.administrator.name')) ?></h2>
            <a href="/install/admin" class="pc-install-summary__edit"><?= $this->e($this->t('core.actions.edit')) ?></a>
        </div>
        <dl class="pc-install-dl">
            <dt><?= $this->e($this->t('core.fields.display_name')) ?></dt>
            <dd><?= $this->e($summary['admin']['display_name']) ?></dd>
            <dt><?= t('core.install.run.email') ?></dt>
            <dd><?= $this->e($summary['admin']['email']) ?></dd>
            <dt><?= $this->e($this->t('core.fields.password')) ?></dt>
            <dd><?= $this->e($this->t('core.install.run.masque_stocke_argon2id')) ?></dd>
        </dl>
    </section>
</div>

<form method="post" action="/install/run" class="pc-install__form">
    <?= $this->raw($this->csrfField()) ?>

    <div class="pc-install__actions">
        <a href="/install/admin" class="pc-btn pc-btn--ghost">
            <?= $this->raw($this->icon('chevron-left', 16)) ?>
            <span><?= $this->e($this->t('core.actions.back')) ?></span>
        </a>

        <button type="submit" class="pc-btn pc-btn--primary">
            <span><?= $this->e($manualEnvContent !== null ? $this->t('core.install.view.env_created_continue') : $this->t('core.install.view.install_button')) ?></span>
            <?= $this->raw($this->icon('arrow-right', 16)) ?>
        </button>
    </div>
</form>
<?php $this->stop(); ?>
