<?php

declare(strict_types=1);

/**
 * Performance settings view (`GET /admin/settings/performance`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var array{
 *   driver: string,
 *   apcu_available: bool,
 *   page_enabled: bool,
 *   page_ttl: int,
 *   data: array{entries: int, bytes: int},
 *   pages: array{entries: int, bytes: int},
 *   total_entries: int,
 *   total_bytes: int
 * } $stats
 * @var string $driver
 * @var bool $apcuAvailable
 * @var ?int $lastPurgedAt
 * @var list<array{label: string, url: ?string}> $breadcrumbs
 */
$this->layout('admin::layouts/app');
?>
<?php $this->start('content'); ?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => 'Performance',
    'subtitle' => $this->t('admin.view_settings_performance.cache_de_pages_pilote_du_cache_de_donnees_et_statistiques_en'),
    'breadcrumbs' => $breadcrumbs,
])) ?>

<div class="pc-grid-2 pc-stack pc-stack--lg">
    <section class="pc-card" aria-labelledby="pc-perf-pages-title">
        <div class="pc-card__body pc-stack pc-stack--md">
            <h2 id="pc-perf-pages-title" class="pc-card__title"><?= $this->e($this->t('admin.settings.performance.cache_pages')) ?></h2>
            <p class="pc-text-xs pc-text-muted">
                <?= $this->e($this->t('admin.settings.performance.cache_pages_conserve_html_visiteurs')) ?>
                <code>storage/cache/pages/</code>. <?= $this->e($this->t('admin.settings.performance.purge_automatique')) ?>
                <?= $this->e($this->t('admin.settings.performance.publication_modification_menu_changement_reglage')) ?>
            </p>

            <form method="post" action="/admin/settings/performance" class="pc-stack pc-stack--md">
                <?= $this->raw($this->csrfField()) ?>

                <label class="pc-switch">
                    <input class="pc-switch__input" type="checkbox" name="page_enabled" value="1"<?= $stats['page_enabled'] ? ' checked' : '' ?>>
                    <span><?= $this->e($this->t('admin.settings.performance.activer_cache_pages')) ?></span>
                </label>

                <div class="pc-field">
                    <label class="pc-label" for="pc-perf-ttl"><?= $this->e($this->t('admin.settings.performance.duree_vie_secondes')) ?></label>
                    <input
                        id="pc-perf-ttl"
                        type="number"
                        name="page_ttl"
                        min="60"
                        max="86400"
                        step="60"
                        value="<?= $this->e((string) $stats['page_ttl']) ?>"
                        class="pc-input pc-input--narrow"
                        required
                    >
                    <p class="pc-text-xs pc-text-muted"><?= $this->e($this->t('admin.settings.performance.entre_60_secondes_24_heures')) ?></p>
                </div>

                <button type="submit" class="pc-btn pc-btn--primary"><?= $this->e($this->t('core.actions.save')) ?></button>
            </form>
        </div>
    </section>

    <section class="pc-card" aria-labelledby="pc-perf-data-title">
        <div class="pc-card__body pc-stack pc-stack--md">
            <h2 id="pc-perf-data-title" class="pc-card__title"><?= $this->e($this->t('admin.settings.performance.cache_donnees')) ?></h2>

            <div class="pc-grid-2">
                <div class="pc-field">
                    <span class="pc-label"><?= t('admin.settings.performance.driver') ?></span>
                    <p class="pc-text-sm"><code><?= $this->e($driver) ?></code></p>
                </div>
                <div class="pc-field">
                    <span class="pc-label">APCu</span>
                    <p class="pc-text-sm">
                        <?= $apcuAvailable ? 'Disponible' : 'Non disponible sur ce serveur' ?>
                    </p>
                </div>
                <div class="pc-field">
                    <span class="pc-label"><?= $this->e($this->t('admin.settings.performance.entrees_donnees')) ?></span>
                    <p class="pc-text-sm"><?= $this->formatNumber($stats['data']['entries']) ?></p>
                </div>
                <div class="pc-field">
                    <span class="pc-label"><?= t('admin.settings.performance.cached_pages') ?></span>
                    <p class="pc-text-sm"><?= $this->formatNumber($stats['pages']['entries']) ?></p>
                </div>
                <div class="pc-field">
                    <span class="pc-label"><?= t('admin.settings.performance.disk_size') ?></span>
                    <p class="pc-text-sm"><?= $this->e(\Prime\Media\MediaHelper::formatBytes($stats['total_bytes'])) ?></p>
                </div>
                <div class="pc-field">
                    <span class="pc-label"><?= t('admin.settings.performance.last_purge') ?></span>
                    <p class="pc-text-sm">
                        <?= $lastPurgedAt === null
                            ? 'Jamais'
                            : $this->e($this->formatRelative($lastPurgedAt)) ?>
                    </p>
                </div>
            </div>

            <form method="post" action="/admin/settings/performance/purge">
                <?= $this->raw($this->csrfField()) ?>
                <button type="submit" class="pc-btn pc-btn--secondary"><?= $this->e($this->t('admin.settings.performance.vider_cache')) ?></button>
            </form>
        </div>
    </section>
</div>

<?php $this->stop(); ?>
