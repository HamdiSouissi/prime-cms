<?php

declare(strict_types=1);

/**
 * Dashboard home view (`GET /admin`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var list<array{label: string, value: string, icon: string, accent: string, trend: string, trendDirection: 'up'|'down'|'flat', hint: string, url: string}> $stats
 * @var list<array{title: string, meta: string, author: string, initials: string, status: string, statusVariant: string, accent: string}> $recentActivity
 * @var list<array{title: string, description: string, icon: string, accent: string, url: string}> $shortcuts
 * @var list<array{label: string, value: string, badge?: string, variant?: string}> $systemStatus
 */
$this->layout('admin::layouts/app');
$userName = (string) ($currentUser['name'] ?? 'Administrateur');
$showStyleguide = ($environment ?? '') === 'local' && !empty($currentUser['isAdmin']);
?>
<?php $this->start('content'); ?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => $this->t('admin.dashboard.index.greeting', ['name' => $userName]),
    'subtitle' => $this->t('admin.dashboard.index.subtitle', ['site' => $appName]),
    'actions' => $showStyleguide
        ? $this->insert('admin::components/button', [
            'label' => $this->t('admin.dashboard.index.explore_design_system'),
            'variant' => 'secondary',
            'icon' => 'sparkles',
            'href' => '/admin/styleguide',
        ])
        : '',
])) ?>

<div class="pc-stack pc-stack--lg">

    <?php if (!empty($criticalChecks)): ?>
        <div class="pc-alert pc-alert--danger" role="alert" data-prime-system-alert>
            <?= $this->raw($this->icon('alert-triangle', 18)) ?>
            <div>
                <p><strong><?= $this->e($this->choice('admin.system.dashboard_alert', count($criticalChecks))) ?></strong> <?= $this->e(implode(' · ', $criticalChecks)) ?></p>
                <a class="pc-auth-link" href="/admin/tools/system"><?= $this->e($this->t('admin.system.dashboard_alert_link')) ?></a>
            </div>
        </div>
    <?php endif; ?>

    <!-- 4 vibrant KPI stat tiles -->
    <section aria-label="<?= t('admin.attr.indicateurs_cles') ?>" class="pc-grid pc-grid--stats">
        <?php foreach ($stats as $stat): ?>
            <?= $this->raw($this->insert('admin::components/stat-tile', $stat)) ?>
        <?php endforeach; ?>
    </section>

    <!-- Main two-column dashboard grid -->
    <div class="pc-grid pc-grid--dashboard">
        <!-- Recent activity -->
        <?php $liveMode = !empty($isLiveActivity); ?>
        <?php ob_start(); ?>
        <?php if ($recentActivity === []): ?>
            <div style="padding:24px;text-align:center;" class="pc-text-sm pc-text-muted">
                <?= $this->e($this->t('admin.dashboard.index.aucun_contenu_cree_moment_commencez')) ?>
            </div>
        <?php else: ?>
            <ul class="pc-activity-list">
                <?php foreach ($recentActivity as $event): ?>
                    <li class="pc-activity-item">
                        <?= $this->raw($this->insert('admin::components/avatar', [
                            'initials' => $event['initials'],
                            'size' => 'md',
                            'accent' => $event['accent'],
                        ])) ?>
                        <div class="pc-activity-item__body">
                            <p class="pc-activity-item__title">
                                <?php if (!empty($event['url'])): ?>
                                    <a href="<?= $this->e((string) $event['url']) ?>" style="color:inherit;text-decoration:none;"><?= $this->e($event['title']) ?></a>
                                <?php else: ?>
                                    <?= $this->e($event['title']) ?>
                                <?php endif; ?>
                            </p>
                            <p class="pc-activity-item__meta"><?= $this->e($event['meta']) ?></p>
                        </div>
                        <?= $this->raw($this->insert('admin::components/badge', [
                            'label' => $event['status'],
                            'variant' => $event['statusVariant'],
                            'dot' => true,
                        ])) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php $activityHtml = (string) ob_get_clean(); ?>

        <?= $this->raw($this->insert('admin::components/card', [
            'title' => $this->t('admin.view_dashboard_index.activite_recente'),
            'subtitle' => $liveMode ? $this->t('admin.view_dashboard_index.derniers_contenus_crees_ou_modifies') : $this->t('admin.view_dashboard_index.dernieres_operations_sur_votre_espace_editorial'),
            'body' => $activityHtml,
            'flush' => true,
            'footer' => '<span class="pc-text-xs pc-text-muted">' . $this->e($this->t('admin.dashboard.index.audit_footer')) . '</span>'
                . $this->insert('admin::components/button', [
                    'label' => $this->t('admin.view_dashboard_index.voir_les_pages'),
                    'variant' => 'ghost',
                    'size' => 'sm',
                    'href' => '/admin/content/page',
                ]),
        ])) ?>

        <!-- Right column: Shortcuts + System status -->
        <div class="pc-stack pc-stack--lg">
            <?php ob_start(); ?>
            <div class="pc-shortcut-grid">
                <?php foreach ($shortcuts as $shortcut): ?>
                    <a class="pc-shortcut" href="<?= $this->e($shortcut['url']) ?>">
                        <span class="pc-accent-tile pc-accent-tile--<?= $this->e($shortcut['accent']) ?>" aria-hidden="true">
                            <?= $this->raw($this->icon($shortcut['icon'], 20)) ?>
                        </span>
                        <span>
                            <span class="pc-shortcut__title"><?= $this->e($shortcut['title']) ?></span><br>
                            <span class="pc-shortcut__desc"><?= $this->e($shortcut['description']) ?></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php $shortcutsHtml = (string) ob_get_clean(); ?>

            <?= $this->raw($this->insert('admin::components/card', [
                'title' => $this->t('admin.dashboard.index.raccourcis'),
                'subtitle' => $this->t('admin.view_dashboard_index.acces_direct_aux_modules_et_au_styleguide'),
                'body' => $shortcutsHtml,
            ])) ?>

            <?php ob_start(); ?>
            <dl class="pc-kv-list">
                <?php foreach ($systemStatus as $row): ?>
                    <div class="pc-kv-row">
                        <dt><?= $this->e($row['label']) ?></dt>
                        <dd class="pc-row pc-row--sm">
                            <span class="pc-mono"><?= $this->e($row['value']) ?></span>
                            <?php if (!empty($row['badge'])): ?>
                                <?= $this->raw($this->insert('admin::components/badge', [
                                    'label' => $row['badge'],
                                    'variant' => $row['variant'] ?? 'neutral',
                                ])) ?>
                            <?php endif; ?>
                        </dd>
                    </div>
                <?php endforeach; ?>
            </dl>
            <?php $statusHtml = (string) ob_get_clean(); ?>

            <?= $this->raw($this->insert('admin::components/card', [
                'title' => $this->t('admin.view_dashboard_index.etat_du_systeme'),
                'subtitle' => $this->t('admin.view_dashboard_index.diagnostics_temps_reel_du_noyau_prime_cms'),
                'body' => $statusHtml,
            ])) ?>
        </div>
    </div>
</div>

<?php $this->stop(); ?>
