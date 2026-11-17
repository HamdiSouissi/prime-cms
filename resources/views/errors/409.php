<?php $this->layout('layouts.base', ['title' => $this->t('core.errors.page_titles.409')]); ?>

<?php $this->start('content'); ?>
  <section class="pc-card pc-error-card" aria-labelledby="error-409-title">
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
    <span class="pc-badge pc-badge--brand"><?= t('core.errors.409.title') ?></span>
    <h1 id="error-409-title" class="pc-error-heading"><?= $this->e($this->t('core.errors.409.action_impossible_cet_etat')) ?></h1>
    <p class="pc-error-text">
      <?= $this->e(!empty($message) && is_string($message) ? $message : $this->t('core.errors.conflict_default')) ?>
    </p>
    <a href="/admin" class="pc-btn pc-btn--primary"><?= $this->e($this->t('core.errors.409.retour_tableau_bord')) ?></a>
  </section>
<?php $this->stop(); ?>
