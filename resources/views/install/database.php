<?php

declare(strict_types=1);

/**
 * Step 3 — Database configuration and connectivity test.
 *
 * @var \Prime\View\TemplateContext $this
 * @var array{db_host: string, db_port: string, db_database: string, db_username: string, db_prefix: string} $values
 * @var array<string, string> $errors
 * @var ?array{ok: bool, status: string, message: string, can_create_database?: bool, existing_tables?: list<string>} $dbStatus
 * @var bool $hasStoredPassword
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
    <h1 class="pc-install__title"><?= $this->e($this->t('core.install.database.connexion_a_base_donnees')) ?></h1>
    <p class="pc-install__lead">
        <?= $this->e($this->t('core.install.database.identifiants_serveur')) ?>
        <?= $this->e($this->t('core.install.database.informations_sensibles_restent_stockees_session')) ?>
    </p>
</div>

<?php if ($dbStatus !== null): ?>
    <div class="pc-install__alert-block">
        <?= $this->raw($this->insert('admin::components/alert', [
            'variant' => $dbStatus['ok'] ? 'success' : ($dbStatus['status'] === 'prefix_conflict' ? 'warning' : 'danger'),
            'title' => $dbStatus['ok'] ? $this->t('core.install.view.db_ok') : $this->t('core.install.view.db_failed'),
            'message' => $dbStatus['message'],
        ])) ?>
    </div>
<?php endif; ?>

<form method="post" action="/install/database" class="pc-install__form" novalidate>
    <?= $this->raw($this->csrfField()) ?>

    <div class="pc-install__grid pc-install__grid--host">
        <?= $this->raw($this->insert('admin::components/field', [
            'id' => 'db-host',
            'name' => 'db_host',
            'label' => $this->t('core.install.view.db_host'),
            'type' => 'text',
            'value' => $values['db_host'],
            'placeholder' => '127.0.0.1 ou db',
            'error' => $errors['db_host'] ?? null,
            'required' => true,
        ])) ?>

        <?= $this->raw($this->insert('admin::components/field', [
            'id' => 'db-port',
            'name' => 'db_port',
            'label' => $this->t('core.install.view.db_port'),
            'type' => 'number',
            'value' => $values['db_port'],
            'placeholder' => '3306',
            'error' => $errors['db_port'] ?? null,
            'required' => true,
        ])) ?>
    </div>

    <div class="pc-install__grid">
        <?= $this->raw($this->insert('admin::components/field', [
            'id' => 'db-database',
            'name' => 'db_database',
            'label' => $this->t('core.install.view.db_name'),
            'type' => 'text',
            'value' => $values['db_database'],
            'placeholder' => 'prime_cms',
            'error' => $errors['db_database'] ?? null,
            'required' => true,
        ])) ?>

        <div class="pc-field<?= isset($errors['db_prefix']) ? ' pc-field--error' : '' ?>">
            <label class="pc-field__label" for="db-prefix">
                <span><?= $this->e($this->t('core.install.database.prefixe_tables')) ?></span>
                <span class="pc-field__required" aria-hidden="true">*</span>
                <span class="sr-only"><?= $this->e($this->t('core.install.database.obligatoire')) ?></span>
            </label>
            <input
                type="text"
                id="db-prefix"
                name="db_prefix"
                class="pc-input"
                value="<?= $this->e($values['db_prefix']) ?>"
                placeholder="pc_"
                required
                data-prime-validate="prefix"
                aria-describedby="db-prefix-help<?= isset($errors['db_prefix']) ? ' db-prefix-error' : '' ?>"
                <?= isset($errors['db_prefix']) ? 'aria-invalid="true"' : '' ?>
            >
            <p class="pc-field__help" id="db-prefix-help"><?= $this->e($this->t('core.install.database.lettres_minuscules_chiffres_termine')) ?></p>
            <?php if (isset($errors['db_prefix'])): ?>
                <p class="pc-field__error" id="db-prefix-error" role="alert">
                    <?= $this->raw($this->icon('alert-circle', 14)) ?>
                    <span><?= $this->e($errors['db_prefix']) ?></span>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <div class="pc-install__grid">
        <?= $this->raw($this->insert('admin::components/field', [
            'id' => 'db-username',
            'name' => 'db_username',
            'label' => $this->t('core.install.view.db_user'),
            'type' => 'text',
            'value' => $values['db_username'],
            'placeholder' => 'prime',
            'error' => $errors['db_username'] ?? null,
            'required' => true,
        ])) ?>

        <?= $this->raw($this->insert('admin::components/field', [
            'id' => 'db-password',
            'name' => 'db_password',
            'label' => $this->t('core.fields.password'),
            'type' => 'password',
            'value' => '',
            'placeholder' => $hasStoredPassword ? $this->t('core.install.view.db_password_keep') : $this->t('core.install.view.db_password'),
            'help' => $hasStoredPassword ? $this->t('core.install.view.db_password_kept_help') : null,
        ])) ?>
    </div>

    <?php if ($dbStatus !== null && ($dbStatus['status'] ?? '') === 'database_missing' && !empty($dbStatus['can_create_database'])): ?>
        <div>
            <button type="submit" name="intent" value="create_db" class="pc-btn pc-btn--secondary">
                <?= $this->e($this->t('core.install.database.creer_base', ['name' => $values['db_database']])) ?>
            </button>
        </div>
    <?php endif; ?>

    <div class="pc-install__actions">
        <a href="/install/requirements" class="pc-btn pc-btn--ghost">
            <?= $this->raw($this->icon('chevron-left', 16)) ?>
            <span><?= $this->e($this->t('core.actions.back')) ?></span>
        </a>

        <div class="pc-install__actions-group">
            <button type="submit" name="intent" value="test" class="pc-btn pc-btn--secondary">
                <?= $this->e($this->t('core.install.db_test')) ?>
            </button>
            <button type="submit" name="intent" value="continue" class="pc-btn pc-btn--primary">
                <span><?= $this->e($this->t('core.actions.continue')) ?></span>
                <?= $this->raw($this->icon('arrow-right', 16)) ?>
            </button>
        </div>
    </div>
</form>
<?php $this->stop(); ?>
