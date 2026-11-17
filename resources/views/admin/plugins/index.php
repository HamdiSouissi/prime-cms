<?php

declare(strict_types=1);

/**
 * Plugins management view (`GET /admin/plugins`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var list<\Prime\Plugins\PluginInfo> $plugins
 * @var array<string, \Prime\Plugins\PluginInfo> $allPlugins
 * @var array{all: int, active: int, inactive: int, errored: int} $counts
 * @var array{status: string, q: string} $filters
 * @var bool $allowUpload
 * @var int $maxUploadMb
 * @var bool $safeMode
 * @var list<array{label: string, url: ?string}> $breadcrumbs
 */
$this->layout('admin::layouts/app');

$statusTabs = [
    'all' => ['label' => $this->t('admin.shared.tous'), 'count' => $counts['all']],
    'active' => ['label' => $this->t('admin.shared.actifs'), 'count' => $counts['active']],
    'inactive' => ['label' => $this->t('admin.shared.inactifs'), 'count' => $counts['inactive']],
    'errored' => ['label' => $this->t('admin.shared.en_erreur'), 'count' => $counts['errored']],
];

$buildTabUrl = static function (string $status) use ($filters): string {
    $params = [];
    if ($status !== 'all') {
        $params['status'] = $status;
    }
    if ($filters['q'] !== '') {
        $params['q'] = $filters['q'];
    }

    return $params === [] ? '/admin/plugins' : '/admin/plugins?' . http_build_query($params);
};

$headerActions = '';
if ($allowUpload) {
    $headerActions = '<button type="button" class="pc-btn pc-btn--primary" data-prime-drawer-open="prime-plugin-upload-drawer">'
        . $this->icon('upload', 16)
        . '<span>' . $this->e($this->t('admin.view_plugins_index.ajouter_un_plugin')) . '</span></button>';
}
?>
<?php $this->start('content'); ?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => 'Plugins',
    'subtitle' => sprintf(
        $this->t('admin.view_plugins_index.d_plugin_s_installe_s_d_actif_s_s'),
        $counts['all'],
        $counts['all'] > 1 ? 's' : '',
        $counts['all'] > 1 ? 's' : '',
        $counts['active'],
        $counts['active'] > 1 ? 's' : '',
        $counts['errored'] > 0 ? sprintf(' · %d en erreur', $counts['errored']) : '',
    ),
    'breadcrumbs' => $breadcrumbs,
    'actions' => $headerActions,
])) ?>

<div class="pc-stack pc-stack--lg" data-prime-plugins-page>
    <!-- Status filter tabs & search bar -->
    <div class="pc-plugins-toolbar">
        <nav class="pc-plugins-tabs" aria-label="<?= t('admin.attr.filtrer_les_plugins_par_statut') ?>">
            <?php foreach ($statusTabs as $tabKey => $tabMeta): ?>
                <?php $isActiveTab = $filters['status'] === $tabKey; ?>
                <a
                    href="<?= $this->e($buildTabUrl($tabKey)) ?>"
                    class="pc-plugins-tab<?= $isActiveTab ? ' pc-plugins-tab--active' : '' ?><?= ($tabKey === 'errored' && $tabMeta['count'] > 0) ? ' pc-plugins-tab--danger' : '' ?>"
                    <?= $isActiveTab ? 'aria-current="page"' : '' ?>
                >
                    <span><?= $this->e($tabMeta['label']) ?></span>
                    <span class="pc-plugins-tab__count"><?= $this->e((string) $tabMeta['count']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <form method="get" action="/admin/plugins" class="pc-plugins-search" role="search" aria-label="<?= t('admin.attr.rechercher_un_plugin') ?>">
            <?php if ($filters['status'] !== 'all'): ?>
                <input type="hidden" name="status" value="<?= $this->e($filters['status']) ?>">
            <?php endif; ?>
            <label class="pc-sr-only" for="plugins-search-input"><?= $this->e($this->t('admin.plugins.index.rechercher_plugin')) ?></label>
            <div class="pc-plugins-search__field">
                <span class="pc-plugins-search__icon" aria-hidden="true"><?= $this->raw($this->icon('search', 16)) ?></span>
                <input
                    id="plugins-search-input"
                    type="search"
                    name="q"
                    value="<?= $this->e($filters['q']) ?>"
                    class="pc-input pc-plugins-search__input"
                    placeholder="<?= $this->e($this->t('admin.plugins.index.rechercher_nom_slug_auteur')) ?>"
                    autocomplete="off"
                >
            </div>
            <?php if ($filters['q'] !== ''): ?>
                <a href="<?= $this->e($filters['status'] !== 'all' ? '/admin/plugins?status=' . rawurlencode($filters['status']) : '/admin/plugins') ?>" class="pc-btn pc-btn--ghost pc-btn--sm">
                    Effacer
                </a>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($plugins === []): ?>
        <?php if ($counts['all'] === 0): ?>
            <?= $this->raw($this->insert('admin::components/empty-state', [
                'icon' => 'plug',
                'title' => $this->t('admin.view_plugins_index.aucun_plugin_installe'),
                'description' => $this->t('admin.view_plugins_index.etendez_prime_cms_avec_des_plugins_officiels_ou_sur_mesure_e'),
                'actionHtml' => $allowUpload
                    ? '<button type="button" class="pc-btn pc-btn--secondary" data-prime-drawer-open="prime-plugin-upload-drawer">' . $this->icon('upload', 16) . '<span>Téléverser une archive ZIP</span></button>'
                    : null,
            ])) ?>
        <?php else: ?>
            <?= $this->raw($this->insert('admin::components/empty-state', [
                'icon' => 'search',
                'title' => $this->t('admin.view_plugins_index.aucun_plugin_ne_correspond_a_ce_filtre'),
                'description' => $this->t('admin.view_plugins_index.essayez_un_autre_mot_cle_ou_reinitialisez_les_filtres_de_sta'),
                'actionHtml' => '<a class="pc-btn pc-btn--secondary" href="/admin/plugins">Afficher tous les plugins</a>',
            ])) ?>
        <?php endif; ?>
    <?php else: ?>
        <div class="pc-plugins-grid">
            <?php foreach ($plugins as $plugin): ?>
                <?php
                $manifest = $plugin->manifest;
                $issues = $plugin->allIssues();
                $statusVariant = match ($plugin->status) {
                    'active' => 'success',
                    'errored' => 'danger',
                    default => 'neutral',
                };
                $statusLabel = match ($plugin->status) {
                    'active' => $this->t('admin.shared.actif'),
                    'errored' => $this->t('admin.shared.en_erreur'),
                    default => 'Inactif',
                };
                ?>
                <article class="pc-card pc-plugin-card pc-plugin-card--<?= $this->e($plugin->status) ?>" data-prime-plugin-slug="<?= $this->e($plugin->slug) ?>">
                    <div class="pc-plugin-card__header">
                        <div class="pc-plugin-card__identity">
                            <?php if ($plugin->iconUrl !== null): ?>
                                <div class="pc-plugin-card__icon pc-plugin-card__icon--image">
                                    <img src="<?= $this->e($plugin->iconUrl) ?>" alt="" width="40" height="40" loading="lazy">
                                </div>
                            <?php else: ?>
                                <div class="pc-plugin-card__icon pc-plugin-card__icon--<?= $this->e($plugin->accent()) ?>" aria-hidden="true">
                                    <?= $this->e($manifest !== null ? $manifest->initials() : strtoupper(substr($plugin->slug, 0, 2))) ?>
                                </div>
                            <?php endif; ?>

                            <div class="pc-plugin-card__heading">
                                <div class="pc-plugin-card__title-row">
                                    <h2 class="pc-plugin-card__name"><?= $this->e($plugin->name()) ?></h2>
                                    <span class="pc-plugin-card__version">v<?= $this->e($plugin->version()) ?></span>
                                </div>
                                <div class="pc-plugin-card__meta">
                                    <code class="pc-plugin-card__slug"><?= $this->e($plugin->slug) ?></code>
                                    <?php if ($manifest !== null): ?>
                                        <span aria-hidden="true">·</span>
                                        <span>Par
                                            <?php if ($manifest->authorUrl !== null): ?>
                                                <a href="<?= $this->e($manifest->authorUrl) ?>" target="_blank" rel="noopener noreferrer" class="pc-plugin-card__author-link">
                                                    <?= $this->e($manifest->authorName) ?>
                                                </a>
                                            <?php else: ?>
                                                <?= $this->e($manifest->authorName) ?>
                                            <?php endif; ?>
                                        </span>
                                        <span aria-hidden="true">·</span>
                                        <span><?= $this->e($manifest->license) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="pc-plugin-card__badges">
                            <?= $this->raw($this->insert('admin::components/badge', [
                                'label' => $statusLabel,
                                'variant' => $statusVariant,
                                'dot' => true,
                            ])) ?>
                            <?php if ($plugin->needsUpdate && $plugin->installedVersion !== null): ?>
                                <?= $this->raw($this->insert('admin::components/badge', [
                                    'label' => $this->t('admin.shared.mise_a_jour') . ' ' . $plugin->installedVersion . ' → ' . $plugin->version(),
                                    'variant' => 'info',
                                ])) ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="pc-plugin-card__body">
                        <?php if ($manifest !== null): ?>
                            <p class="pc-plugin-card__desc"><?= $this->e($manifest->description) ?></p>
                        <?php else: ?>
                            <p class="pc-plugin-card__desc pc-plugin-card__desc--muted"><?= $this->raw($this->t('admin.plugins.index.manifeste_invalide_incomplet')) ?></p>
                        <?php endif; ?>

                        <?php if ($manifest !== null && ($manifest->dependencies !== [] || $plugin->requiredBy !== [])): ?>
                            <div class="pc-plugin-card__deps">
                                <?php if ($manifest->dependencies !== []): ?>
                                    <div class="pc-plugin-card__dep-row">
                                        <span class="pc-plugin-card__dep-label"><?= $this->e($this->t('admin.plugins.index.necessite')) ?></span>
                                        <?php foreach ($manifest->dependencies as $depSlug => $depConstraint): ?>
                                            <?php
                                            $depInfo = $allPlugins[$depSlug] ?? null;
                                            $depLabel = ($depInfo !== null ? $depInfo->name() : $depSlug) . ' (' . $depConstraint . ')';
                                            $depOk = $depInfo !== null && $depInfo->isActive();
                                            ?>
                                            <span class="pc-plugin-dep-chip<?= $depOk ? ' pc-plugin-dep-chip--ok' : ' pc-plugin-dep-chip--warn' ?>">
                                                <?= $this->e($depLabel) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($plugin->requiredBy !== []): ?>
                                    <div class="pc-plugin-card__dep-row">
                                        <span class="pc-plugin-card__dep-label"><?= t('admin.plugins.required_by') ?></span>
                                        <?php foreach ($plugin->requiredBy as $reqBySlug): ?>
                                            <?php $reqInfo = $allPlugins[$reqBySlug] ?? null; ?>
                                            <span class="pc-plugin-dep-chip">
                                                <?= $this->e($reqInfo !== null ? $reqInfo->name() : $reqBySlug) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($plugin->isErrored() && $plugin->lastError !== null && $plugin->lastError !== ''): ?>
                            <div class="pc-plugin-card__issues pc-plugin-card__issues--danger" role="alert">
                                <div class="pc-plugin-card__issues-title">
                                    <?= $this->raw($this->icon('alert-circle', 15)) ?>
                                    <span><?= $this->e($this->t('admin.plugins.index.derniere_erreur_enregistree')) ?><?= $plugin->erroredAt !== null ? ' (' . $this->e($plugin->erroredAt) . ' UTC)' : '' ?></span>
                                </div>
                                <p class="pc-plugin-card__error-msg"><?= $this->e($plugin->lastError) ?></p>
                            </div>
                        <?php endif; ?>

                        <?php if ($issues !== []): ?>
                            <div class="pc-plugin-card__issues pc-plugin-card__issues--warning">
                                <div class="pc-plugin-card__issues-title">
                                    <?= $this->raw($this->icon('alert-triangle', 15)) ?>
                                    <span><?= $this->e($this->choice('admin.plugins.index.points_a_resoudre', count($issues))) ?></span>
                                </div>
                                <ul class="pc-plugin-card__issues-list">
                                    <?php foreach ($issues as $issue): ?>
                                        <li><?= $this->e($issue) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if ($plugin->warnings !== []): ?>
                            <div class="pc-plugin-card__warnings">
                                <?php foreach ($plugin->warnings as $warn): ?>
                                    <p class="pc-plugin-card__warning-item"><?= $this->raw($this->icon('info', 14)) ?> <span><?= $this->e($warn) ?></span></p>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <footer class="pc-plugin-card__footer">
                        <div class="pc-plugin-card__actions-left">
                            <?php if ($plugin->isActive()): ?>
                                <form method="post" action="/admin/plugins/<?= $this->e(rawurlencode($plugin->slug)) ?>/deactivate" data-prime-plugin-action-form>
                                    <?= $this->raw($this->csrfField()) ?>
                                    <button
                                        type="submit"
                                        class="pc-btn pc-btn--secondary pc-btn--sm"
                                        <?= !$plugin->canDeactivate() ? 'disabled title="' . $this->e($this->t('admin.attr.desactivez_d_abord_les_plugins_qui_dependent_de_celui_ci')) . '"' : '' ?>
                                    >
                                        <span><?= $this->e($this->t('admin.plugins.index.desactiver')) ?></span>
                                    </button>
                                </form>

                                <?php if ($plugin->settingsUrl !== null): ?>
                                    <a href="<?= $this->e($plugin->settingsUrl) ?>" class="pc-btn pc-btn--ghost pc-btn--sm">
                                        <?= $this->raw($this->icon('settings', 15)) ?>
                                        <span><?= $this->e($this->t('admin.plugins.index.reglages')) ?></span>
                                    </a>
                                <?php endif; ?>
                            <?php else: ?>
                                <form method="post" action="/admin/plugins/<?= $this->e(rawurlencode($plugin->slug)) ?>/activate" data-prime-plugin-action-form>
                                    <?= $this->raw($this->csrfField()) ?>
                                    <button
                                        type="submit"
                                        class="pc-btn pc-btn--secondary pc-btn--sm"
                                        <?= (!$plugin->canActivate() || $safeMode) ? 'disabled' : '' ?>
                                    >
                                        <?= $this->raw($this->icon('check', 15)) ?>
                                        <span><?= $plugin->isErrored() ? $this->t('admin.view_plugins_index.reessayer_l_activation') : 'Activer' ?></span>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>

                        <?php if (!$plugin->isActive()): ?>
                            <button
                                type="button"
                                class="pc-btn pc-btn--ghost pc-btn--sm pc-plugin-card__delete-btn"
                                data-prime-plugin-delete
                                data-prime-plugin-slug="<?= $this->e($plugin->slug) ?>"
                                data-prime-plugin-name="<?= $this->e($plugin->name()) ?>"
                                <?= !$plugin->canUninstall() ? 'disabled title="' . $this->e($this->t('admin.attr.d_autres_plugins_installes_dependent_de_ce_plugin')) . '"' : '' ?>
                            >
                                <?= $this->raw($this->icon('trash', 15)) ?>
                                <span><?= $this->e($this->t('core.actions.delete')) ?></span>
                            </button>
                        <?php endif; ?>
                    </footer>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($allowUpload): ?>
    <?php
    ob_start();
    ?>
    <form
        id="prime-plugin-upload-form"
        method="post"
        action="/admin/plugins/upload"
        enctype="multipart/form-data"
        class="pc-stack pc-stack--md"
        data-prime-plugin-upload-form
        data-prime-max-mb="<?= $this->e((string) $maxUploadMb) ?>"
    >
        <?= $this->raw($this->csrfField()) ?>
        <input type="hidden" name="replace_existing" value="0" data-prime-upload-replace>
        <input type="hidden" name="force_replace" value="0" data-prime-upload-force>

        <p class="pc-text-muted">
            <?= $this->raw($this->t('admin.plugins.index.televersez_archive_dossier_racine')) ?>
            <?= $this->e($this->t('admin.plugins.index.plugin_sera_installe_a_etat')) ?>
        </p>

        <div class="pc-dropzone" data-prime-dropzone tabindex="0" role="button" aria-label="<?= t('admin.attr.deposer_une_archive_zip_ou_cliquer_pour_choisir_un_fichier') ?>">
            <input
                type="file"
                id="prime-plugin-zip-input"
                name="plugin_zip"
                accept=".zip,application/zip"
                class="pc-dropzone__input"
                data-prime-dropzone-input
                required
            >
            <div class="pc-dropzone__icon" aria-hidden="true">
                <?= $this->raw($this->icon('upload', 28)) ?>
            </div>
            <p class="pc-dropzone__title" data-prime-dropzone-title><?= $this->e($this->t('admin.plugins.index.deposez_archive_zip_ici')) ?></p>
            <p class="pc-dropzone__hint" data-prime-dropzone-hint><?= t('admin.plugins.dropzone_hint', ['size' => $this->e((string) $maxUploadMb)]) ?></p>
        </div>

        <div class="pc-upload-progress" data-prime-upload-progress hidden>
            <div class="pc-upload-progress__row">
                <span data-prime-upload-status><?= $this->e($this->t('admin.plugins.index.envoi_validation_archive')) ?></span>
                <span data-prime-upload-percent>0 %</span>
            </div>
            <div class="pc-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-prime-upload-bar-wrap>
                <div class="pc-progress__bar" style="width: 0%" data-prime-upload-bar></div>
            </div>
        </div>

        <div class="pc-alert pc-alert--danger" role="alert" data-prime-upload-error hidden>
            <span class="pc-alert__icon" aria-hidden="true"><?= $this->raw($this->icon('alert-circle', 18)) ?></span>
            <div class="pc-alert__body">
                <p class="pc-alert__title"><?= $this->e($this->t('admin.plugins.index.installation_refusee')) ?></p>
                <p class="pc-alert__text" data-prime-upload-error-text></p>
            </div>
        </div>

        <div class="pc-alert pc-alert--warning pc-upload-conflict" role="alert" data-prime-upload-conflict hidden>
            <span class="pc-alert__icon" aria-hidden="true"><?= $this->raw($this->icon('alert-triangle', 18)) ?></span>
            <div class="pc-alert__body">
                <p class="pc-alert__title"><?= $this->e($this->t('admin.plugins.index.plugin_deja_installe')) ?></p>
                <p class="pc-alert__text" data-prime-upload-conflict-text></p>
                <div class="pc-upload-conflict__actions">
                    <button type="button" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-upload-confirm-replace>
                        <?= $this->e($this->t('admin.plugins.index.remplacer_plugin_existant')) ?>
                    </button>
                </div>
            </div>
        </div>

        <div class="pc-drawer-form-actions">
            <button type="button" class="pc-btn pc-btn--ghost" data-prime-drawer-close><?= $this->e($this->t('core.actions.cancel')) ?></button>
            <button type="submit" class="pc-btn pc-btn--secondary" data-prime-upload-submit>
                <?= $this->raw($this->icon('upload', 16)) ?>
                <span><?= t('admin.plugins.install_archive') ?></span>
            </button>
        </div>
    </form>
    <?php
    $drawerBody = (string) ob_get_clean();
    ?>

    <?= $this->raw($this->insert('admin::components/drawer', [
        'id' => 'prime-plugin-upload-drawer',
        'title' => $this->t('admin.view_plugins_index.ajouter_un_plugin_zip'),
        'body' => $drawerBody,
    ])) ?>
<?php endif; ?>

<!-- Named uninstall confirmation modal -->
<?php
ob_start();
?>
<div class="pc-stack pc-stack--md">
    <p>
        <?= $this->e($this->t('admin.plugins.index.etes_point_supprimer_definitivement_plugin')) ?>
        <strong data-prime-delete-plugin-name></strong>
        (<code data-prime-delete-plugin-slug></code>).
    </p>
    <div class="pc-alert pc-alert--danger" role="alert">
        <span class="pc-alert__icon" aria-hidden="true"><?= $this->raw($this->icon('alert-triangle', 18)) ?></span>
        <div class="pc-alert__body">
            <p class="pc-alert__title"><?= $this->e($this->t('admin.plugins.index.action_irreversible_perte_donnees')) ?></p>
            <p class="pc-alert__text"><?= $this->raw($this->t('admin.plugins.index.tables_creees_migrations_reglages_enregistres')) ?></p>
        </div>
    </div>
</div>
<?php
$modalBody = (string) ob_get_clean();

ob_start();
?>
<button type="button" class="pc-btn pc-btn--ghost" data-prime-modal-close><?= $this->e($this->t('core.actions.cancel')) ?></button>
<form method="post" action="/admin/plugins" data-prime-delete-plugin-form>
    <?= $this->raw($this->csrfField()) ?>
    <button type="submit" class="pc-btn pc-btn--danger">
        <?= $this->raw($this->icon('trash', 15)) ?>
        <span><?= $this->e($this->t('admin.plugins.index.supprimer_definitivement')) ?></span>
    </button>
</form>
<?php
$modalFooter = (string) ob_get_clean();
?>

<?= $this->raw($this->insert('admin::components/modal', [
    'id' => 'prime-plugin-delete-modal',
    'title' => $this->t('admin.view_plugins_index.supprimer_le_plugin'),
    'body' => $modalBody,
    'footer' => $modalFooter,
])) ?>

<?php $this->stop(); ?>
