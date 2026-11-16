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
    'subtitle' => 'Cache de pages, pilote du cache de données et statistiques en direct.',
    'breadcrumbs' => $breadcrumbs,
])) ?>

<div class="pc-grid-2 pc-stack pc-stack--lg">
    <section class="pc-card" aria-labelledby="pc-perf-pages-title">
        <div class="pc-card__body pc-stack pc-stack--md">
            <h2 id="pc-perf-pages-title" class="pc-card__title">Cache de pages</h2>
            <p class="pc-text-xs pc-text-muted">
                Le cache de pages conserve le HTML des visiteurs anonymes dans
                <code>storage/cache/pages/</code>. Il est purgé automatiquement à chaque
                publication, modification de menu ou changement de réglage.
            </p>

            <form method="post" action="/admin/settings/performance" class="pc-stack pc-stack--md">
                <?= $this->raw($this->csrfField()) ?>

                <label class="pc-switch">
                    <input class="pc-switch__input" type="checkbox" name="page_enabled" value="1"<?= $stats['page_enabled'] ? ' checked' : '' ?>>
                    <span>Activer le cache de pages</span>
                </label>

                <div class="pc-field">
                    <label class="pc-label" for="pc-perf-ttl">Durée de vie (secondes)</label>
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
                    <p class="pc-text-xs pc-text-muted">Entre 60 secondes et 24 heures.</p>
                </div>

                <button type="submit" class="pc-btn pc-btn--primary">Enregistrer</button>
            </form>
        </div>
    </section>

    <section class="pc-card" aria-labelledby="pc-perf-data-title">
        <div class="pc-card__body pc-stack pc-stack--md">
            <h2 id="pc-perf-data-title" class="pc-card__title">Cache de données</h2>

            <div class="pc-grid-2">
                <div class="pc-field">
                    <span class="pc-label">Pilote actif</span>
                    <p class="pc-text-sm"><code><?= $this->e($driver) ?></code></p>
                </div>
                <div class="pc-field">
                    <span class="pc-label">APCu</span>
                    <p class="pc-text-sm">
                        <?= $apcuAvailable ? 'Disponible' : 'Non disponible sur ce serveur' ?>
                    </p>
                </div>
                <div class="pc-field">
                    <span class="pc-label">Entrées de données</span>
                    <p class="pc-text-sm"><?= $this->formatNumber($stats['data']['entries']) ?></p>
                </div>
                <div class="pc-field">
                    <span class="pc-label">Pages en cache</span>
                    <p class="pc-text-sm"><?= $this->formatNumber($stats['pages']['entries']) ?></p>
                </div>
                <div class="pc-field">
                    <span class="pc-label">Taille sur disque</span>
                    <p class="pc-text-sm"><?= $this->e(\Prime\Media\MediaHelper::formatBytes($stats['total_bytes'])) ?></p>
                </div>
                <div class="pc-field">
                    <span class="pc-label">Dernier vidage</span>
                    <p class="pc-text-sm">
                        <?= $lastPurgedAt === null
                            ? 'Jamais'
                            : $this->e($this->formatRelative($lastPurgedAt)) ?>
                    </p>
                </div>
            </div>

            <form method="post" action="/admin/settings/performance/purge">
                <?= $this->raw($this->csrfField()) ?>
                <button type="submit" class="pc-btn pc-btn--secondary">Vider tout le cache</button>
            </form>
        </div>
    </section>
</div>

<?php $this->stop(); ?>
