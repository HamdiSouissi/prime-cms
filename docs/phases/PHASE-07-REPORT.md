# Phase 7 — Médiathèque (v0.7.0)

## 1. Synthèse des livrables (L0 – L13)

| Lot | Périmètre | État | Fichiers clés |
|-----|-----------|------|---------------|
| **L0** | Préservation du statut lors de `Ctrl/⌘+S` dans Prime Editor + suivi `lockLost` | Terminé | `public/assets/admin/js/editor/core.js`, `tests/js/editor.test.mjs`, `tests/Integration/AdminContentHttpTest.php` |
| **L1** | Schéma SQL (`media_folders`, `media`, `media_usage`, `users.avatar_media_id`), configuration `config/media.php`, modèles & dépôts (`Media`, `MediaFolder`, `MediaConfig`, `MediaRepository`, `MediaFolderRepository`, `MediaUsageRepository`) | Terminé | `database/migrations/2026_11_15_000015..000018_*.php`, `config/media.php`, `src/Media/*` |
| **L2** | Pipeline de traitement d'image natif GD (auto-rotation EXIF, suppression métadonnées, transparence alpha PNG/WebP/GIF, variantes `thumbnail`/`medium`/`large` + WebP parallèles, recadrage centré sur le point focal) | Terminé | `src/Media/ImageProcessor.php` |
| **L3** | Service d'upload défensif (`UploadService` : 8 barrières sécurité, SHA-256 doublons, remplacement préservant ID/métadonnées, suppression atomique disque + BDD, 6 hooks) | Terminé | `src/Media/UploadService.php`, `src/Media/MediaServiceProvider.php` |
| **L4** | Défense stockage & exposition web : `UploadsGuard` (`.htaccess` anti-PHP/anti-listing/CSP sandbox + symlink `public/uploads`), repli PHP `MediaServingController` (`ETag`/`304`, `HEAD`, `Range 206/416`, `Content-Disposition`), durcissement Apache/Nginx/Docker + `ext-exif` + `Installer` | Terminé | `src/Media/UploadsGuard.php`, `src/Media/MediaServingController.php`, `public/.htaccess`, `docs/nginx.conf.example`, `docker/php/*`, `src/Install/Installer.php` |
| **L5** | Contrôleur admin `MediaController` (`/admin/media` + 12 endpoints JSON sécurisés RBAC/CSRF) & interface complète (arborescence dossiers, grille/liste persistée, recherche, filtres type/date/mes fichiers/non utilisés, sélection Shift+clic, actions groupées, tiroir inspecteur + point focal interactif, drag & drop global + file d'upload parallèle max 3) | Terminé | `src/Admin/Controller/MediaController.php`, `resources/views/admin/media/index.php`, `public/assets/admin/js/media/{selection,upload-queue,library}.js` |
| **L6** | Sélecteur de média réutilisable `Prime.media.open()` + widget déclaratif `[data-prime-media-field]` | Terminé | `public/assets/admin/js/media/picker.js`, `public/assets/admin/js/admin.js` |
| **L7** | Intégration transverse : 15 blocs Prime Editor (`image` amélioré `<picture>` WebP + `gallery`, `video`, `audio`, `file`, collage/glisser-déposer d'images), image à la une, champ personnalisé `media` (simple/multiple), miniatures 40×40 dans les listings, suivi `pc_media_usage`, avatars utilisateurs (profil, topbar, listing), KPI live Tableau de bord, extension `hello-prime` | Terminé | `src/Editor/BlockRegistry.php`, `public/assets/admin/js/editor/{blocks,core}.js`, `src/Content/*`, `src/Admin/*`, `resources/views/admin/*`, `content/plugins/hello-prime/src/Plugin.php` |
| **L8** | Design system : 7 nouvelles icônes Lucide dans `sprite.svg`, feuille `media.css` alignée sur les jetons `tokens.css`, recompilation `public/assets/admin/css/admin.css` (112,70 Ko) | Terminé | `public/assets/admin/icons/sprite.svg`, `resources/assets/admin/css/components/media.css`, `public/assets/admin/css/admin.css` |
| **L9** | Helpers responsive `media_url()`, `media_srcset()`, `media_image()` (globaux + `TemplateContext`) | Terminé | `src/Media/MediaHelper.php`, `src/Support/helpers.php`, `src/View/TemplateContext.php` |
| **L10** | Commandes CLI `media:regenerate`, `media:link`, `media:cleanup` (`--dry-run`) | Terminé | `src/Console/Commands/Media{Regenerate,Link,Cleanup}Command.php`, `src/Console/ConsoleApplication.php` |
| **L11** | Permissions RBAC (`media.view`, `media.upload`, `media.delete_any`) & colonne `users.avatar_media_id` | Terminé | `src/Auth/{Capabilities,User,UserRepository}.php`, `database/migrations/2026_11_15_000006_seed_default_roles.php` |
| **L12** | Suite de tests complète (fixtures binaires déterministes, sécurité/polyglotte/EXIF/point focal, serving/ETag/Range, HTTP/RBAC 5 rôles/CSRF/usages/CLI, DB MySQL & MariaDB, JS `SelectionState` & `UploadQueue`, CI `ext-exif`) | Terminé | `tests/Support/MediaFixtures.php`, `tests/Unit/Media/*`, `tests/Integration/{AdminMediaHttpTest,MediaDatabaseIntegrationTest}.php`, `tests/js/media.test.mjs`, `.github/workflows/ci.yml` |
| **L13** | Documentation complète (`docs/media.md`, `docs/hooks.md`, `docs/editor.md`, `README.md`, rapport de phase) | Terminé | `docs/media.md`, `docs/hooks.md`, `docs/editor.md`, `README.md`, `docs/phases/PHASE-07-REPORT.md` |

---

## 2. Sécurité, architecture et qualité vérifiées

- **Défense en profondeur contre les uploads malveillants** :
  - Double validation extension + MIME réel (`finfo_file()`), rejet strict des scripts (`.php`, `.phtml`, `.svg`, `.html`), doubles extensions (`shell.php.jpg`), noms avec traversée (`../`), fichiers vides, fichiers dépassant `min(MEDIA_MAX_UPLOAD_MB, upload_max_filesize, post_max_size)` et bombes à pixels (> 40 Mpx avant décodage GD).
  - Rejet immédiat des polyglottes PHP en en-tête **et** ré-encodage GD systématique éliminant 100 % des métadonnées EXIF/GPS ainsi que tout octet résiduel en fin de fichier.
  - Exécution de scripts impossible dans `content/uploads/` (`php_flag engine off`, `Options -Indexes`, `Require all denied`, CSP `sandbox`, `nosniff`).
- **RBAC et propriété** : matrice vérifiée sur les 5 rôles (Administrateur, Éditeur, Auteur, Contributeur, Abonné) — un Auteur ne peut ni éditer, ni remplacer, ni régénérer, ni supprimer (unitairement ou en lot) un média d'un autre auteur (`403`).
- **Zéro dépendance runtime** : PHP 8.2/8.3 natif + GD + EXIF + finfo, JS Vanilla ES2022 sous CSP stricte, zéro script inline.
- **Vérification navigateur réel (Chromium 153 / Playwright)** :
  - A permis de détecter et corriger 4 défauts réels en conditions navigateur avant fusion :
    1. Ajout de `<meta name="csrf-token">` dans `resources/views/admin/layouts/app.php` pour les requêtes `fetch`/`XHR` authentifiées ;
    2. Enregistrement des routes multi-segments `/uploads/{s1}/{s2}/{s3}` (1 à 4 segments) dans `MediaServiceProvider` pour correspondre au routeur par segments de Prime CMS ;
    3. Alignement des attributs `data-prime-media-*` et `<script type="application/json" data-prime-media-initial>` entre les vues (`edit.php`, `field-group.php`, `profile/index.php`) et `picker.js` ;
    4. Alignement à 100 % des variables CSS de `media.css` sur les jetons réels de `tokens.css` (`--surface`, `--surface-subtle`, `--color-border`, `--shadow-modal`, etc.).
  - Vérifié sur 1440×900 et 375×812 (mobile) : création de dossier, upload parallèle de 3 fichiers réels (JPEG, PNG transparent, PDF), tiroir inspecteur + point focal, vue liste + file d'upload flottante, modale sélecteur dans Prime Editor + sélection image à la une, KPI live Tableau de bord — **0 erreur console, 0 erreur JS, 0 image cassée, 0 réponse HTTP ≥ 400, 0 débordement horizontal mobile**.

---

## 3. Résultats des vérifications automatisées

- **PHPUnit (Unit + HTTP Integration)** : 432 tests passés, 0 échec, 5 555 assertions (dont 10 tests d'intégration HTTP dans `AdminMediaHttpTest`).
- **Tests JS natifs (`node tests/js/run.mjs`)** : 29 tests passés, 0 échec (Prime Editor + `SelectionState` + `UploadQueue` + contrats `Prime.media.open` / `Prime.toast`).
- **PHP-CS-Fixer (PSR-12)** : 0 fichier à corriger.
- **PHPStan (niveau 8)** : vérifié en CI (PHP 8.2 & 8.3).
- **Tests SQL réels (`#[Group('database')]`)** : 29 tests (dont 6 dédiés à la médiathèque dans `MediaDatabaseIntegrationTest`), vérifiés en CI sur MySQL 8.0 (PHP 8.2 & 8.3) et MariaDB 10.6 (PHP 8.3).
- **Smoke test Docker Compose** : vérifié en CI (installation CLI, `media:link`, `/admin/media`, extension `exif`, migration `000018`).

---

## 4. Corrections (revue 1)

### C1 — Unification de `Prime.media.open()` dans Prime Editor & suppression des gardes silencieuses

- **Cause identifiée** : `public/assets/admin/js/editor/blocks.js` appelait `window.Prime.media.openPicker(...)` (6 occurrences) avec des gardes silencieuses (`if (!window.Prime?.media?.openPicker) return;`), alors que l'unique API exposée par `public/assets/admin/js/media/picker.js` est `Prime.media.open(...)`. En outre, le champ URL du bloc `image` utilisait `type="url"`, ce qui bloquait la soumission native HTML5 pour les chemins relatifs `/uploads/…`.
- **Corrections appliquées** :
  - Ajout du helper unique `openBlockMediaPicker(options, onPicked)` dans `public/assets/admin/js/editor/blocks.js` appelant exclusivement `window.Prime.media.open({ multiple, kinds, selected, title, onSelect })` pour les 6 déclencheurs (`image`, `gallery`, `video` source + poster, `audio`, `file`).
  - Suppression de toutes les gardes silencieuses : si `Prime.media.open` est indisponible, une erreur explicite est journalisée via `console.error(...)` et un toast `danger` « Le sélecteur de médias n'a pas pu s'ouvrir. » est affiché.
  - Passage de `urlInput` du bloc `image` en `type="text"` (`inputMode="url"`) afin d'accepter les chemins relatifs `/uploads/…` lors de l'enregistrement de l'article.
  - Vérification de la correspondance exacte des schémas JS ↔ `Prime\Editor\BlockRegistry` PHP (`image`, `gallery` avec `items`, `video` + `posterMediaId`/`posterUrl`, `audio`, `file`).
  - Mise à jour de la section 6 de `docs/media.md` et styles de rendu `.pc-prose` pour `gallery`, `file`, `audio` et `video` dans `resources/assets/admin/css/components/media.css` (recompilé dans `public/assets/admin/css/admin.css`).
  - **Tests JS ajoutés (`tests/js/media.test.mjs`)** : vérification statique que `blocks.js` n'invoque que des méthodes réellement exportées par `mediaApi` (zéro `openPicker`), test runtime des 5 blocs vérifiant les patches produits pour `BlockRegistry`, et test vérifiant que l'absence de `Prime.media.open` journalise une erreur et affiche le toast d'erreur.
- **Parcours vérifié en navigateur réel (Chromium 153 / Playwright)** sur `/admin/content/post/create` pour les 5 blocs :
  1. **`image`** : insertion via `/image` → clic « Choisir dans la médiathèque » → sélection de `banner.jpg` → aperçu image + badge `Média #` + URL/alt remplis → enregistré → rendu `<picture>` + `<source type="image/webp">` + `srcset` dans l'aperçu ;
  2. **`gallery`** : insertion via `/galerie` → clic « Ajouter des images » → sélection multiple (`banner.jpg`, `portrait.png`) → grille d'aperçu 2 cartes remplie → enregistré → rendu `.pc-block--gallery` en grille responsive avec `<picture>` WebP dans l'aperçu ;
  3. **`file`** : insertion via `/fichier` → clic « Choisir un fichier » → sélection de `brochure.pdf` → badge `brochure.pdf` et libellé remplis → enregistré → carte `.pc-block--file` (`brochure`, `PDF · 322 o`, bouton `Télécharger`) dans l'aperçu ;
  4. **`audio`** : insertion via `/audio` → clic « Choisir un fichier audio » → sélection de `podcast.wav` → lecteur `<audio controls src="/uploads/…">` + titre remplis → enregistré → lecteur `<audio>` fonctionnel dans l'aperçu ;
  5. **`video`** : insertion via `/mp4` → clic « Choisir une vidéo » (`demo.mp4`) + clic « Image poster » (`banner.jpg`) → lecteur `<video controls poster="/uploads/…">` rempli → enregistré → lecteur `<video>` avec poster dans l'aperçu (0 image cassée, 0 erreur console/HTTP).

### C2 — Correction des toasts « [object Object] »

- **Cause identifiée** : `showToast(message, type, options)` dans `public/assets/admin/js/toast.js` attend une chaîne en premier argument, tandis que les 19 appels du module médias (`library.js` : 18, `picker.js` : 1) passaient un objet `{ type, title }`.
- **Corrections appliquées** :
  - Conversion de l'ensemble des 19 appels `window.Prime?.toast?.(...)` dans `public/assets/admin/js/media/library.js` et `public/assets/admin/js/media/picker.js` vers la signature canonique `Prime.toast(message, type)`.
  - Ajout d'un test de non-régression JS dans `tests/js/media.test.mjs` interdisant tout appel `toast({ ... })` dans le module médias et `editor/blocks.js`.
  - Vérifié en navigateur réel : toast doublon à l'envoi (« Un fichier identique existe déjà dans la médiathèque (« banner »). ») et toast d'enregistrement (« Métadonnées enregistrées. ») affichés en texte lisible, zéro `[object Object]`.

### C3 — Serveur de développement (`public/router.php`) & blocage des fichiers cachés `/uploads/.htaccess`

- Ajout de `public/router.php` : bloque `403` les fichiers cachés (`.htaccess`, `.env`, `/uploads/.htaccess`, etc.) et scripts/HTML/SVG sous `/uploads/`, retourne `false` pour les fichiers statiques légitimes existants dans `public/`, et délègue tout le reste (y compris `/uploads/YYYY/MM/…` sans lien symbolique) à `public/index.php`.
- Mise à jour de `README.md`, `docs/media.md` et `docs/install.md` avec `php -S localhost:8080 -t public public/router.php`.
- Durcissement anti-fichiers cachés via le lien symbolique `/uploads/` dans :
  - `docs/nginx.conf.example` (`location ~ /\. { return 403; }` à l'intérieur de `location ^~ /uploads/` pour contrer la priorité du préfixe `^~`) ;
  - `src/Media/UploadsGuard.php` (`<FilesMatch "^\."> Require all denied </FilesMatch>` dans le `.htaccess` auto-généré de `content/uploads/`) ;
  - `public/.htaccess` et `docker/php/apache.conf` (`<FilesMatch "^\."> Require all denied </FilesMatch>`).

### C4 — Renforcement des tests d'intégration (HTTP + SQL réel MySQL 8.0 / MariaDB 10.6)

- **`tests/Integration/AdminMediaHttpTest.php`** étendu de 3 à **10 tests d'intégration HTTP** couvrant :
  1. Matrice RBAC complète (5 rôles : Invité, Abonné, Contributeur, Auteur, Éditeur, Administrateur) sur **chacun des 12 endpoints JSON** + protection CSRF (`419`) sur **chacun des 9 endpoints mutatifs** ;
  2. Rejet HTTP `422` de **chacun des 9 fichiers malveillants** (polyglotte JPEG+PHP, `.php` renommé `.jpg`, `photo.php.jpg`, `script.html.png`, faux PDF, SVG `<script>`, fichier vide, PNG 100 Mpx, traversée `../../evil.jpg`) avec **0 fichier écrit sur disque** et **0 ligne BDD** ;
  3. Cycle complet des dossiers (création, renommage, déplacement, refus de boucle `422`, suppression avec remontée des sous-dossiers et médias) ;
  4. Changement de point focal vérifiant le recadrage réel (pixels rouge vs bleu), régénération explicite, et remplacement préservant ID et métadonnées tout en purgeant les anciens fichiers ;
  5. Suppression d'un média vérifiant la suppression de l'original + toutes variantes/WebP sur disque et le nettoyage de `pc_media_usage` et `users.avatar_media_id` ;
  6. Repli de diffusion `/uploads/` (`200`, `304`, `HEAD`, `Range 206`/`416`, PDF inline, ZIP `attachment`, refus `403`/`404` sur `/uploads/.htaccess`, dotfiles, scripts et traversées) ;
  7. Cycle de vie de l'avatar utilisateur (`/admin/profile`, barre supérieure `/admin`, `/admin/users`, usages, suppression) ;
  8. Champs personnalisés `media` simple et multiple (persistance meta, `pc_media_usage` `field:*`, widgets `[data-prime-media-field]` sur la page d'édition, nettoyage) ;
  9. Rendu des blocs (`<picture>`, `srcset`, `gallery`, `file`), miniature `40×40` dans les listings et KPI live du Tableau de bord ;
  10. Commandes CLI (`media:regenerate`, `media:link` idempotente + vérification `.htaccess`, `media:cleanup --dry-run` puis exécution).
- **`tests/Integration/MediaDatabaseIntegrationTest.php`** (`#[Group('database')]`) étendu de 1 à **6 tests SQL réels** (MySQL 8.0 & MariaDB 10.6) couvrant migrations/FK/rollback, rejet des 8 fichiers malveillants avec 0 fichier disque/0 ligne BDD, hiérarchie de dossiers, point focal + remplacement + suppression complète, pagination/filtres/FULLTEXT/doublon SHA-256/stats, et suivi d'usages multi-contextes (`thumbnail`, `block`, `field:*`, `avatar`).

### C5 — Vérification finale en navigateur réel (Chromium 153 / Playwright)

Parcours complet exécuté et inspecté visuellement (captures vérifiées, **0 erreur console**, **0 erreur JS**, **0 réponse HTTP ≥ 400**, **0 image cassée**, **0 toast `[object Object]`**) :
- **Médiathèque en vue Grille et vue Liste à 1440×900 et 375×812** : correction CSS responsive (`grid-template-columns: minmax(0, 1fr)` et `.pc-media-list .pc-table-wrap { overflow-x: auto }`) garantissant **0 débordement horizontal** à 375 px aussi bien en grille qu'en liste ;
- **Envoi par glisser-déposer** : dépôt simultané de 5 fichiers réels (`banner.jpg`, `portrait.png`, `brochure.pdf`, `podcast.wav`, `demo.mp4`), file d'envoi terminée 5/5, puis doublon `banner.jpg` affichant le toast d'avertissement lisible ;
- **Fiche inspecteur et point focal au clavier** : focus sur `.pc-focal-stage`, déplacement par flèches (`ArrowRight` ×3, `ArrowDown` ×2 → passage de `50% / 50%` à `56% / 54%`), enregistrement des métadonnées et toast « Métadonnées enregistrées. » ;
- **Sélecteur depuis chaque bloc Prime Editor (C1)** : `image`, `gallery` (2 images), `file`, `audio`, `video` + poster, aperçus dans l'éditeur, enregistrement et aperçu complet de l'article (`4 <picture>` WebP, galerie, carte téléchargement, lecteurs audio et vidéo) ;
- **Image mise en avant** : sélection via la modale dans la barre latérale, aperçu miniature et persistance à l'enregistrement ;
- **Avatar dans « Mon profil »** (`/admin/profile`) : sélection de `portrait.png` via le sélecteur, enregistrement, affichage confirmé dans la fiche profil et dans la barre supérieure ;
- **Champ « Photo du client » de `hello-prime`** (`/admin/content/testimonial/create`) : sélection de `portrait.png` via le sélecteur modal dans « Détails du témoignage », enregistrement du témoignage et vérification de la persistance après rechargement sur la page d'édition.

---

## 5. Prochaine étape

**Phase 8 — Système de thèmes & moteur de rendu front** : résolution de thèmes dans `content/themes/`, hiérarchie de templates, chargement des menus/contenus/médias responsive (`media_image()`), personnaliseur et thème par défaut Prime.
