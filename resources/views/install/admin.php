<?php

declare(strict_types=1);

/**
 * Step 5 — Administrator account creation with password strength meter.
 *
 * @var \Prime\View\TemplateContext $this
 * @var array{display_name: string, email: string} $values
 * @var array<string, string> $errors
 * @var string $siteName
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
    <h1 class="pc-install__title">Compte administrateur</h1>
    <p class="pc-install__lead">
        Créez le compte principal qui administrera votre site.
        Choisissez une phrase secrète unique d'au moins 12 caractères (stockée de façon irréversible en Argon2id).
    </p>
</div>

<form method="post" action="/install/admin" class="pc-install__form" novalidate>
    <?= $this->raw($this->csrfField()) ?>

    <div class="pc-install__grid">
        <div class="pc-field<?= isset($errors['display_name']) ? ' pc-field--error' : '' ?>">
            <label class="pc-field__label" for="admin-display-name">
                <span>Nom affiché</span>
                <span class="pc-field__required" aria-hidden="true">*</span>
                <span class="sr-only">(obligatoire)</span>
            </label>
            <input
                type="text"
                id="admin-display-name"
                name="display_name"
                class="pc-input"
                value="<?= $this->e($values['display_name']) ?>"
                placeholder="Camille Laurent"
                autocomplete="name"
                required
                data-prime-validate="display-name"
                <?= isset($errors['display_name']) ? 'aria-invalid="true" aria-describedby="admin-display-name-error"' : '' ?>
            >
            <?php if (isset($errors['display_name'])): ?>
                <p class="pc-field__error" id="admin-display-name-error" role="alert">
                    <?= $this->raw($this->icon('alert-circle', 14)) ?>
                    <span><?= $this->e($errors['display_name']) ?></span>
                </p>
            <?php endif; ?>
        </div>

        <div class="pc-field<?= isset($errors['email']) ? ' pc-field--error' : '' ?>">
            <label class="pc-field__label" for="admin-email">
                <span>Adresse e-mail</span>
                <span class="pc-field__required" aria-hidden="true">*</span>
                <span class="sr-only">(obligatoire)</span>
            </label>
            <input
                type="email"
                id="admin-email"
                name="email"
                class="pc-input"
                value="<?= $this->e($values['email']) ?>"
                placeholder="admin@monsite.fr"
                autocomplete="email"
                required
                data-prime-validate="email"
                <?= isset($errors['email']) ? 'aria-invalid="true" aria-describedby="admin-email-error"' : '' ?>
            >
            <?php if (isset($errors['email'])): ?>
                <p class="pc-field__error" id="admin-email-error" role="alert">
                    <?= $this->raw($this->icon('alert-circle', 14)) ?>
                    <span><?= $this->e($errors['email']) ?></span>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <div class="pc-field<?= isset($errors['password']) ? ' pc-field--error' : '' ?>">
        <label class="pc-field__label" for="admin-password">
            <span>Mot de passe</span>
            <span class="pc-field__required" aria-hidden="true">*</span>
            <span class="sr-only">(obligatoire)</span>
        </label>
        <div class="pc-input-group">
            <input
                type="password"
                id="admin-password"
                name="password"
                class="pc-input"
                value=""
                placeholder="Au moins 12 caractères"
                autocomplete="new-password"
                minlength="12"
                required
                data-prime-validate="admin-password"
                aria-describedby="admin-password-strength<?= isset($errors['password']) ? ' admin-password-error' : '' ?>"
                <?= isset($errors['password']) ? 'aria-invalid="true"' : '' ?>
            >
            <button
                type="button"
                class="pc-input-group__action"
                data-prime-password-toggle
                aria-label="Afficher le mot de passe"
                aria-pressed="false"
            >
                <?= $this->raw($this->icon('eye', 18)) ?>
            </button>
        </div>

        <div
            class="pc-strength"
            id="admin-password-strength"
            data-prime-password-strength="admin-password"
            data-prime-site-name="<?= $this->e($siteName) ?>"
            data-prime-level="0"
        >
            <div class="pc-strength__bars" aria-hidden="true">
                <span class="pc-strength__bar"></span>
                <span class="pc-strength__bar"></span>
                <span class="pc-strength__bar"></span>
                <span class="pc-strength__bar"></span>
            </div>
            <p class="pc-strength__text" data-prime-strength-label aria-live="polite">
                Minimum 12 caractères recommandés (phrase secrète, majuscules, chiffres et symboles).
            </p>
        </div>

        <?php if (isset($errors['password'])): ?>
            <p class="pc-field__error" id="admin-password-error" role="alert">
                <?= $this->raw($this->icon('alert-circle', 14)) ?>
                <span><?= $this->e($errors['password']) ?></span>
            </p>
        <?php endif; ?>
    </div>

    <div class="pc-field<?= isset($errors['password_confirmation']) ? ' pc-field--error' : '' ?>">
        <label class="pc-field__label" for="admin-password-confirm">
            <span>Confirmer le mot de passe</span>
            <span class="pc-field__required" aria-hidden="true">*</span>
            <span class="sr-only">(obligatoire)</span>
        </label>
        <div class="pc-input-group">
            <input
                type="password"
                id="admin-password-confirm"
                name="password_confirmation"
                class="pc-input"
                value=""
                placeholder="Ressaisissez votre mot de passe"
                autocomplete="new-password"
                minlength="12"
                required
                data-prime-validate="password-confirm"
                <?= isset($errors['password_confirmation']) ? 'aria-invalid="true" aria-describedby="admin-password-confirm-error"' : '' ?>
            >
            <button
                type="button"
                class="pc-input-group__action"
                data-prime-password-toggle
                aria-label="Afficher la confirmation du mot de passe"
                aria-pressed="false"
            >
                <?= $this->raw($this->icon('eye', 18)) ?>
            </button>
        </div>
        <?php if (isset($errors['password_confirmation'])): ?>
            <p class="pc-field__error" id="admin-password-confirm-error" role="alert">
                <?= $this->raw($this->icon('alert-circle', 14)) ?>
                <span><?= $this->e($errors['password_confirmation']) ?></span>
            </p>
        <?php endif; ?>
    </div>

    <div class="pc-install__actions">
        <a href="/install/site" class="pc-btn pc-btn--ghost">
            <?= $this->raw($this->icon('chevron-left', 16)) ?>
            <span>Retour</span>
        </a>

        <button type="submit" class="pc-btn pc-btn--primary">
            <span>Continuer</span>
            <?= $this->raw($this->icon('arrow-right', 16)) ?>
        </button>
    </div>
</form>
<?php $this->stop(); ?>
