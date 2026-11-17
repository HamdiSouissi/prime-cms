<?php $this->layout('layouts.base', ['title' => $this->t('core.errors.page_titles.405')]); ?>

<?php $this->start('content'); ?>
  <section class="pc-card pc-error-card" aria-labelledby="error-405-title">
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
    <span class="pc-badge pc-badge--brand"><?= t('core.errors.405.title') ?></span>
    <h1 id="error-405-title" class="pc-error-heading"><?= $this->e($this->t('core.errors.405.methode_non_autorisee')) ?></h1>
    <p class="pc-error-text">
      <?= $this->e($this->t('core.errors.405.methode_http_utilisee_pas_acceptee')) ?>
      <?php if (!empty($allowedMethods)): ?>
        <br><?= $this->e($this->t('core.errors.405.methodes_autorisees')) ?> <code class="pc-mono"><?= $this->e(implode(', ', $allowedMethods)) ?></code>
      <?php endif; ?>
    </p>
    <a href="/" class="pc-btn pc-btn--primary"><?= $this->e($this->t('core.errors.405.retourner_a_accueil')) ?></a>
  </section>
<?php $this->stop(); ?>
