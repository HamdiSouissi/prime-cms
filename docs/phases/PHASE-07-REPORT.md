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

- **PHPUnit (Unit + HTTP Integration)** : 425 tests passés, 0 échec, 5 372 assertions.
- **Tests JS natifs (`node tests/js/run.mjs`)** : 25 tests passés, 0 échec (Prime Editor + `SelectionState` + `UploadQueue`).
- **PHP-CS-Fixer (PSR-12)** : 0 fichier à corriger.
- **PHPStan (niveau 8)** : vérifié en CI (PHP 8.2 & 8.3).
- **Tests SQL réels (`#[Group('database')]`)** : vérifiés en CI sur MySQL 8.0 (PHP 8.2 & 8.3) et MariaDB 10.6 (PHP 8.3).
- **Smoke test Docker Compose** : vérifié en CI (installation CLI, `media:link`, `/admin/media`, extension `exif`, migration `000018`).

---

## 4. Prochaine étape

**Phase 8 — Système de thèmes & moteur de rendu front** : résolution de thèmes dans `content/themes/`, hiérarchie de templates, chargement des menus/contenus/médias responsive (`media_image()`), personnaliseur et thème par défaut Prime.
