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
 */
?><!DOCTYPE html>
<html lang="fr">
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
    <a class="pc-skip-link" href="#content">Aller au contenu</a>

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
                    aria-label="Fermer le menu de navigation"
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
                                            data-prime-tooltip-collapsed="<?= $this->e($item->label) ?>"
                                            data-prime-tooltip-placement="right"
                                        >
                                            <span class="pc-sidebar__icon pc-sidebar__icon--<?= $this->e($item->accent) ?>" aria-hidden="true">
                                                <?= $this->raw($this->icon($item->icon, 20)) ?>
                                            </span>
                                            <span class="pc-sidebar__label"><?= $this->e($item->label) ?></span>
                                            <?php if ($item->badge !== null): ?>
                                                <span class="pc-sidebar__badge"><?= $this->e($item->badge) ?></span>
                                            <?php endif; ?>
                                        </a>

                                        <?php if ($hasChildren): ?>
                                            <button
                                                type="button"
                                                class="pc-sidebar__submenu-toggle"
                                                data-prime-submenu-toggle
                                                aria-expanded="<?= $isExpanded ? 'true' : 'false' ?>"
                                                aria-controls="<?= $this->e($submenuId) ?>"
                                                aria-label="Afficher les sous-menus de <?= $this->e($item->label) ?>"
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
                                                        <span><?= $this->e($child->label) ?></span>
                                                        <?php if ($child->badge !== null): ?>
                                                            <span class="pc-sidebar__badge"><?= $this->e($child->badge) ?></span>
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
                    aria-label="Réduire la barre latérale"
                    data-prime-tooltip-collapsed="Déplier la barre latérale"
                    data-prime-tooltip-placement="right"
                >
                    <?= $this->raw($this->icon('panel-left-close', 18)) ?>
                    <span class="pc-sidebar__collapse-label" data-prime-sidebar-collapse-label>Réduire</span>
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
                        aria-label="Ouvrir le menu de navigation"
                        aria-expanded="false"
                    >
                        <?= $this->raw($this->icon('menu', 20)) ?>
                    </button>

                    <button
                        type="button"
                        class="pc-topbar__search"
                        data-prime-command-palette-open
                        aria-label="Rechercher ou ouvrir la palette de commandes"
                    >
                        <?= $this->raw($this->icon('search', 16)) ?>
                        <span class="pc-topbar__search-text">Rechercher ou lancer une action…</span>
                        <span class="pc-row pc-row--sm" aria-hidden="true">
                            <?= $this->raw($this->insert('admin::components/kbd', ['keys' => 'Ctrl', 'platformMod' => true])) ?>
                            <?= $this->raw($this->insert('admin::components/kbd', ['keys' => 'K'])) ?>
                        </span>
                    </button>

                    <button
                        type="button"
                        class="pc-topbar__search-mobile"
                        data-prime-command-palette-open
                        data-prime-tooltip="Rechercher"
                        aria-label="Rechercher"
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
                            aria-label="Créer un contenu"
                            aria-haspopup="menu"
                            aria-expanded="false"
                            aria-controls="pc-topbar-create-menu"
                        >
                            <?= $this->raw($this->icon('plus', 16)) ?>
                            <span class="pc-topbar__create-label">Créer</span>
                            <?= $this->raw($this->icon('chevron-down', 14)) ?>
                        </button>
                        <div
                            id="pc-topbar-create-menu"
                            class="pc-dropdown__menu"
                            role="menu"
                            aria-label="Création rapide"
                            data-prime-dropdown-menu
                            hidden
                        >
                            <div class="pc-dropdown__header">
                                <div class="pc-dropdown__title">Création rapide</div>
                                <div class="pc-dropdown__subtitle">Nouveaux contenus et ressources</div>
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
                            data-prime-tooltip="Vider le cache"
                            aria-label="Vider le cache"
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
                                <div class="pc-dropdown__subtitle">Centre d’alertes persistantes</div>
                            </div>
                            <?php if ($noticesList === []): ?>
                                <div class="pc-dropdown__empty">
                                    Aucune notification pour le moment.
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
                            aria-label="Menu utilisateur : <?= $this->e($currentUser['name']) ?>"
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
                            aria-label="Menu utilisateur"
                            data-prime-dropdown-menu
                            hidden
                        >
                            <div class="pc-dropdown__header">
                                <div class="pc-dropdown__title"><?= $this->e($currentUser['name']) ?></div>
                                <div class="pc-dropdown__subtitle"><?= $this->e($currentUser['role']) ?><?= !empty($currentUser['email']) ? ' · ' . $this->e($currentUser['email']) : '' ?></div>
                            </div>
                            <a class="pc-dropdown__item" role="menuitem" href="/admin/profile">
                                <?= $this->raw($this->icon('user', 16)) ?>
                                <span>Mon profil</span>
                            </a>
                            <?php if (!empty($currentUser['canSettings'])): ?>
                                <a class="pc-dropdown__item" role="menuitem" href="/admin/settings">
                                    <?= $this->raw($this->icon('settings', 16)) ?>
                                    <span>Réglages</span>
                                </a>
                            <?php endif; ?>
                            <a class="pc-dropdown__item" role="menuitem" href="/">
                                <?= $this->raw($this->icon('external-link', 16)) ?>
                                <span>Voir le site public</span>
                            </a>
                            <div class="pc-dropdown__separator" role="separator"></div>
                            <form method="post" action="/admin/logout" class="pc-dropdown-form">
                                <?= $this->raw($this->csrfField()) ?>
                                <button type="submit" class="pc-dropdown__item" role="menuitem">
                                    <?= $this->raw($this->icon('log-out', 16)) ?>
                                    <span>Se déconnecter</span>
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
                                <p class="pc-alert__title">Mode sans échec actif (PRIME_SAFE_MODE=true)</p>
                                <p class="pc-alert__text">Tous les plugins sont temporairement ignorés au démarrage afin de sécuriser l’administration. Retirez <code>PRIME_SAFE_MODE=true</code> de votre fichier <code>.env</code> une fois le problème corrigé.</p>
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
                                                aria-label="Fermer la notification « <?= $this->e($noticeItem->title) ?> »"
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
        aria-label="Palette de commandes"
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
                placeholder="Rechercher un module, une page ou une action…"
                autocomplete="off"
                aria-label="Filtrer les commandes"
            >
            <button
                type="button"
                class="pc-btn pc-btn--ghost pc-btn--icon pc-btn--sm"
                data-prime-command-palette-close
                aria-label="Fermer la palette de commandes"
            >
                <?= $this->raw($this->icon('x', 16)) ?>
            </button>
        </div>

        <div id="prime-cmd-results" class="pc-cmd__results" role="listbox" aria-label="Résultats de recherche" data-prime-cmd-results></div>

        <footer class="pc-cmd__footer">
            <div class="pc-cmd__hints">
                <span class="pc-cmd__hint"><?= $this->raw($this->insert('admin::components/kbd', ['keys' => '↑↓'])) ?> Naviguer</span>
                <span class="pc-cmd__hint"><?= $this->raw($this->insert('admin::components/kbd', ['keys' => '↵'])) ?> Ouvrir</span>
                <span class="pc-cmd__hint"><?= $this->raw($this->insert('admin::components/kbd', ['keys' => 'Échap'])) ?> Fermer</span>
            </div>
            <span>Prime CMS</span>
        </footer>
    </dialog>

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
