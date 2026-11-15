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
    <h1 class="pc-install__title">Prêt pour l'installation</h1>
    <p class="pc-install__lead">
        Vérifiez votre récapitulatif ci-dessous (aucun mot de passe n'est affiché), puis lancez l'installation.
        Prime CMS écrira la configuration, créera les tables et enregistrera votre compte administrateur.
    </p>
</div>

<?php if ($installError !== null): ?>
    <div class="pc-install__alert-block">
        <?= $this->raw($this->insert('admin::components/alert', [
            'variant' => $manualEnvContent !== null ? 'warning' : 'danger',
            'title' => 'Étape interrompue : ' . $installError['step'],
            'message' => $installError['message'],
        ])) ?>
    </div>
<?php endif; ?>

<?php if ($manualEnvContent !== null): ?>
    <div class="pc-env-box">
        <div class="pc-env-box__header">
            <p class="pc-env-box__title">Créez manuellement le fichier <code>.env</code> à la racine du projet</p>
            <button
                type="button"
                class="pc-btn pc-btn--secondary pc-btn--sm"
                data-prime-copy-target="install-manual-env-content"
                data-prime-copy-status="install-manual-env-status"
            >
                <?= $this->raw($this->icon('copy', 15)) ?>
                <span>Copier</span>
            </button>
        </div>
        <label class="sr-only" for="install-manual-env-content">Contenu exact du fichier .env</label>
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
            'title' => 'Fichier .env en lecture seule',
            'message' => 'Le serveur ne peut pas écrire le fichier .env automatiquement. En lançant l\'installation, l\'assistant vous affichera le contenu exact à copier manuellement.',
        ])) ?>
    </div>
<?php endif; ?>

<div class="pc-install-summary">
    <section class="pc-install-summary__section" aria-label="Base de données">
        <div class="pc-install-summary__heading">
            <h2 class="pc-install-summary__title">Base de données</h2>
            <a href="/install/database" class="pc-install-summary__edit">Modifier</a>
        </div>
        <dl class="pc-install-dl">
            <dt>Serveur</dt>
            <dd><?= $this->e($summary['database']['host'] . ':' . (string) $summary['database']['port']) ?></dd>
            <dt>Base</dt>
            <dd><?= $this->e($summary['database']['database']) ?></dd>
            <dt>Utilisateur</dt>
            <dd><?= $this->e($summary['database']['username']) ?></dd>
            <dt>Préfixe des tables</dt>
            <dd><code><?= $this->e($summary['database']['prefix']) ?></code></dd>
        </dl>
    </section>

    <section class="pc-install-summary__section" aria-label="Site">
        <div class="pc-install-summary__heading">
            <h2 class="pc-install-summary__title">Site</h2>
            <a href="/install/site" class="pc-install-summary__edit">Modifier</a>
        </div>
        <dl class="pc-install-dl">
            <dt>Nom du site</dt>
            <dd><?= $this->e($summary['site']['name']) ?></dd>
            <dt>URL publique</dt>
            <dd><?= $this->e($summary['site']['url']) ?></dd>
            <dt>Langue</dt>
            <dd><?= $this->e(strtoupper($summary['site']['locale'])) ?></dd>
            <dt>Fuseau horaire</dt>
            <dd><?= $this->e($summary['site']['timezone']) ?></dd>
        </dl>
    </section>

    <section class="pc-install-summary__section" aria-label="Administrateur">
        <div class="pc-install-summary__heading">
            <h2 class="pc-install-summary__title">Administrateur</h2>
            <a href="/install/admin" class="pc-install-summary__edit">Modifier</a>
        </div>
        <dl class="pc-install-dl">
            <dt>Nom affiché</dt>
            <dd><?= $this->e($summary['admin']['display_name']) ?></dd>
            <dt>Adresse e-mail</dt>
            <dd><?= $this->e($summary['admin']['email']) ?></dd>
            <dt>Mot de passe</dt>
            <dd>Masqué (stocké en Argon2id)</dd>
        </dl>
    </section>
</div>

<form method="post" action="/install/run" class="pc-install__form">
    <?= $this->raw($this->csrfField()) ?>

    <div class="pc-install__actions">
        <a href="/install/admin" class="pc-btn pc-btn--ghost">
            <?= $this->raw($this->icon('chevron-left', 16)) ?>
            <span>Retour</span>
        </a>

        <button type="submit" class="pc-btn pc-btn--primary">
            <span><?= $this->e($manualEnvContent !== null ? 'J\'ai créé le fichier, continuer' : 'Installer Prime CMS') ?></span>
            <?= $this->raw($this->icon('arrow-right', 16)) ?>
        </button>
    </div>
</form>
<?php $this->stop(); ?>
