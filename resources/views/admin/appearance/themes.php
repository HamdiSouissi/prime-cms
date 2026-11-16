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
                <p class="pc-alert__title">Mode sans échec actif (PRIME_SAFE_MODE)</p>
                <p>Le thème par défaut Aurora est forcé pour cette instance.</p>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($fallbackError !== null): ?>
        <div class="pc-alert pc-alert--danger" role="alert">
            <span class="pc-alert__icon" aria-hidden="true"><?= $this->raw($this->icon('alert-circle', 18)) ?></span>
            <div class="pc-alert__body">
                <p class="pc-alert__title">Bascule de sécurité sur Aurora</p>
                <p>Le thème « <?= $this->e($fallbackError['slug']) ?> » a provoqué une erreur : <?= $this->e($fallbackError['message']) ?></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($themes === []): ?>
        <div class="pc-empty-state">
            <p class="pc-empty-state__title">Aucun thème trouvé</p>
            <p class="pc-empty-state__desc">Ajoutez un thème dans <code>content/themes/</code> ou téléversez une archive ZIP.</p>
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

                        <div class="pc-theme-card__badges">
                            <?php if ($theme->active): ?>
                                <span class="pc-badge pc-badge--success">Actif</span>
                            <?php elseif (!$theme->valid): ?>
                                <span class="pc-badge pc-badge--danger">Invalide</span>
                            <?php elseif (!$theme->compatible): ?>
                                <span class="pc-badge pc-badge--warning">Incompatible</span>
                            <?php endif; ?>
                            <?php if ($theme->parentSlug() !== null): ?>
                                <span class="pc-badge pc-badge--neutral">Enfant de <?= $this->e($theme->parentSlug()) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="pc-theme-card__body">
                        <div class="pc-theme-card__head">
                            <h2 class="pc-theme-card__title"><?= $this->e($theme->name()) ?></h2>
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
                            Détails
                        </button>

                        <?php if ($canDelete): ?>
                            <form method="post" action="/admin/appearance/themes/<?= $this->e($theme->slug) ?>/delete" class="pc-theme-card__delete-form">
                                <?= $this->raw($this->csrfField()) ?>
                                <button type="submit" class="pc-btn pc-btn--ghost pc-btn--danger pc-btn--sm" data-prime-confirm="Supprimer définitivement le thème « <?= $this->e($theme->name()) ?> » ?">
                                    Supprimer
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
                                <?php if ($manifest !== null && $manifest->authorName !== ''): ?>
                                    <div><dt>Auteur</dt><dd><?= $this->e($manifest->authorName) ?></dd></div>
                                <?php endif; ?>
                                <?php if ($manifest !== null && $manifest->license !== ''): ?>
                                    <div><dt>Licence</dt><dd><?= $this->e($manifest->license) ?></dd></div>
                                <?php endif; ?>
                                <?php if ($manifest !== null): ?>
                                    <div><dt>Emplacements de menus</dt><dd><?= $manifest->menus !== [] ? $this->e(implode(', ', array_values($manifest->menus))) : 'Aucun' ?></dd></div>
                                    <div><dt>Zones de blocs</dt><dd><?= $manifest->zones !== [] ? $this->e(implode(', ', array_column($manifest->zones, 'label'))) : 'Aucune' ?></dd></div>
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
                <h2 class="pc-drawer__title" id="prime-theme-upload-title">Installer un thème (ZIP)</h2>
                <button type="button" class="pc-btn pc-btn--ghost pc-btn--icon" aria-label="Fermer" data-prime-drawer-close>
                    <?= $this->raw($this->icon('x', 18)) ?>
                </button>
            </header>
            <form method="post" action="/admin/appearance/themes/upload" enctype="multipart/form-data" class="pc-drawer__body pc-stack pc-stack--md">
                <?= $this->raw($this->csrfField()) ?>
                <p class="pc-text-sm pc-text-muted">
                    Téléversez une archive ZIP contenant un dossier racine portant le slug du thème, un manifeste <code>theme.json</code> valide et <code>src/Theme.php</code> (max. <?= $this->e((string) $maxUploadMb) ?> Mo).
                </p>
                <div class="pc-field">
                    <label class="pc-label" for="prime-theme-zip">Archive ZIP du thème</label>
                    <input id="prime-theme-zip" type="file" name="theme_zip" accept=".zip,application/zip" class="pc-input" required>
                </div>
                <label class="pc-checkbox">
                    <input type="checkbox" name="replace_existing" value="1">
                    <span>Remplacer si un thème portant ce slug est déjà installé</span>
                </label>
                <div class="pc-drawer__footer">
                    <button type="button" class="pc-btn pc-btn--secondary" data-prime-drawer-close>Annuler</button>
                    <button type="submit" class="pc-btn pc-btn--primary">Installer le thème</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php $this->stop(); ?>
