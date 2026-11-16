<?php

declare(strict_types=1);

/**
 * Theme block zones management view (`GET /admin/appearance/zones`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var array<string, array{label: string, description: string}> $declaredZones
 * @var array<string, int> $zoneCounts
 * @var string $selectedSlug
 * @var ?array{label: string, description: string} $selectedZone
 * @var string $zoneContentJson
 * @var string $editorConfigJson
 * @var list<array{name: string, label: string, description: string, defaults: array<string, mixed>, fields: list<array<string, mixed>>}> $dynamicCatalog
 * @var list<array{label: string, url: ?string}> $breadcrumbs
 */
$this->layout('admin::layouts/app');
?>
<?php $this->start('content'); ?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => 'Zones de blocs',
    'subtitle' => 'Composez la barre latérale et le pied de page avec Prime Editor et les blocs dynamiques.',
    'breadcrumbs' => $breadcrumbs,
])) ?>

<?php if ($declaredZones === []): ?>
    <div class="pc-empty-state">
        <p class="pc-empty-state__title"><?= $this->e($this->t('admin.appearance.zones.aucune_zone_declaree')) ?></p>
        <p class="pc-empty-state__desc"><?= $this->raw($this->t('admin.appearance.zones.theme_actif_aucune_zone')) ?></p>
    </div>
<?php else: ?>
    <div class="pc-zones-layout" data-prime-zones-page>
        <aside class="pc-card" aria-label="<?= t('admin.attr.liste_des_zones_du_theme') ?>">
            <div class="pc-card__header">
                <h2 class="pc-card__title"><?= $this->e($this->t('admin.appearance.zones.zones_theme')) ?></h2>
            </div>
            <nav class="pc-zones-nav" aria-label="<?= t('admin.attr.choisir_une_zone_a_modifier') ?>">
                <?php foreach ($declaredZones as $zSlug => $zMeta): ?>
                    <?php $active = $zSlug === $selectedSlug; ?>
                    <a
                        href="/admin/appearance/zones?zone=<?= $this->e(rawurlencode($zSlug)) ?>"
                        class="pc-zones-nav__item<?= $active ? ' is-active' : '' ?>"
                        <?= $active ? 'aria-current="page"' : '' ?>
                    >
                        <span class="pc-zones-nav__label"><?= $this->e($this->t((string) $zMeta['label'])) ?></span>
                        <span class="pc-badge pc-badge--neutral"><?= $this->e((string) ($zoneCounts[$zSlug] ?? 0)) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php if ($dynamicCatalog !== []): ?>
                <div class="pc-card__body pc-zones-dyn-panel">
                    <h3 class="pc-text-xs pc-font-semibold pc-text-muted"><?= t('admin.appearance.zones.dynamic_blocks') ?></h3>
                    <div class="pc-stack pc-stack--xs">
                        <?php foreach ($dynamicCatalog as $dyn): ?>
                            <button
                                type="button"
                                class="pc-btn pc-btn--secondary pc-btn--sm pc-zones-dyn-btn"
                                data-prime-zone-add-dynamic="<?= $this->e($dyn['name']) ?>"
                                data-prime-zone-dyn-defaults="<?= $this->e(json_encode($dyn['defaults'], JSON_UNESCAPED_UNICODE) ?: '{}') ?>"
                            >
                                <?= $this->raw($this->icon('plus', 14)) ?>
                                <span><?= $this->e($dyn['label']) ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </aside>

        <section class="pc-card">
            <?php if ($selectedZone !== null): ?>
                <header class="pc-card__header">
                    <div>
                        <h2 class="pc-card__title"><?= $this->e($this->t((string) $selectedZone['label'])) ?></h2>
                        <?php if ($selectedZone['description'] !== ''): ?>
                            <p class="pc-text-xs pc-text-muted"><?= $this->e($selectedZone['description']) ?></p>
                        <?php endif; ?>
                    </div>
                </header>

                <form method="post" action="/admin/appearance/zones/<?= $this->e(rawurlencode($selectedSlug)) ?>/save" class="pc-card__body pc-stack pc-stack--md" data-prime-zone-form>
                    <?= $this->raw($this->csrfField()) ?>
                    <div class="pc-ed-root" data-prime-editor>
                        <script type="application/json" data-prime-editor-config><?= $this->raw($editorConfigJson) ?></script>
                        <textarea name="content_json" hidden data-prime-editor-input><?= $this->e($zoneContentJson) ?></textarea>
                        <div class="pc-ed-canvas" data-prime-editor-canvas></div>
                        <div class="pc-ed-add-bar">
                            <button type="button" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-editor-add>
                                <?= $this->raw($this->icon('plus', 16)) ?>
                                <span><?= $this->e($this->t('admin.appearance.zones.ajouter_bloc')) ?></span>
                            </button>
                        </div>
                        <div class="pc-sr-only" aria-live="polite" data-prime-editor-live></div>
                    </div>

                    <div class="pc-zones-footer">
                        <button type="submit" class="pc-btn pc-btn--primary"><?= $this->e($this->t('admin.appearance.zones.enregistrer_zone')) ?></button>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    </div>
<?php endif; ?>

<?php $this->stop(); ?>
