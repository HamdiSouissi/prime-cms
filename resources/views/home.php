<?php $this->layout('layouts.base', ['title' => $appName . ' — Noyau']); ?>

<?php $this->start('content'); ?>
  <header class="pc-header">
    <picture>
      <source srcset="<?= $this->e(asset('brand/logo-128.webp')) ?>" type="image/webp">
      <img
        src="<?= $this->e(asset('brand/logo-128.png')) ?>"
        alt="<?= $this->e($appName) ?>"
        width="64"
        height="64"
        class="pc-logo"
      >
    </picture>
    <div class="pc-title-row">
      <h1 class="pc-title"><?= $this->e($appName) ?></h1>
      <span class="pc-badge pc-badge--brand pc-mono">v<?= $this->e($version) ?></span>
    </div>
    <p class="pc-subtitle"><?= $this->e($this->t('site.home.noyau_place')) ?></p>
  </header>

  <?php if (!empty($debug)): ?>
    <section class="pc-card" aria-labelledby="status-heading">
      <h2 id="status-heading" class="pc-card-title"><?= $this->e($this->t('site.home.etat_systeme')) ?></h2>

      <ul class="pc-list">
        <li class="pc-list-item">
          <span class="pc-label"><?= t('core.home.php_version') ?></span>
          <span class="pc-badge pc-badge--neutral pc-mono"><?= $this->e($phpVersion) ?></span>
        </li>
        <li class="pc-list-item">
          <span class="pc-label">Environnement</span>
          <span class="pc-badge pc-badge--neutral">
            <?= $this->e($environment) ?> · debug
          </span>
        </li>
        <li class="pc-list-item">
          <span class="pc-label"><?= t('core.home.storage_folders', ['path' => '<code class="pc-mono">storage/</code>']) ?></span>
          <?php if ($allWritable): ?>
            <span class="pc-badge pc-badge--success">
              <span class="pc-dot pc-dot--success" aria-hidden="true"></span>
              <?= $this->e($this->t('site.home.ecriture_prete')) ?>
            </span>
          <?php else: ?>
            <span class="pc-badge pc-badge--danger">
              <span class="pc-dot pc-dot--danger" aria-hidden="true"></span>
              <?= $this->e($this->t('site.home.permissions_a_verifier')) ?>
            </span>
          <?php endif; ?>
        </li>
      </ul>

      <div class="pc-divider" role="separator"></div>

      <ul class="pc-list" aria-label="<?= t('admin.attr.detail_des_dossiers_de_stockage') ?>">
        <?php foreach ($storageStatus as $directory => $writable): ?>
          <li class="pc-list-item">
            <code class="pc-mono"><?= $this->e($directory) ?></code>
            <?php if ($writable): ?>
              <span class="pc-badge pc-badge--success">
                <span class="pc-dot pc-dot--success" aria-hidden="true"></span>
                Accessible
              </span>
            <?php else: ?>
              <span class="pc-badge pc-badge--danger">
                <span class="pc-dot pc-dot--danger" aria-hidden="true"></span>
                <?= t('core.home.storage_not_writable') ?>
              </span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>

    <footer class="pc-footer">
      <span><?= t('core.home.footer') ?></span>
      <span aria-hidden="true">·</span>
      <a href="/health"><?= $this->e($this->t('site.home.etat_json_health')) ?></a>
    </footer>
  <?php endif; ?>
<?php $this->stop(); ?>
