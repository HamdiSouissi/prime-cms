<?php

declare(strict_types=1);

/** @var Prime\Site\View\SiteView $site */
$footerZones = [];
foreach (['footer_1', 'footer_2', 'footer_3'] as $zSlug) {
    if ($site->hasZone($zSlug)) {
        $footerZones[] = $site->zone($zSlug);
    }
}
$footerText = trim((string) $site->option('footer_text', ''));
if ($footerText === '') {
    $footerText = $site->t('site.footer.powered_by', ['name' => 'Prime CMS']);
}
?>
<footer class="site-footer">
  <div class="site-container">
    <?php if ($footerZones !== []): ?>
      <div class="site-footer__zones">
        <?php foreach ($footerZones as $zoneHtml): ?>
          <div class="site-footer__col"><?= $zoneHtml ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="site-footer__bottom">
      <p class="site-footer__copy">
        &copy; <?= gmdate('Y') ?> <?= $site->name() ?><?php if ($footerText !== ''): ?> — <?= $footerText ?><?php endif; ?>
      </p>

      <?php if ($site->hasMenu('footer')): ?>
        <div class="site-footer__nav">
          <?= $site->menu('footer', ['class' => 'site-nav', 'aria_label' => $site->t('site.aurora.partials.footer.footer_nav')]) ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</footer>
