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
        <h1 class="pc-install__title">Prime CMS est installé !</h1>
        <p class="pc-install__lead">
            Le site <strong><?= $this->e($siteName) ?></strong> est prêt. Les tables de la base de données,
            les réglages initiaux et le compte administrateur ont été créés, et l'assistant d'installation est désormais verrouillé.
        </p>
    </div>

    <?= $this->raw($this->insert('admin::components/alert', [
        'variant' => 'info',
        'title' => 'Connexion au tableau de bord (Phase 4)',
        'message' => 'Votre compte administrateur est bien enregistré en base de données. L\'écran de connexion et la gestion des rôles arriveront avec la prochaine mise à jour (Phase 4).',
    ])) ?>

    <?php if (!$envWritten): ?>
        <?= $this->raw($this->insert('admin::components/alert', [
            'variant' => 'warning',
            'title' => 'Rappel de sécurité',
            'message' => 'Pensez à restreindre les permissions de votre fichier .env créé manuellement (chmod 640 .env) afin qu\'il ne soit pas lisible par d\'autres utilisateurs du serveur.',
        ])) ?>
    <?php endif; ?>

    <div class="pc-install-done__actions">
        <a href="/" class="pc-btn pc-btn--primary">
            <?= $this->raw($this->icon('home', 16)) ?>
            <span>Voir le site</span>
        </a>
        <a href="/admin" class="pc-btn pc-btn--secondary">
            <?= $this->raw($this->icon('layout-dashboard', 16)) ?>
            <span>Ouvrir le tableau de bord</span>
        </a>
    </div>
</div>
<?php $this->stop(); ?>
