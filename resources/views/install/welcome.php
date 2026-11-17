<?php

declare(strict_types=1);

/**
 * Step 1 — Welcome & wizard language selection.
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $wizardLocale
 * @var array<string, string> $locales
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
    <h1 class="pc-install__title"><?= t('core.install.welcome.title') ?></h1>
    <p class="pc-install__lead">
        <?= $this->e($this->t('core.install.welcome.prime_cms_systeme_gestion_contenu')) ?>
        <?= $this->e($this->t('core.install.welcome.cet_assistant_va_verifier_serveur')) ?>
    </p>
</div>

<form method="post" action="/install" class="pc-install__form">
    <?= $this->raw($this->csrfField()) ?>

    <?= $this->raw($this->insert('admin::components/field', [
        'id' => 'wizard-locale',
        'name' => 'wizard_locale',
        'label' => $this->t('core.install.view.wizard_language'),
        'type' => 'select',
        'value' => $wizardLocale,
        'options' => $locales,
        'help' => $this->t('core.install.view.wizard_language_help'),
        'required' => true,
    ])) ?>

    <div class="pc-install__actions pc-install__actions--end">
        <button type="submit" class="pc-btn pc-btn--primary">
            <span><?= t('core.install.welcome.start') ?></span>
            <?= $this->raw($this->icon('arrow-right', 16)) ?>
        </button>
    </div>
</form>
<?php $this->stop(); ?>
