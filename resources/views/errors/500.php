<?php $this->layout('layouts.base', ['title' => $this->t('core.errors.page_titles.500')]); ?>

<?php $this->start('content'); ?>
  <section class="pc-card pc-error-card" aria-labelledby="error-500-title">
    <picture>
      <source srcset="<?= $this->e(asset('brand/logo-128.webp')) ?>" type="image/webp">
      <img
        src="<?= $this->e(asset('brand/logo-128.png')) ?>"
        alt="Prime CMS"
        width="56"
        height="56"
        class="pc-logo"
      >
    </picture>
    <span class="pc-badge pc-badge--danger"><?= $this->e($this->t('core.errors.badge', ['status' => $status ?? 500])) ?></span>
    <h1 id="error-500-title" class="pc-error-heading"><?= $this->e($this->t('core.errors.500.erreur_survenue')) ?></h1>
    <p class="pc-error-text">
      <?= $this->e($this->t('core.errors.500.serveur_pas_pu_traiter_demande')) ?>
    </p>
    <a href="/" class="pc-btn pc-btn--primary"><?= $this->e($this->t('core.errors.500.retourner_a_accueil')) ?></a>
  </section>
<?php $this->stop(); ?>
