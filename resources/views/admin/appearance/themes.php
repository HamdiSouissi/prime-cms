<?php

declare(strict_types=1);

/**
 * Installed themes management view (`GET /admin/appearance/themes`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var list<\Prime\Themes\ThemeInfo> $themes
 * @var ?\Prime\Themes\ThemeInfo $activeTheme
 * @var bool $allowUpload
 * @var int $maxUploadMb
 * @var bool $safeMode
 * @var ?array{slug: string, message: string} $fallbackError
 * @var list<array{label: string, url: ?string}> $breadcrumbs
 */
$this->layout('admin::layouts/app');

$headerActions = '';
if ($allowUpload) {
    $headerActions = '<button type="button" class="pc-btn pc-btn--primary" data-prime-drawer-open="prime-theme-upload-drawer">'
        . $this->icon('upload', 16)
        . '<span>Installer un thème (ZIP)</span></button>';
}
?>
<?php $this->start('content'); ?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => 'Thèmes',
    'subtitle' => sprintf(
        '%d thème%s installé%s · Actif : %s',
        count($themes),
        count($themes) > 1 ? 's' : '',
        count($themes) > 1 ? 's' : '',
        $activeTheme !== null ? $activeTheme->name() : 'Aurora',
    ),
    'breadcrumbs' => $breadcrumbs,
    'actions' => $headerActions,
])) ?>

<div class="pc-stack pc-stack--lg" data-prime-themes-page>
    <?php if ($safeMode): ?>
        <div class="pc-alert pc-alert--warning" role="status">
            <span class="pc-alert__icon" aria-hidden="true"><?= $this->raw($this->icon('shield', 18)) ?></span>
            <div class="pc-alert__body">
                <p class="pc-alert__title"><?= $this->e($this->t('admin.appearance.themes.mode_sans_echec_actif_prime')) ?></p>
                <p><?= $this->e($this->t('admin.appearance.themes.theme_defaut_aurora_force_instance')) ?></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($themes === []): ?>
        <div class="pc-empty-state">
            <p class="pc-empty-state__title"><?= $this->e($this->t('admin.appearance.themes.aucun_theme_trouve')) ?></p>
            <p class="pc-empty-state__desc"><?= $this->raw($this->t('admin.appearance.themes.ajoutez_theme_dossier_archive')) ?></p>
        </div>
    <?php else: ?>
        <div class="pc-themes-grid">
            <?php foreach ($themes as $theme): ?>
                <?php
                    $manifest = $theme->manifest;
                $canDelete = !$theme->active && !$theme->isParentOfActive && $theme->slug !== 'aurora';
                $detailId = 'prime-theme-detail-' . $theme->slug;
                ?>
                <article class="pc-theme-card<?= $theme->active ? ' pc-theme-card--active' : '' ?><?= !$theme->valid ? ' pc-theme-card--invalid' : '' ?>">
                    <div class="pc-theme-card__shot">
                        <?php if ($theme->screenshotUrl !== null): ?>
                            <img src="<?= $this->e($theme->screenshotUrl) ?>" alt="Aperçu du thème <?= $this->e($theme->name()) ?>" loading="lazy">
                        <?php else: ?>
                            <div class="pc-theme-card__placeholder" aria-hidden="true">
                                <?= $this->raw($this->icon('palette', 32)) ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!$theme->valid || !$theme->compatible || $theme->parentSlug() !== null): ?>
                            <div class="pc-theme-card__badges">
                                <?php if (!$theme->valid): ?>
                                    <span class="pc-badge pc-badge--danger"><?= $this->e($this->t('admin.appearance.themes.invalide')) ?></span>
                                <?php elseif (!$theme->compatible): ?>
                                    <span class="pc-badge pc-badge--warning">Incompatible</span>
                                <?php endif; ?>
                                <?php if ($theme->parentSlug() !== null): ?>
                                    <span class="pc-badge pc-badge--neutral"><?= $this->e($this->t('admin.appearance.themes.enfant')) ?> <?= $this->e($theme->parentSlug()) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="pc-theme-card__body">
                        <div class="pc-theme-card__head">
                            <div class="pc-theme-card__title-group">
                                <h2 class="pc-theme-card__title"><?= $this->e($theme->name()) ?></h2>
                                <?php if ($theme->active): ?>
                                    <span class="pc-badge pc-badge--success">Actif</span>
                                <?php endif; ?>
                            </div>
                            <span class="pc-text-xs pc-text-muted">v<?= $this->e($theme->version()) ?></span>
                        </div>

                        <?php if ($theme->description() !== ''): ?>
                            <p class="pc-theme-card__desc"><?= $this->e($theme->description()) ?></p>
                        <?php endif; ?>

                        <?php if ($theme->errors !== []): ?>
                            <p class="pc-field__error"><?= $this->e(implode(' ', $theme->errors)) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="pc-theme-card__footer">
                        <?php if ($theme->active): ?>
                            <a href="/admin/appearance/customizer" class="pc-btn pc-btn--secondary pc-btn--sm">
                                <?= $this->raw($this->icon('sparkles', 15)) ?>
                                <span>Personnaliser</span>
                            </a>
                        <?php elseif ($theme->valid && $theme->compatible): ?>
                            <form method="post" action="/admin/appearance/themes/<?= $this->e($theme->slug) ?>/activate">
                                <?= $this->raw($this->csrfField()) ?>
                                <button type="submit" class="pc-btn pc-btn--secondary pc-btn--sm">Activer</button>
                            </form>
                        <?php endif; ?>

                        <button type="button" class="pc-btn pc-btn--ghost pc-btn--sm" data-prime-drawer-open="<?= $this->e($detailId) ?>">
                            <?= $this->e($this->t('admin.appearance.themes.details')) ?>
                        </button>

                        <?php if ($canDelete): ?>
                            <form method="post" action="/admin/appearance/themes/<?= $this->e($theme->slug) ?>/delete" class="pc-theme-card__delete-form">
                                <?= $this->raw($this->csrfField()) ?>
                                <button type="submit" class="pc-btn pc-btn--danger-outline pc-btn--sm" data-prime-confirm="Supprimer définitivement le thème « <?= $this->e($theme->name()) ?> » ?">
                                    <?= $this->e($this->t('core.actions.delete')) ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>

                <!-- Theme details drawer -->
                <div class="pc-drawer" id="<?= $this->e($detailId) ?>" role="dialog" aria-modal="true" aria-labelledby="<?= $this->e($detailId) ?>-title" hidden>
                    <div class="pc-drawer__backdrop" data-prime-drawer-close></div>
                    <div class="pc-drawer__panel">
                        <header class="pc-drawer__header">
                            <h2 class="pc-drawer__title" id="<?= $this->e($detailId) ?>-title"><?= $this->e($theme->name()) ?></h2>
                            <button type="button" class="pc-btn pc-btn--ghost pc-btn--icon" aria-label="Fermer" data-prime-drawer-close>
                                <?= $this->raw($this->icon('x', 18)) ?>
                            </button>
                        </header>
                        <div class="pc-drawer__body pc-stack pc-stack--md">
                            <?php if ($theme->screenshotUrl !== null): ?>
                                <img src="<?= $this->e($theme->screenshotUrl) ?>" alt="Aperçu <?= $this->e($theme->name()) ?>" class="pc-theme-detail__img">
                            <?php endif; ?>
                            <p><?= $this->e($theme->description()) ?></p>
                            <dl class="pc-theme-detail__meta">
                                <div><dt>Identifiant</dt><dd><code><?= $this->e($theme->slug) ?></code></dd></div>
                                <div><dt>Version</dt><dd><?= $this->e($theme->version()) ?></dd></div>
                                <?php if ($manifest !== null && $manifest->author !== ''): ?>
                                    <div><dt>Auteur</dt><dd><?= $this->e($manifest->author) ?></dd></div>
                                <?php endif; ?>
                                <?php if ($manifest !== null && $manifest->authorUrl !== null && $manifest->authorUrl !== ''): ?>
                                    <div><dt>Site auteur</dt><dd><a href="<?= $this->e($manifest->authorUrl) ?>" target="_blank" rel="noopener"><?= $this->e($manifest->authorUrl) ?></a></dd></div>
                                <?php endif; ?>
                                <?php if ($manifest !== null): ?>
                                    <div><dt><?= $this->e($this->t('admin.appearance.themes.emplacements_menus')) ?></dt><dd><?= $manifest->menus !== [] ? $this->e(implode(', ', array_values($manifest->menus))) : 'Aucun' ?></dd></div>
                                    <div><dt><?= $this->e($this->t('admin.appearance.themes.zones_blocs')) ?></dt><dd><?= $manifest->zones !== [] ? $this->e(implode(', ', array_column($manifest->zones, 'label'))) : 'Aucune' ?></dd></div>
                                <?php endif; ?>
                            </dl>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($allowUpload): ?>
    <div class="pc-drawer" id="prime-theme-upload-drawer" role="dialog" aria-modal="true" aria-labelledby="prime-theme-upload-title" hidden>
        <div class="pc-drawer__backdrop" data-prime-drawer-close></div>
        <div class="pc-drawer__panel">
            <header class="pc-drawer__header">
                <h2 class="pc-drawer__title" id="prime-theme-upload-title"><?= $this->e($this->t('admin.appearance.themes.installer_theme_zip')) ?></h2>
                <button type="button" class="pc-btn pc-btn--ghost pc-btn--icon" aria-label="Fermer" data-prime-drawer-close>
                    <?= $this->raw($this->icon('x', 18)) ?>
                </button>
            </header>
            <form method="post" action="/admin/appearance/themes/upload" enctype="multipart/form-data" class="pc-drawer__body pc-stack pc-stack--md">
                <?= $this->raw($this->csrfField()) ?>
                <p class="pc-text-sm pc-text-muted">
                    <?= $this->raw($this->t('admin.appearance.themes.televersez_archive_zip_contenant_dossier', ['size' => $maxUploadMb])) ?>
                </p>
                <div class="pc-field">
                    <label class="pc-label" for="prime-theme-zip"><?= $this->e($this->t('admin.appearance.themes.archive_zip_theme')) ?></label>
                    <input id="prime-theme-zip" type="file" name="theme_zip" accept=".zip,application/zip" class="pc-input" required>
                </div>
                <label class="pc-checkbox">
                    <input type="checkbox" name="replace_existing" value="1">
                    <span><?= $this->e($this->t('admin.appearance.themes.remplacer_si_theme_portant_slug')) ?></span>
                </label>
                <div class="pc-drawer__footer">
                    <button type="button" class="pc-btn pc-btn--secondary" data-prime-drawer-close><?= $this->e($this->t('core.actions.cancel')) ?></button>
                    <button type="submit" class="pc-btn pc-btn--primary"><?= $this->e($this->t('admin.appearance.themes.installer_theme')) ?></button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php $this->stop(); ?>
