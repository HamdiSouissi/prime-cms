# Rapport de phase — Phase 2 : Design system et coque du dashboard

- **Projet** : Prime CMS (`HamdiSouissi/prime-cms`)
- **Phase** : 2 / 15 — Design system et coque du dashboard
- **Branche** : `arena/6bec0d01-prime-cms`
- **Date** : 15 novembre 2026

---

## 1. Résumé des livrables (L1 – L12)

| Livrable | Statut | Détails |
|---|---|---|
| **L1 — Logo officiel & favicons** | Livré | Intégration des 11 visuels officiels (`logo-{64,128,256,512}.{webp,png}`, `icon-192.png`, `apple-touch-icon.png`, `favicon-32.png`) + `favicon.ico` (32×32 PNG-in-ICO) dans `public/assets/brand/` ; suppression des placeholders provisoires (`logo.svg`, `logo.png`, `README.md`) ; balises `<picture>` WebP+PNG 2× Retina sur toutes les vues (Phase 1 & Phase 2). |
| **L2 — Polices locales (OFL)** | Livré | Hébergement local des fontes variables WOFF2 officielles (`InterVariable`, `InterVariable-Italic`, `PlusJakartaSans-Variable`, `JetBrainsMono-Variable`) et de leurs licences `OFL.txt` dans `public/assets/fonts/`. Préchargement d'Inter uniquement, `font-display: swap`, zéro requête externe. |
| **L3 — Sprite Lucide & `icon()`** | Livré | Sprite SVG local `public/assets/admin/icons/sprite.svg` (55 icônes Lucide officielles, trait 1,5 px, `currentColor`) + licence ISC `public/assets/admin/icons/LICENSE` ; classe `Prime\View\Icon`, helper global `icon()` et méthode `$this->icon()` avec échappement strict et exception explicite en mode debug. |
| **L4 — CSS modulaire & `assets:build`** | Livré | 26 feuilles modulaires dans `resources/assets/admin/css/` (`tokens.css`, `fonts.css`, `base.css`, `layout.css`, `utilities.css`, 21 `components/*.css`) pilotées par `resources/assets/admin/manifest.php` ; aucune couleur codée en dur hors de `tokens.css` ; constructeur pur PHP `Prime\Admin\Assets\CssBuilder` + commande CLI `php bin/prime assets:build` générant `public/assets/admin/css/admin.css` (45,86 Ko). |
| **L5 — Modules JS ES2022** | Livré | 10 modules natifs dans `public/assets/admin/js/` (`admin.js`, `focus-trap.js`, `sidebar.js`, `dropdown.js`, `modal.js`, `drawer.js`, `tabs.js`, `toast.js`, `tooltip.js`, `command-palette.js`). Zéro bibliothèque externe, zéro bundler, zéro script inline exécutable, `localStorage` protégé par `try/catch`, API `window.Prime`. |
| **L6 — Bibliothèque de composants** | Livré | 18 partiels PHP réutilisables dans `resources/views/admin/components/` (`button`, `field`, `choice`, `badge`, `card`, `stat-tile`, `alert`, `empty-state`, `avatar`, `breadcrumb`, `progress`, `kbd`, `page-header`, `skeleton`, `tabs`, `dropdown`, `modal`, `drawer`), tous échappés via `e()`. |
| **L7 — Coque du dashboard** | Livré | Layout `resources/views/admin/layouts/app.php` avec lien d'évitement « Aller au contenu », sidebar sombre `--ink-900` (264 px / 72 px repliée avec infobulles / tiroir mobile `< 768 px`), sous-menus repliables, topbar blanche sticky (recherche, bouton corail unique « Créer », notifications, menu utilisateur) et zone principale `--canvas`. |
| **L8 — Palette de commandes** | Livré | Déclenchement par `Ctrl/⌘ + K`, `/` hors champ éditable ou clic dans la topbar ; recherche floue insensible aux accents et à la casse ; navigation clavier complète ; extensible via `admin.command_palette`. |
| **L9 — Système de menu extensible** | Livré | `Prime\Admin\Menu\{MenuItem,MenuNode,AdminMenu}` + `AdminServiceProvider` (menu par défaut Tableau de bord, Contenu, Système + sous-menus Apparence et Réglages) + filtres `admin.menu` et `admin.create.items`. |
| **L10 — Routes, aperçu & pages** | Livré | Middleware `AdminPreviewGate` (`APP_ENV=local` uniquement, sinon 404) ; tableau de bord `/admin` alimenté par `DemoDashboardData` (strictement isolé) ; `/admin/styleguide` interactif ; 14 routes placeholders `/admin/{module}` et `/admin/{module}/{section}` ; hooks `admin.assets`, `admin.head`, `admin.footer`. |
| **L11 — Tests & CI** | Livré | Tests unitaires (`IconTest`, `CssBuilderTest`, `AdminMenuTest`) et d'intégration (`AdminDashboardTest`) ; vérification `assets:build` + `git diff --exit-code` et smoke tests `/admin` dans `.github/workflows/ci.yml`. |
| **L12 — Documentation** | Livré | Création de `docs/design-system.md` et `docs/phases/PHASE-02-REPORT.md` ; mise à jour de `docs/hooks.md` et `README.md`. |

---

## 2. Vérifications qualité, sécurité et accessibilité

- **Analyse statique** : PHPStan niveau 6 — **0 erreur**.
- **Tests PHP 8.2 & 8.3** : **97 tests, 907 assertions, 0 échec** (Unit + Integration).
- **Exécution sans `vendor/`** : `bin/prime list`, `bin/prime about`, `bin/prime routes`, `bin/prime assets:build` vérifiés sans dossier `vendor/`.
- **CSP stricte & sécurité** :
  - Aucun script inline exécutable (`<script>` uniquement avec `src=` ou `type="application/json"`), aucun gestionnaire d'événement inline (`on*`), vérifié par assertion automatique dans `AdminDashboardTest`.
  - Aucune couleur codée en dur en dehors de `resources/assets/admin/css/tokens.css`.
  - En-têtes de sécurité (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Content-Security-Policy`) inchangés et vérifiés sur toutes les routes `/admin/*`.
  - Garde `AdminPreviewGate` vérifiée en `production` et `staging` (retour `404` garanti).
- **Zéro requête externe** : polices, icônes et logo servis 100 % localement.

---

## 3. Corrections (revue 1)

Les 7 points relevés lors de la revue de la PR #2 ont été corrigés sur la branche `arena/6bec0d01-prime-cms` :

- **C1 — Barre supérieure mobile (< 768 px)** : le champ de recherche étendu est masqué en dessous de 768 px et remplacé par un bouton icône loupe dédié (`.pc-topbar__search-mobile`, `aria-label="Rechercher"`, infobulle `data-prime-tooltip="Rechercher"`). Toutes les zones cliquables de la barre supérieure mobile font au minimum **40 × 40 px** ; la somme des largeurs tient dans **264 px** (aucun chevauchement ni découpe à 320, 375, 414 et 768 px).
- **C2 — Numéros de phase alignés sur `docs/CONTEXT.md` §4** : mise à jour de `DemoDashboardData::modulePlaceholders()` (badges, descriptions et fonctionnalités prévues), du styleguide (« Édition … (Phase 6) ») et du pied de carte d'activité (« Journal d'audit disponible en Phase 9 ») :
  - Utilisateurs → **Phase 4** · Plugins → **Phase 5** · Pages & Articles → **Phase 6** · Médias → **Phase 7** · Apparence (thèmes, menus, widgets) → **Phase 8** · Réglages (général, permaliens, sécurité, e-mails) → **Phase 9**.
  - Vérifié par `AdminDashboardTest::testModulePlaceholdersAnnounceOfficialRoadmapPhases()`.
- **C3 — Palette de commandes (3 correctifs)** :
  1. **Titre et sous-titre séparés** : `.pc-cmd__item-body` passe en colonne (`display: flex; flex-direction: column; gap: 2px`), sous-titre sur sa propre ligne en `--color-text-muted` (`--ink-500`) 12 px ; les sous-menus affichent désormais un libellé de groupe lisible et non plus l'URL brute.
  2. **Un seul bouton de fermeture** : le champ passe de `type="search"` à `type="text" role="combobox"` (et les pseudo-éléments `::-webkit-search-cancel-button` sont masqués en sécurité), supprimant la croix native bleue de Chrome.
  3. **Recherche stricte par mots et classement** : logique extraite dans deux fonctions pures exportées (`normalizeText`, `searchCommands`) dans `public/assets/admin/js/command-palette.js`, avec mots-clés français ajoutés dans `AdminContext::DEFAULT_KEYWORDS` (ex. `paramètres`, `configuration` pour Réglages). L'URL n'est jamais fouillée. Cas vérifiés directement sous Node.js contre le payload réel du serveur :
     - `"medi"` → `['Médias', 'Téléverser un média']` uniquement (sans Thèmes, Menus, Permaliens ni Widgets) ;
     - `"medias"` et `"Médias"` → résultats identiques (insensible aux accents et à la casse) ;
     - `"paramètres"` et `"configuration"` → remontent `Réglages` et ses sous-menus via les mots-clés ;
     - `"appearance"` → `[]` (les segments d'URL ne sont jamais inclus dans la recherche) ;
     - `"nouvel article"` → `['Nouvel article']` (tous les mots saisis doivent correspondre) ;
     - classement vérifié : `startsWith` (400) > début de mot du libellé (300) > libellé contient (200) > groupe ou mots-clés (100).
  - Payload serveur vérifié par `AdminDashboardTest::testCommandPalettePayloadHasKeywordsCleanSubtitlesAndSingleCloseControl()`.
- **C4 — Lien d'évitement sans trace hors focus** : `.pc-skip-link` utilise hors focus `clip-path: inset(50%); width: 1px; height: 1px; box-shadow: none; opacity: 0; pointer-events: none` (zéro pixel ni ombre visible au repos) tout en restant immédiatement focalisable au clavier (`:focus` / `:focus-visible`).
- **C5 — Positionnement de la pile de toasts** : décalée sous la topbar sur ordinateur et tablette (`top: calc(var(--topbar-height) + var(--space-4))`, `right: var(--space-4)`), et ancrée en bas pleine largeur moins 16 px de marge sur mobile (`@media (max-width: 767px)`), sans jamais masquer la barre supérieure.
- **C6 — Usage maîtrisé du corail et des actions neutres** :
  - liens de métadonnées secondaires des tuiles KPI (`2 brouillons`, `5 programmés`, `WebP & PNG`, `3 rôles`) passés en `--color-text-muted` (`--ink-500`) via `.pc-stat__link` ;
  - « Se déconnecter » passé en élément de menu neutre (`.pc-dropdown__item--danger` réservé aux actions destructrices) ;
  - message du bouton « Dupliquer » dans le styleguide corrigé en « Élément dupliqué (démo). ».
- **C7 — Convention stricte `data-prime-*`** : tous les attributs HTML pilotés par le JavaScript du dashboard ont été préfixés `data-prime-*` dans les 10 modules JS, les partiels, le layout, le styleguide et `docs/design-system.md`. Vérifié automatiquement par `AdminDashboardTest::testAllAdminPagesUsePrimePrefixedDataAttributesOnly()`.

> **Captures d'écran** : le sandbox d'exécution ne dispose pas de navigateur headless (Chromium / Playwright non installés, accès réseau restreint hors téléchargement de binaires), aucune capture automatisée n'a donc été générée dans `docs/phases/screens/phase-02/`. Toutes les règles CSS, zones tactiles, positions et sorties HTML ont été vérifiées par calcul et tests automatisés.

---

## 4. Suite logique (Phases 3 et 4)

- **Phase 3 (Base de données et installateur)** : connexion PDO préparée, migrations, remplacement progressif de `DemoDashboardData` par des statistiques réelles.
- **Phase 4 (Authentification, utilisateurs, rôles)** : remplacement de `AdminPreviewGate` par un middleware d'authentification par session + Argon2id, activation de la déconnexion et filtrage des items de `AdminMenu` par `$item->capability`.
