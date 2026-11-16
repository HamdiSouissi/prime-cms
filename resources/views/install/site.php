<?php

declare(strict_types=1);

/**
 * Step 4 — Site identity and regional settings.
 *
 * @var \Prime\View\TemplateContext $this
 * @var array{site_name: string, site_url: string, site_locale: string, timezone: string} $values
 * @var array<string, string> $errors
 * @var array<string, string> $locales
 * @var array<string, string> $timezones
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
    <h1 class="pc-install__title"><?= $this->e($this->t('core.install.site.titre')) ?></h1>
    <p class="pc-install__lead">
        <?= $this->e($this->t('core.install.site.choisissez_nom_adresse_langue')) ?>
        <?= $this->e($this->t('core.install.site.parametres_resteront_modifiables_suite_reglages')) ?>
    </p>
</div>

<form method="post" action="/install/site" class="pc-install__form" novalidate>
    <?= $this->raw($this->csrfField()) ?>

    <?= $this->raw($this->insert('admin::components/field', [
        'id' => 'site-name',
        'name' => 'site_name',
        'label' => 'Nom du site',
        'type' => 'text',
        'value' => $values['site_name'],
        'placeholder' => 'Mon site Prime CMS',
        'error' => $errors['site_name'] ?? null,
        'required' => true,
    ])) ?>

    <div class="pc-field<?= isset($errors['site_url']) ? ' pc-field--error' : '' ?>">
        <label class="pc-field__label" for="site-url">
            <span><?= $this->e($this->t('core.install.site.adresse_site_url')) ?></span>
            <span class="pc-field__required" aria-hidden="true">*</span>
            <span class="sr-only"><?= $this->e($this->t('core.install.site.obligatoire')) ?></span>
        </label>
        <input
            type="url"
            id="site-url"
            name="site_url"
            class="pc-input"
            value="<?= $this->e($values['site_url']) ?>"
            placeholder="https://monsite.fr"
            required
            data-prime-validate="url"
            aria-describedby="site-url-help<?= isset($errors['site_url']) ? ' site-url-error' : '' ?>"
            <?= isset($errors['site_url']) ? 'aria-invalid="true"' : '' ?>
        >
        <p class="pc-field__help" id="site-url-help"><?= $this->e($this->t('core.install.site.preremplie_depuis_requete_cours_sans')) ?></p>
        <?php if (isset($errors['site_url'])): ?>
            <p class="pc-field__error" id="site-url-error" role="alert">
                <?= $this->raw($this->icon('alert-circle', 14)) ?>
                <span><?= $this->e($errors['site_url']) ?></span>
            </p>
        <?php endif; ?>
    </div>

    <div class="pc-install__grid">
        <?= $this->raw($this->insert('admin::components/field', [
            'id' => 'site-locale',
            'name' => 'site_locale',
            'label' => 'Langue du site',
            'type' => 'select',
            'value' => $values['site_locale'],
            'options' => $locales,
            'error' => $errors['site_locale'] ?? null,
            'required' => true,
        ])) ?>

        <?= $this->raw($this->insert('admin::components/field', [
            'id' => 'site-timezone',
            'name' => 'timezone',
            'label' => 'Fuseau horaire',
            'type' => 'select',
            'value' => $values['timezone'],
            'options' => $timezones,
            'error' => $errors['timezone'] ?? null,
            'required' => true,
        ])) ?>
    </div>

    <div class="pc-install__actions">
        <a href="/install/database" class="pc-btn pc-btn--ghost">
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
