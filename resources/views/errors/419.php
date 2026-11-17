<?php $this->layout('layouts.base', ['title' => $this->t('core.errors.page_titles.419')]); ?>

<?php $this->start('content'); ?>
  <section class="pc-card pc-error-card" aria-labelledby="error-419-title">
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
    <span class="pc-badge pc-badge--brand"><?= t('core.errors.419.title') ?></span>
    <h1 id="error-419-title" class="pc-error-heading"><?= $this->e($this->t('core.errors.419.session_expiree')) ?></h1>
    <p class="pc-error-text">
      <?= $this->e($this->t('core.errors.419.jeton_securite_invalide')) ?>
      <?= $this->e($this->t('core.errors.419.revenez_a_page_precedente_rechargez')) ?>
    </p>
    <a href="<?= $this->e(!empty($path) && is_string($path) ? $path : '/') ?>" class="pc-btn pc-btn--primary"><?= $this->e($this->t('core.actions.reload')) ?></a>
  </section>
<?php $this->stop(); ?>
