<?php $this->layout('layouts.base', ['title' => '404 — Page introuvable · Prime CMS']); ?>

<?php $this->start('content'); ?>
  <section class="pc-card pc-error-card" aria-labelledby="error-404-title">
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
    <span class="pc-badge pc-badge--brand">Erreur 404</span>
    <h1 id="error-404-title" class="pc-error-heading">Page introuvable</h1>
    <p class="pc-error-text">
      <?= $this->e($this->t('core.errors.404.ressource_demandee_existe_pas_ete')) ?>
      <?php if (!empty($path)): ?>
        <br><code class="pc-mono"><?= $this->e($path) ?></code>
      <?php endif; ?>
    </p>
    <a href="/" class="pc-btn pc-btn--primary"><?= $this->e($this->t('core.errors.404.retourner_a_accueil')) ?></a>
  </section>
<?php $this->stop(); ?>
