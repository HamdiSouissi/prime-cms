<?php

declare(strict_types=1);

/**
 * Cooperative edit lock screen when another user currently holds the edit lock.
 *
 * @var \Prime\View\TemplateContext $this
 * @var \Prime\Content\ContentType $contentType
 * @var \Prime\Content\Entry $entry
 * @var \Prime\Content\EntryLock $lock
 * @var \Prime\Auth\User|null $holder
 * @var bool $canTakeOver
 */
$this->layout('admin::layouts/app');
$baseUrl = $contentType->adminListUrl();
$holderName = $holder !== null ? $holder->displayName : ($lock->userName !== '' ? $lock->userName : 'Un autre utilisateur');
?>
<?php $this->start('content'); ?>

<div class="pc-lock-screen">
    <div class="pc-lock-screen__icon" aria-hidden="true">
        <?= $this->raw($this->icon('lock', 26)) ?>
    </div>

    <h1 class="pc-page-header__title" style="margin-bottom:8px;">Contenu en cours d’édition</h1>
    <p class="pc-text-muted">
        <strong><?= $this->e($holderName) ?></strong> modifie actuellement « <?= $this->e($entry->title) ?> ».
        Dernière activité enregistrée à <span class="pc-mono"><?= $this->e($lock->heartbeatAt) ?></span> UTC.
    </p>

    <div class="pc-lock-screen__actions">
        <a href="<?= $this->e($baseUrl) ?>" class="pc-btn pc-btn--secondary">Retour à la liste</a>

        <?php if (!empty($canTakeOver)): ?>
            <form method="post" action="<?= $this->e(sprintf('%s/%d/lock/takeover', $baseUrl, $entry->id)) ?>">
                <?= $this->raw($this->csrfField()) ?>
                <button type="submit" class="pc-btn pc-btn--primary">
                    <?= $this->raw($this->icon('lock', 16)) ?>
                    <span>Prendre le contrôle de l’édition</span>
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php $this->stop(); ?>
