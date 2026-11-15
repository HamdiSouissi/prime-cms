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
    'title' => 'Bonjour, ' . $userName,
    'subtitle' => 'Bienvenue sur votre tableau de bord ' . $appName . '.',
    'actions' => $showStyleguide
        ? $this->insert('admin::components/button', [
            'label' => 'Explorer le Design System',
            'variant' => 'secondary',
            'icon' => 'sparkles',
            'href' => '/admin/styleguide',
        ])
        : '',
])) ?>

<div class="pc-stack pc-stack--lg">

    <!-- 4 vibrant KPI stat tiles -->
    <section aria-label="Indicateurs clés" class="pc-grid pc-grid--stats">
        <?php foreach ($stats as $stat): ?>
            <?= $this->raw($this->insert('admin::components/stat-tile', $stat)) ?>
        <?php endforeach; ?>
    </section>

    <!-- Main two-column dashboard grid -->
    <div class="pc-grid pc-grid--dashboard">
        <!-- Recent activity -->
        <?php ob_start(); ?>
        <ul class="pc-activity-list">
            <?php foreach ($recentActivity as $event): ?>
                <li class="pc-activity-item">
                    <?= $this->raw($this->insert('admin::components/avatar', [
                        'initials' => $event['initials'],
                        'size' => 'md',
                        'accent' => $event['accent'],
                    ])) ?>
                    <div class="pc-activity-item__body">
                        <p class="pc-activity-item__title"><?= $this->e($event['title']) ?></p>
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
        <?php $activityHtml = (string) ob_get_clean(); ?>

        <?= $this->raw($this->insert('admin::components/card', [
            'title' => 'Activité récente',
            'subtitle' => 'Dernières opérations simulées sur votre espace éditorial',
            'body' => $activityHtml,
            'flush' => true,
            'footer' => '<span class="pc-text-xs pc-text-muted">Journal d’audit disponible en Phase 9</span>'
                . $this->insert('admin::components/button', [
                    'label' => 'Voir les pages',
                    'variant' => 'ghost',
                    'size' => 'sm',
                    'href' => '/admin/pages',
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
                'title' => 'Raccourcis',
                'subtitle' => 'Accès direct aux modules et au styleguide',
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
                'title' => 'État du système',
                'subtitle' => 'Diagnostics temps réel du noyau Prime CMS',
                'body' => $statusHtml,
            ])) ?>
        </div>
    </div>
</div>

<?php $this->stop(); ?>
