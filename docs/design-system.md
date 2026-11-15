# Design System — Prime CMS

Le design system de **Prime CMS** est une bibliothèque 100 % originale, sans framework CSS ni bundler JavaScript, construite autour des tokens officiels définis dans [`docs/CONTEXT.md`](CONTEXT.md) (section 8).

Un aperçu interactif complet est accessible en environnement local (`APP_ENV=local`) sur **`/admin/styleguide`**.

---

## 1. Principes visuels et accessibilité

- **Zéro couleur codée en dur hors de `tokens.css`** : toutes les feuilles de style utilisent exclusivement des variables CSS (`var(--...)`).
- **Contraste WCAG AA garanti** : le corail exact du logo (`--brand-500: #ED6647`, ratio 3,19:1) est réservé aux indicateurs, icônes, bordures et focus rings. Tout texte blanc de taille normale sur bouton ou lien primaire utilise **`--brand-600` (`#C9441F`, ratio 4,85:1)** et `--brand-700` (`#A8381A`) au survol.
- **Une seule action primaire corail par écran** ; libellés formulés en verbes d'action explicites.
- **Zéro requête externe** : polices variables locales (OFL), icônes Lucide en sprite SVG local (ISC), logo officiel servi en WebP + PNG 2× Retina.
- **Navigation clavier complète** : lien d'évitement « Aller au contenu », focus ring 2 px `--brand-500`, fermeture par `Échap`, piège et restauration de focus, respect strict de `prefers-reduced-motion`.
- **CSP stricte** : aucun script inline exécutable ni attribut `on*` ; les données serveur passent par `<script type="application/json">` ou attributs préfixés `data-prime-*`.

---

## 2. Identité visuelle et polices locales

### Logo officiel (`public/assets/brand/`)

| Fichier | Usage |
|---|---|
| `logo-64.{webp,png}` / `logo-128.{webp,png}` | Barre latérale admin, en-têtes (1× / 2× Retina via `<picture>`) |
| `logo-256.{webp,png}` / `logo-512.{webp,png}` | Pages d'accueil, écrans de connexion et d'erreur |
| `favicon.ico`, `favicon-32.png` | Icônes navigateur |
| `apple-touch-icon.png`, `icon-192.png` | Raccourcis mobiles et PWA |

### Typographie (`public/assets/fonts/`)

| Police | Variable CSS | Usage |
|---|---|---|
| **Plus Jakarta Sans** (variable) | `--font-display` | Titres (`h1`–`h6`), nom de marque, chiffres KPI |
| **Inter** (variable + italique, préchargée) | `--font-sans` | Interface utilisateur, corps de texte 14 px |
| **JetBrains Mono** (variable) | `--font-mono` | Permaliens, code, commandes CLI, badges `<kbd>` |

---

## 3. Tokens CSS et pipeline `assets:build`

Les sources CSS modulaires vivent dans `resources/assets/admin/css/` et sont ordonnées dans `resources/assets/admin/manifest.php` :

1. `tokens.css` (palette, neutres, accents vibrants, espacements sur grille 4 px, rayons, ombres, z-index, bloc `[data-theme="dark"]` prêt pour une future phase)
2. `fonts.css` (`@font-face` locaux avec `font-display: swap`)
3. `base.css` (reset léger, typographie, skip-link, focus-visible, `prefers-reduced-motion`)
4. `layout.css` (sidebar sombre `--ink-900`, topbar blanche sticky, zone `--canvas`, responsive ≥ 1280 / 768–1279 / < 768 px)
5. `utilities.css` (grilles, stacks, tuiles d'accent 12 %, `.sr-only`)
6. `components/*.css` (21 feuilles dédiées par composant)

### Recompiler `public/assets/admin/css/admin.css`

Après toute modification d'une source CSS, lancer :

```bash
php bin/prime assets:build
```

La CI vérifie automatiquement que le fichier généré et versionné `public/assets/admin/css/admin.css` est strictement à jour (`git diff --exit-code`).

---

## 4. Icônes Lucide et helper `icon()`

Le sprite officiel `public/assets/admin/icons/sprite.svg` (55 icônes Lucide, trait 1,5 px, `stroke="currentColor"`, licence ISC dans `public/assets/admin/icons/LICENSE`) s'utilise depuis n'importe quel template :

```php
<?= $this->raw($this->icon('layout-dashboard', 20)) ?>
<?= $this->raw(icon('sparkles', 18, 'ma-classe')) ?>
```

- L'attribut `aria-hidden="true"` est toujours posé (les boutons icône seule portent un `aria-label`).
- En mode debug (`APP_DEBUG=true`), un nom d'icône inexistant lève immédiatement une `Prime\View\Exception\ViewException`. En production, le helper retourne une chaîne vide.

---

## 5. Bibliothèque de composants (`resources/views/admin/components/`)

Chaque composant s'insère via `$this->insert('admin::components/<nom>', [...])` et échappe automatiquement toutes ses données utilisateur :

| Partiel | Rôle | Options principales |
|---|---|---|
| `button` | Bouton ou lien stylé | `label`, `variant` (`primary\|secondary\|ghost\|danger`), `size` (`sm\|md\|lg`), `icon`, `iconOnly`, `href`, `disabled`, `loading`, `tooltip`, `attrs` |
| `field` | Champ de formulaire complet | `id`, `name`, `label`, `type` (`text\|email\|password\|select\|textarea\|…`), `value`, `help`, `error`, `required`, `options` |
| `choice` | Case, radio ou switch | `id`, `name`, `label`, `type` (`checkbox\|radio\|switch`), `description`, `checked`, `disabled` |
| `badge` | Badge / pastille de statut | `label`, `variant` (`neutral\|brand\|violet\|teal\|sky\|amber\|success\|danger`), `dot`, `pill` |
| `card` | Carte de surface | `title`, `subtitle`, `headerAction`, `body`, `footer`, `flush` |
| `stat-tile` | Tuile KPI avec icône teintée 12 % | `label`, `value`, `icon`, `accent`, `trend`, `trendDirection`, `hint`, `url` |
| `alert` | Bandeau d'alerte inline | `variant` (`info\|success\|warning\|danger`), `title`, `message` |
| `empty-state` | État vide sobre | `icon`, `accent`, `title`, `description`, `actionLabel`, `actionUrl` |
| `avatar` | Avatar image ou initiales | `initials`, `src`, `alt`, `size` (`sm\|md\|lg`), `accent` |
| `breadcrumb` | Fil d'Ariane accessible | `items` (`list<array{label: string, url: ?string}>`) |
| `progress` | Barre de progression ARIA | `label`, `value` (`0..100`), `accent` |
| `kbd` | Raccourci clavier | `keys`, `platformMod` (affiche `⌘` sur macOS, `Ctrl` ailleurs) |
| `page-header` | En-tête de page admin | `title`, `subtitle`, `breadcrumbs`, `actions` |
| `skeleton` | Placeholder de chargement | `variant` (`line\|title\|short\|circle\|card`) |
| `tabs` | Onglets WAI-ARIA | `id`, `label`, `tabs` |
| `dropdown` | Menu déroulant clavier | `id`, `triggerHtml`, `menuHtml`, `align` |
| `modal` | Modale native `<dialog>` | `id`, `title`, `body`, `footer`, `size` |
| `drawer` | Panneau latéral coulissant | `id`, `title`, `body`, `footer` |

---

## 6. Modules JavaScript ES2022 (`public/assets/admin/js/`)

Chargés nativement via `<script type="module" src="/assets/admin/js/admin.js" defer>`. Tous les attributs HTML pilotés par le JavaScript du dashboard sont strictement préfixés **`data-prime-*`** afin d'éviter toute collision avec des thèmes ou plugins tiers :

- `sidebar.js` — réduction desktop/tablette persistée de manière sûre (`localStorage` dans `try/catch`), tiroir mobile `< 768 px` avec piège de focus, sous-menus repliables (`data-prime-sidebar-*`, `data-prime-submenu-toggle`).
- `dropdown.js` — navigation flèches `↑`/`↓`, `Début`/`Fin`, fermeture clic extérieur et `Échap` (`data-prime-dropdown`, `data-prime-dropdown-trigger`, `data-prime-dropdown-menu`).
- `modal.js` — API `<dialog>` native, clic arrière-plan, restauration du focus (`data-prime-modal-open`, `data-prime-modal-close`).
- `drawer.js` — tiroir latéral avec `focus-trap.js` et restauration du focus (`data-prime-drawer`, `data-prime-drawer-open`, `data-prime-drawer-close`).
- `tabs.js` — onglets clavier (`←`, `→`, `Début`, `Fin`) (`data-prime-tabs`).
- `toast.js` — pile de notifications `aria-live="polite"` (sous la topbar sur ordinateur/tablette, en bas pleine largeur sur mobile) avec pause au survol/focus, exposée via `window.Prime.toast(message, type, options)` ou déclenchable sans JS inline via `data-prime-toast-trigger`.
- `tooltip.js` — infobulles au survol et au focus clavier (`data-prime-tooltip`, `data-prime-tooltip-collapsed`, `data-prime-tooltip-placement`).
- `command-palette.js` — palette de commandes (`Ctrl/⌘ + K` ou `/`), recherche stricte par mots (insensible aux accents et à la casse, sur libellé, groupe et mots-clés — jamais sur l'URL), classement hiérarchique (`searchCommands()` exportée) (`data-prime-command-palette-open`, `data-prime-cmd-input`, `data-prime-cmd-results`).

---

## 7. Extensibilité du menu et de la coque

Les plugins peuvent enrichir la navigation, le menu « Créer », la palette de commandes et les assets via les hooks documentés dans [`docs/hooks.md`](hooks.md) (`admin.menu`, `admin.create.items`, `admin.command_palette`, `admin.assets`, `admin.head`, `admin.footer`).
