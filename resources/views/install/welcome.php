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
    <h1 class="pc-install__title">Bienvenue dans Prime CMS</h1>
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
        'label' => 'Langue de l\'assistant',
        'type' => 'select',
        'value' => $wizardLocale,
        'options' => $locales,
        'help' => 'Vous pourrez également choisir la langue publique de votre site à l\'étape 4.',
        'required' => true,
    ])) ?>

    <div class="pc-install__actions pc-install__actions--end">
        <button type="submit" class="pc-btn pc-btn--primary">
            <span>Commencer l'installation</span>
            <?= $this->raw($this->icon('arrow-right', 16)) ?>
        </button>
    </div>
</form>
<?php $this->stop(); ?>
