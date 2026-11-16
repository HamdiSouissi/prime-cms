<?php

declare(strict_types=1);

/**
 * Main admin dashboard shell layout.
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $appName
 * @var string $pageTitle
 * @var string $fullTitle
 * @var string $environment
 * @var list<array{label: ?string, items: list<\Prime\Admin\Menu\MenuNode>}> $menuGroups
 * @var list<array{id: string, label: string, description: string, icon: string, accent: string, url: string}> $createItems
 * @var string $commandPaletteJson
 * @var list<string> $adminStyles
 * @var list<string> $adminScripts
 * @var string $adminHeadExtra
 * @var string $adminFooterExtra
 * @var array{name: string, role: string, email: string, initials: string} $currentUser
 * @var string $htmlLang
 * @var string $htmlDirection
 * @var string $translationsJson
 */
$htmlLang = isset($htmlLang) && is_string($htmlLang) && $htmlLang !== '' ? $htmlLang : 'fr';
$htmlDirection = isset($htmlDirection) && is_string($htmlDirection) && $htmlDirection !== '' ? $htmlDirection : 'ltr';
?><!DOCTYPE html>
<html lang="<?= $this->e($htmlLang) ?>" dir="<?= $this->e($htmlDirection) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $this->e($this->csrfToken()) ?>">
    <meta name="prime-icon-sprite" content="<?= $this->e(asset('admin/icons/sprite.svg')) ?>">
    <title><?= $this->e($fullTitle) ?></title>
    <link rel="icon" href="<?= $this->e(asset('brand/favicon.ico')) ?>" sizes="32x32">
    <link rel="icon" type="image/png" href="<?= $this->e(asset('brand/favicon-32.png')) ?>" sizes="32x32">
    <link rel="apple-touch-icon" href="<?= $this->e(asset('brand/apple-touch-icon.png')) ?>">
    <link rel="preload" href="<?= $this->e(asset('fonts/inter/InterVariable.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <?php foreach ($adminStyles as $styleUrl): ?>
    <link rel="stylesheet" href="<?= $this->e($styleUrl) ?>">
    <?php endforeach; ?>
    <?= $this->raw($adminHeadExtra) ?>
</head>
<body>
    <a class="pc-skip-link" href="#content"><?= t('admin.layouts.skip_to_content') ?></a>

    <div class="pc-admin" data-prime-admin-shell>
        <div class="pc-sidebar__backdrop" data-prime-sidebar-backdrop aria-hidden="true"></div>

        <aside class="pc-sidebar" data-prime-sidebar>
            <div class="pc-sidebar__brand">
                <a class="pc-sidebar__brand-link" href="/admin">
                    <picture>
                        <source
                            type="image/webp"
                            srcset="<?= $this->e(asset('brand/logo-64.webp')) ?> 1x, <?= $this->e(asset('brand/logo-128.webp')) ?> 2x"
                        >
                        <img
                            class="pc-sidebar__logo"
                            src="<?= $this->e(asset('brand/logo-64.png')) ?>"
                            srcset="<?= $this->e(asset('brand/logo-64.png')) ?> 1x, <?= $this->e(asset('brand/logo-128.png')) ?> 2x"
                            alt=""
                            width="32"
                            height="32"
                        >
                    </picture>
                    <span class="pc-sidebar__brand-text">
                        <span class="pc-sidebar__brand-name"><?= $this->e($appName) ?></span>
                        <span class="pc-sidebar__brand-tag">Administration</span>
                    </span>
                </a>
                <button
                    type="button"
                    class="pc-sidebar__close-mobile"
                    data-prime-sidebar-mobile-close
                    aria-label="<?= $this->e($this->t('admin.layouts.app.fermer_menu_navigation')) ?>"
                >
                    <?= $this->raw($this->icon('x', 20)) ?>
                </button>
            </div>

            <nav class="pc-sidebar__nav" aria-label="Navigation principale">
                <?php foreach ($menuGroups as $group): ?>
                    <div class="pc-sidebar__group">
                        <?php if ($group['label'] !== null): ?>
                            <div class="pc-sidebar__group-label"><?= $this->e($group['label']) ?></div>
                        <?php endif; ?>
                        <ul class="pc-sidebar__list">
                            <?php foreach ($group['items'] as $node): ?>
                                <?php
                                $item = $node->item;
                                $hasChildren = $node->children !== [];
                                $isCurrent = $node->active;
                                $isExpanded = $node->active || $node->hasActiveChild;
                                $submenuId = 'pc-submenu-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $item->id);
                                ?>
                                <li class="pc-sidebar__item">
                                    <div class="pc-sidebar__row">
                                        <a
                                            href="<?= $this->e($item->url) ?>"
                                            class="pc-sidebar__link<?= ($isCurrent || $node->hasActiveChild) ? ' pc-sidebar__link--active' : '' ?>"
                                            <?= $isCurrent ? 'aria-current="page"' : '' ?>
                                            data-prime-tooltip-collapsed="<?= $this->e($item->translatedLabel()) ?>"
                                            data-prime-tooltip-placement="right"
                                        >
                                            <span class="pc-sidebar__icon pc-sidebar__icon--<?= $this->e($item->accent) ?>" aria-hidden="true">
                                                <?= $this->raw($this->icon($item->icon, 20)) ?>
                                            </span>
                                            <span class="pc-sidebar__label"><?= $this->e($item->translatedLabel()) ?></span>
                                            <?php if ($item->badge !== null): ?>
                                                <span class="pc-sidebar__badge"><?= $this->e($item->translatedBadge()) ?></span>
                                            <?php endif; ?>
                                        </a>

                                        <?php if ($hasChildren): ?>
                                            <button
                                                type="button"
                                                class="pc-sidebar__submenu-toggle"
                                                data-prime-submenu-toggle
                                                aria-expanded="<?= $isExpanded ? 'true' : 'false' ?>"
                                                aria-controls="<?= $this->e($submenuId) ?>"
                                                aria-label="<?= $this->e($this->t('admin.layouts.app.afficher_sous_menus', ['label' => $item->translatedLabel()])) ?>"
                                            >
                                                <?= $this->raw($this->icon('chevron-right', 16)) ?>
                                            </button>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($hasChildren): ?>
                                        <ul class="pc-sidebar__submenu" id="<?= $this->e($submenuId) ?>"<?= $isExpanded ? '' : ' hidden' ?>>
                                            <?php foreach ($node->children as $childNode): ?>
                                                <?php $child = $childNode->item; ?>
                                                <li>
                                                    <a
                                                        href="<?= $this->e($child->url) ?>"
                                                        class="pc-sidebar__sublink<?= $childNode->active ? ' pc-sidebar__sublink--active' : '' ?>"
                                                        <?= $childNode->active ? 'aria-current="page"' : '' ?>
                                                    >
                                                        <span><?= $this->e($child->translatedLabel()) ?></span>
                                                        <?php if ($child->badge !== null): ?>
                                                            <span class="pc-sidebar__badge"><?= $this->e($child->translatedBadge()) ?></span>
                                                        <?php endif; ?>
                                                    </a>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </nav>

            <div class="pc-sidebar__footer">
                <button
                    type="button"
                    class="pc-sidebar__collapse-btn"
                    data-prime-sidebar-collapse
                    aria-expanded="true"
                    aria-label="<?= $this->e($this->t('admin.layouts.app.reduire_barre_laterale')) ?>"
                    data-prime-tooltip-collapsed="<?= $this->e($this->t('admin.layouts.app.deplier_barre_laterale')) ?>"
                    data-prime-tooltip-placement="right"
                >
                    <?= $this->raw($this->icon('panel-left-close', 18)) ?>
                    <span class="pc-sidebar__collapse-label" data-prime-sidebar-collapse-label><?= $this->e($this->t('admin.layouts.app.reduire')) ?></span>
                </button>
            </div>
        </aside>

        <div class="pc-main-wrap">
            <header class="pc-topbar">
                <div class="pc-topbar__left">
                    <button
                        type="button"
                        class="pc-topbar__menu-btn"
                        data-prime-sidebar-mobile-open
                        aria-label="<?= $this->e($this->t('admin.layouts.app.ouvrir_menu_navigation')) ?>"
                        aria-expanded="false"
                    >
                        <?= $this->raw($this->icon('menu', 20)) ?>
                    </button>

                    <button
                        type="button"
                        class="pc-topbar__search"
                        data-prime-command-palette-open
                        aria-label="<?= $this->e($this->t('admin.layouts.app.rechercher_ouvrir_palette_commandes')) ?>"
                    >
                        <?= $this->raw($this->icon('search', 16)) ?>
                        <span class="pc-topbar__search-text"><?= $this->e($this->t('admin.layouts.app.rechercher_lancer_action')) ?></span>
                        <span class="pc-row pc-row--sm" aria-hidden="true">
                            <?= $this->raw($this->insert('admin::components/kbd', ['keys' => 'Ctrl', 'platformMod' => true])) ?>
                            <?= $this->raw($this->insert('admin::components/kbd', ['keys' => 'K'])) ?>
                        </span>
                    </button>

                    <button
                        type="button"
                        class="pc-topbar__search-mobile"
                        data-prime-command-palette-open
                        data-prime-tooltip="<?= $this->e($this->t('core.actions.search')) ?>"
                        aria-label="<?= $this->e($this->t('core.actions.search')) ?>"
                    >
                        <?= $this->raw($this->icon('search', 18)) ?>
                    </button>
                </div>

                <div class="pc-topbar__actions">
                    <!-- Quick create dropdown (switches to secondary when page header owns the single coral CTA) -->
                    <?php if ($createItems !== []): ?>
                    <?php $createBtnClass = !empty($primaryCtaInContent) ? 'pc-btn pc-btn--secondary pc-btn--sm' : 'pc-btn pc-btn--primary pc-btn--sm'; ?>
                    <div class="pc-dropdown" data-prime-dropdown>
                        <button
                            type="button"
                            class="<?= $this->e($createBtnClass) ?>"
                            data-prime-dropdown-trigger
                            aria-label="<?= $this->e($this->t('admin.layouts.app.creer_contenu')) ?>"
                            aria-haspopup="menu"
                            aria-expanded="false"
                            aria-controls="pc-topbar-create-menu"
                        >
                            <?= $this->raw($this->icon('plus', 16)) ?>
                            <span class="pc-topbar__create-label"><?= $this->e($this->t('core.actions.create')) ?></span>
                            <?= $this->raw($this->icon('chevron-down', 14)) ?>
                        </button>
                        <div
                            id="pc-topbar-create-menu"
                            class="pc-dropdown__menu"
                            role="menu"
                            aria-label="<?= $this->e($this->t('admin.layouts.app.creation_rapide')) ?>"
                            data-prime-dropdown-menu
                            hidden
                        >
                            <div class="pc-dropdown__header">
                                <div class="pc-dropdown__title"><?= $this->e($this->t('admin.layouts.app.creation_rapide')) ?></div>
                                <div class="pc-dropdown__subtitle"><?= $this->e($this->t('admin.layouts.app.nouveaux_contenus_ressources')) ?></div>
                            </div>
                            <?php foreach ($createItems as $create): ?>
                                <a class="pc-dropdown__item" role="menuitem" href="<?= $this->e($create['url']) ?>">
                                    <?= $this->raw($this->icon($create['icon'], 16)) ?>
                                    <span><?= $this->e($create['label']) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($canPurgeCache)): ?>
                    <!-- Clear every cache without leaving the current screen -->
                    <form method="post" action="/admin/settings/performance/purge" class="pc-topbar__purge">
                        <?= $this->raw($this->csrfField()) ?>
                        <button
                            type="submit"
                            class="pc-btn pc-btn--ghost pc-btn--icon pc-btn--sm"
                            data-prime-tooltip="<?= $this->e($this->t('admin.layouts.app.vider_cache')) ?>"
                            aria-label="<?= $this->e($this->t('admin.layouts.app.vider_cache')) ?>"
                        >
                            <?= $this->raw($this->icon('rotate-ccw', 18)) ?>
                        </button>
                    </form>
                    <?php endif; ?>

                    <!-- Notifications dropdown (persistent dashboard notices) -->
                    <?php
                    /** @var list<\Prime\Admin\Notice> $noticesList */
                    $noticesList = is_array($adminNotices ?? null) ? $adminNotices : [];
                    $noticesCount = count($noticesList);
                    ?>
                    <div class="pc-dropdown" data-prime-dropdown>
                        <button
                            type="button"
                            class="pc-btn pc-btn--ghost pc-btn--icon pc-btn--sm pc-notif-btn"
                            data-prime-dropdown-trigger
                            data-prime-tooltip="Notifications"
                            aria-label="<?= $this->e($noticesCount > 0 ? sprintf('Notifications (%d non lue%s)', $noticesCount, $noticesCount > 1 ? 's' : '') : 'Notifications') ?>"
                            aria-haspopup="menu"
                            aria-expanded="false"
                            aria-controls="pc-topbar-notif-menu"
                        >
                            <?= $this->raw($this->icon('bell', 18)) ?>
                            <?php if ($noticesCount > 0): ?>
                                <span class="pc-notif-btn__badge" data-prime-notice-badge aria-hidden="true"><?= $this->e((string) min(99, $noticesCount)) ?></span>
                            <?php endif; ?>
                        </button>
                        <div
                            id="pc-topbar-notif-menu"
                            class="pc-dropdown__menu pc-notif-menu"
                            role="menu"
                            aria-label="Notifications"
                            data-prime-dropdown-menu
                            hidden
                        >
                            <div class="pc-dropdown__header">
                                <div class="pc-dropdown__title">Notifications<?= $noticesCount > 0 ? ' (' . $this->e((string) $noticesCount) . ')' : '' ?></div>
                                <div class="pc-dropdown__subtitle"><?= t('admin.layouts.alert_centre') ?></div>
                            </div>
                            <?php if ($noticesList === []): ?>
                                <div class="pc-dropdown__empty">
                                    <?= $this->e($this->t('admin.layouts.app.aucune_notification_moment')) ?>
                                </div>
                            <?php else: ?>
                                <div class="pc-notif-menu__list">
                                    <?php foreach ($noticesList as $noticeItem): ?>
                                        <div class="pc-notif-menu__item pc-notif-menu__item--<?= $this->e($noticeItem->type) ?>" data-prime-notice-id="<?= $this->e($noticeItem->id) ?>">
                                            <div class="pc-notif-menu__title"><?= $this->e($noticeItem->title) ?></div>
                                            <p class="pc-notif-menu__text"><?= $this->e($noticeItem->message) ?></p>
                                            <?php if ($noticeItem->actionUrl !== null && $noticeItem->actionLabel !== null): ?>
                                                <?php if ($noticeItem->actionMethod === 'POST'): ?>
                                                    <form method="post" action="<?= $this->e($noticeItem->actionUrl) ?>" class="pc-notif-menu__action">
                                                        <?= $this->raw($this->csrfField()) ?>
                                                        <button type="submit" class="pc-btn pc-btn--secondary pc-btn--sm"><?= $this->e($noticeItem->actionLabel) ?></button>
                                                    </form>
                                                <?php else: ?>
                                                    <a class="pc-notif-menu__link" href="<?= $this->e($noticeItem->actionUrl) ?>"><?= $this->e($noticeItem->actionLabel) ?> →</a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- User menu dropdown -->
                    <div class="pc-dropdown" data-prime-dropdown>
                        <button
                            type="button"
                            class="pc-btn pc-btn--ghost pc-btn--sm"
                            data-prime-dropdown-trigger
                            aria-label="<?= $this->e($this->t('admin.layouts.app.menu_utilisateur_nom', ['name' => $currentUser['name']])) ?>"
                            aria-haspopup="menu"
                            aria-expanded="false"
                            aria-controls="pc-topbar-user-menu"
                        >
                            <?= $this->raw($this->insert('admin::components/avatar', [
                                'initials' => $currentUser['initials'],
                                'src' => $currentUser['avatarUrl'] ?? null,
                                'alt' => $currentUser['name'],
                                'size' => 'sm',
                                'accent' => $currentUser['accent'] ?? 'brand',
                            ])) ?>
                            <span class="pc-topbar__create-label"><?= $this->e($currentUser['name']) ?></span>
                            <?= $this->raw($this->icon('chevron-down', 14)) ?>
                        </button>
                        <div
                            id="pc-topbar-user-menu"
                            class="pc-dropdown__menu"
                            role="menu"
                            aria-label="<?= $this->e($this->t('admin.layouts.app.menu_utilisateur')) ?>"
                            data-prime-dropdown-menu
                            hidden
                        >
                            <div class="pc-dropdown__header">
                                <div class="pc-dropdown__title"><?= $this->e($currentUser['name']) ?></div>
                                <div class="pc-dropdown__subtitle"><?= $this->e($currentUser['role']) ?><?= !empty($currentUser['email']) ? ' · ' . $this->e($currentUser['email']) : '' ?></div>
                            </div>
                            <a class="pc-dropdown__item" role="menuitem" href="/admin/profile">
                                <?= $this->raw($this->icon('user', 16)) ?>
                                <span><?= t('admin.layouts.my_profile') ?></span>
                            </a>
                            <?php if (!empty($currentUser['canSettings'])): ?>
                                <a class="pc-dropdown__item" role="menuitem" href="/admin/settings">
                                    <?= $this->raw($this->icon('settings', 16)) ?>
                                    <span><?= $this->e($this->t('admin.layouts.app.reglages')) ?></span>
                                </a>
                            <?php endif; ?>
                            <a class="pc-dropdown__item" role="menuitem" href="/">
                                <?= $this->raw($this->icon('external-link', 16)) ?>
                                <span><?= $this->e($this->t('admin.layouts.app.voir_site_public')) ?></span>
                            </a>
                            <div class="pc-dropdown__separator" role="separator"></div>
                            <form method="post" action="/admin/logout" class="pc-dropdown-form">
                                <?= $this->raw($this->csrfField()) ?>
                                <button type="submit" class="pc-dropdown__item" role="menuitem">
                                    <?= $this->raw($this->icon('log-out', 16)) ?>
                                    <span><?= $this->e($this->t('admin.layouts.app.deconnecter')) ?></span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>

            <main id="content" class="pc-content" tabindex="-1">
                <?php if (!empty($safeMode)): ?>
                    <div class="pc-notices-stack">
                        <div class="pc-alert pc-alert--warning pc-notice" role="alert">
                            <span class="pc-alert__icon" aria-hidden="true">
                                <?= $this->raw($this->icon('shield', 18)) ?>
                            </span>
                            <div class="pc-alert__body">
                                <p class="pc-alert__title"><?= $this->e($this->t('admin.layouts.app.mode_sans_echec_actif_prime')) ?></p>
                                <p class="pc-alert__text"><?= $this->raw($this->t('admin.layouts.app.plugins_sont_temporairement_ignores_demarrage')) ?></p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($noticesList !== []): ?>
                    <?php
                    $noticeIconMap = [
                        'info' => 'info',
                        'success' => 'check-circle',
                        'warning' => 'alert-triangle',
                        'danger' => 'alert-circle',
                    ];
                    ?>
                    <div class="pc-notices-stack" data-prime-notices-stack>
                        <?php foreach ($noticesList as $noticeItem): ?>
                            <?php
                            $noticeRole = $noticeItem->type === 'danger' ? 'alert' : 'status';
                            $noticeIcon = $noticeIconMap[$noticeItem->type] ?? 'info';
                            ?>
                            <div
                                class="pc-alert pc-alert--<?= $this->e($noticeItem->type) ?> pc-notice"
                                role="<?= $this->e($noticeRole) ?>"
                                data-prime-notice="<?= $this->e($noticeItem->id) ?>"
                            >
                                <span class="pc-alert__icon" aria-hidden="true">
                                    <?= $this->raw($this->icon($noticeIcon, 18)) ?>
                                </span>
                                <div class="pc-alert__body">
                                    <p class="pc-alert__title"><?= $this->e($noticeItem->title) ?></p>
                                    <p class="pc-alert__text"><?= $this->e($noticeItem->message) ?></p>
                                </div>
                                <div class="pc-notice__actions">
                                    <?php if ($noticeItem->actionUrl !== null && $noticeItem->actionLabel !== null): ?>
                                        <?php if ($noticeItem->actionMethod === 'POST'): ?>
                                            <form method="post" action="<?= $this->e($noticeItem->actionUrl) ?>">
                                                <?= $this->raw($this->csrfField()) ?>
                                                <button type="submit" class="pc-btn pc-btn--secondary pc-btn--sm">
                                                    <?= $this->e($noticeItem->actionLabel) ?>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <a href="<?= $this->e($noticeItem->actionUrl) ?>" class="pc-btn pc-btn--secondary pc-btn--sm">
                                                <?= $this->e($noticeItem->actionLabel) ?>
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($noticeItem->dismissible): ?>
                                        <form
                                            method="post"
                                            action="/admin/notices/<?= $this->e(rawurlencode($noticeItem->id)) ?>/dismiss"
                                            data-prime-notice-dismiss
                                        >
                                            <?= $this->raw($this->csrfField()) ?>
                                            <button
                                                type="submit"
                                                class="pc-btn pc-btn--ghost pc-btn--icon pc-btn--sm"
                                                aria-label="<?= $this->e($this->t('admin.layouts.app.fermer_notification', ['title' => $noticeItem->title])) ?>"
                                            >
                                                <?= $this->raw($this->icon('x', 16)) ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?= $this->raw($this->section('content')) ?>
            </main>
        </div>
    </div>

    <!-- Command Palette (Ctrl/Cmd + K or "/") -->
    <dialog
        id="prime-command-palette"
        class="pc-cmd"
        aria-label="<?= $this->e($this->t('admin.layouts.app.palette_commandes')) ?>"
    >
        <div class="pc-cmd__search">
            <span class="pc-cmd__search-icon" aria-hidden="true">
                <?= $this->raw($this->icon('search', 18)) ?>
            </span>
            <input
                type="text"
                role="combobox"
                aria-autocomplete="list"
                aria-expanded="false"
                aria-controls="prime-cmd-results"
                class="pc-cmd__input"
                data-prime-cmd-input
                placeholder="<?= $this->e($this->t('admin.layouts.app.rechercher_module_page_action')) ?>"
                autocomplete="off"
                aria-label="<?= $this->e($this->t('admin.layouts.app.filtrer_commandes')) ?>"
            >
            <button
                type="button"
                class="pc-btn pc-btn--ghost pc-btn--icon pc-btn--sm"
                data-prime-command-palette-close
                aria-label="<?= $this->e($this->t('admin.layouts.app.fermer_palette_commandes')) ?>"
            >
                <?= $this->raw($this->icon('x', 16)) ?>
            </button>
        </div>

        <div id="prime-cmd-results" class="pc-cmd__results" role="listbox" aria-label="<?= t('admin.attr.resultats_de_recherche') ?>" data-prime-cmd-results></div>

        <footer class="pc-cmd__footer">
            <div class="pc-cmd__hints">
                <span class="pc-cmd__hint"><?= $this->raw($this->insert('admin::components/kbd', ['keys' => '↑↓'])) ?> Naviguer</span>
                <span class="pc-cmd__hint"><?= $this->raw($this->insert('admin::components/kbd', ['keys' => '↵'])) ?> Ouvrir</span>
                <span class="pc-cmd__hint"><?= $this->raw($this->insert('admin::components/kbd', ['keys' => 'Échap'])) ?> Fermer</span>
            </div>
            <span>Prime CMS</span>
        </footer>
    </dialog>

    <script type="application/json" data-prime-translations><?= $this->raw(is_string($translationsJson ?? null) && $translationsJson !== '' ? $translationsJson : '{}') ?></script>
    <script type="application/json" id="prime-command-palette-data"><?= $this->raw($commandPaletteJson) ?></script>
    <?php if (!empty($flashToastsJson) && $flashToastsJson !== '[]'): ?>
    <script type="application/json" id="prime-flash-toasts"><?= $this->raw($flashToastsJson) ?></script>
    <?php endif; ?>

    <div class="pc-toast-stack" data-prime-toast-stack aria-live="polite" aria-atomic="false"></div>

    <?php foreach ($adminScripts as $scriptUrl): ?>
    <script type="module" src="<?= $this->e($scriptUrl) ?>" defer></script>
    <?php endforeach; ?>
    <?= $this->raw($adminFooterExtra) ?>
</body>
</html>
