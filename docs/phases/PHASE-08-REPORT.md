# Phase 8 — Thèmes et rendu du site (v0.8.0)

- **Branche** : `arena/a0991795-prime-cms`
- **Version noyau** : `0.8.0` (`Prime\Kernel\Application::VERSION`)
- **Migrations du noyau** : 23 (`000001` à `000023`)

---

## 1. Synthèse des livrables (L0 – L14)

| Lot | Périmètre | État | Fichiers clés |
|-----|-----------|------|---------------|
| **L0** | Prérequis noyau : correctif signature `Prime.toast(msg, type)` dans `content-admin.js` + test JS, bump `0.8.0`, `Router::setFallback()`, `Request::wantsJson()`, permission `themes.manage`, migrations `000019`–`000023` (`pc_redirects`, `pc_menus`, `pc_menu_items`, `pc_zones`, `pc_media_zone_usage`, `themes.manage`, `users.username`/`users.bio`), suivi `MediaUsageRepository::syncForZoneDocument()` | Terminé | `public/assets/admin/js/content-admin.js`, `src/Http/{Router,Request}.php`, `database/migrations/2026_11_15_000019..000023_*.php`, `src/Media/MediaUsageRepository.php` |
| **L1** | Moteur de thèmes (`Prime\Themes\*`) : manifeste `theme.json` + validation SemVer (`ThemeManifest`, `ThemeManifestValidator`), découverte + héritage parent/enfant 1 niveau + isolation fatale `FatalLoadGuard` + bascule Aurora + `PRIME_SAFE_MODE` (`ThemeManager`), publication assets statiques (`ThemeAssetPublisher`), registre versionné (`ThemeAssetRegistry`), `ThemeContext`, `ThemeServiceProvider`, configuration `config/themes.php` et commandes CLI (`themes:list`, `themes:activate`, `themes:publish`) | Terminé | `src/Themes/*`, `config/themes.php`, `src/Console/Commands/Theme{List,Activate,Publish}Command.php` |
| **L2** | Permaliens, résolution d'URL & redirections 301 : `PermalinkSettings` (3 structures + lecture accueil/blog/pagination), `ReservedSlugValidator`, `UrlGenerator` (avec `Entry::url()` / `Term::url()`), `UrlResolver` (front, blog, singles, pages hiérarchiques, archives CPT, taxonomies, auteur, recherche, pagination stricte, 404), `RedirectRepository` (301 auto sur `content.updated`, raccourcissement de chaînes, prévention des boucles, compteur de hits) | Terminé | `src/Site/{PermalinkSettings,ReservedSlugValidator,UrlGenerator,UrlResolver,RedirectRepository}.php`, `src/Content/{Entry,Term,EntryService}.php` |
| **L3** | Hiérarchie de templates & vues sûres : `TemplateResolver` (enfant → parent → plugins, protection traversée), `SiteRenderer`, `SiteView` & `EntryView` (échappement HTML par défaut, variantes `raw*`), `EntryViewFactory` (préchargement par lots auteurs + médias zéro N+1), `UserRepository::findByIds()` / `findByAuthorSlug()` | Terminé | `src/Site/{TemplateResolver,SiteRenderer}.php`, `src/Site/View/{SiteView,EntryView,EntryViewFactory}.php`, `src/Auth/{User,UserRepository}.php` |
| **L4** | Menus de navigation (`pc_menus`, `pc_menu_items`) : `MenuRepository` (CRUD, arbre max 3 niveaux, sanitisation URL stricte, alias `url`/`custom`), `MenuRenderer` (masquage des entrées non publiées, `aria-current="page"`, `rel="noopener noreferrer"`, filtre `site.menu_items`) | Terminé | `src/Site/Menu/{MenuRepository,MenuRenderer}.php` |
| **L5** | Zones de blocs & 6 blocs dynamiques : `ZoneRepository` (scopé par thème `(theme, slug)`, rendu Prime Editor + suivi `pc_media_zone_usage`), `DynamicBlockRegistry` (`recent_posts`, `categories`, `tags`, `search`, `menu`, `author_bio`, filtre `site.dynamic_blocks`), bloc `dynamic` enregistré côté PHP et JS | Terminé | `src/Site/Zone/{ZoneRepository,DynamicBlockRegistry}.php`, `src/Site/SiteServiceProvider.php`, `public/assets/admin/js/zones-admin.js` |
| **L6** | SEO natif : `SeoSettings`, `SeoManager` (`<title>`, meta description, canonical, `rel=prev/next`, robots `noindex`, Open Graph, Twitter Cards, JSON-LD `WebSite`/`Article`/`BreadcrumbList` avec échappement `JSON_HEX_*`), `SitemapGenerator` (`/sitemap.xml` + `/sitemap-{type}-{page}.xml`), `RobotsGenerator` (`/robots.txt`) | Terminé | `src/Site/Seo/{SeoSettings,SeoManager,SitemapGenerator,RobotsGenerator}.php` |
| **L7** | Options de thème, favicons & Personnaliseur : `ThemeOptions` (validation 8 types de champs, génération CSS statique `options-{hash}.css` sans `<style>` inline, génération favicons PNG GD 32/180/192/512) | Terminé | `src/Themes/ThemeOptions.php` |
| **L8** | Installation ZIP sécurisée & CSP publique : `ThemeInstaller` (MIME, 20 Mo max, 2 000 fichiers max, 100 Mo décompressés max, ratio ZIP-bomb ≤ 100:1, anti-ZipSlip/symlinks, écriture atomique), `PublicCsp` (CSP stricte `script-src 'self'`, `style-src 'self'`, filtre `site.csp`) | Terminé | `src/Themes/{ThemeInstaller,PublicCsp}.php` |
| **L9** | Contrôleur public `SiteController` : routage fallback + délégation `HomeController::index()`, prévisualisations sécurisées (`?preview=1&entry_id=...`, `/admin/content/{type}/{id}/preview`, `?customizer_preview=1`), en-têtes `PublicCsp`, `X-Robots-Tag: noindex, nofollow`, `Cache-Control: no-store` | Terminé | `src/Site/Http/SiteController.php`, `src/Site/SiteServiceProvider.php`, `src/Http/Controller/HomeController.php` |
| **L10** | Thème officiel par défaut **Aurora** : design éditorial papier chaud + corail WCAG AA (`#C9441F`), polices locales Inter/Plus Jakarta Sans, 2 menus, 4 zones, 2 modèles de pages (`full-width`, `landing`), 2 formats d'images (`hero`, `card`), 13 options sur 5 sections, `screenshot.webp` 1200×900, `aurora.css` = 23,4 Ko (≤ 40 Ko), `aurora.js` = 2,0 Ko (≤ 8 Ko), 0 script/style inline | Terminé | `content/themes/aurora/*` |
| **L11** | Interfaces d'administration Phase 8 : **Apparence** (`Thèmes`, `Personnaliser` live iframe/`postMessage`, `Menus` 3 niveaux clavier/souris, `Zones` avec Prime Editor + blocs dynamiques, redirection `/admin/appearance/widgets` → `/admin/appearance/zones`), **Réglages** (`Lecture`, `Permaliens` + table redirections 301, `Référencement`), **Contenu** (panneau SEO + aperçu SERP, modèles de page, aperçu dans le thème actif), **Profil** (`username` & `bio`), CSS aligné sur `tokens.css` (0 hex codé en dur) recompilé dans `public/assets/admin/css/admin.css` (123,12 Ko) | Terminé | `src/Admin/Controller/{Theme,Menu,Zone,SiteSettings,Content,Profile}Controller.php`, `resources/views/admin/{appearance,settings}/*`, `public/assets/admin/js/{customizer,customizer-preview,menus-admin,zones-admin,seo-preview,admin}.js`, `resources/assets/admin/css/components/appearance.css` |
| **L12** | Extension du plugin `hello-prime` : bloc dynamique `hello_testimonial` via `site.dynamic_blocks` + template d'archive de repli `templates/archive/testimonial.php` via `site.plugin_template_paths` | Terminé | `content/plugins/hello-prime/src/Plugin.php`, `content/plugins/hello-prime/templates/archive/testimonial.php` |
| **L13** | Tests & CI : tests unitaires moteur de thèmes (`ThemeEngineTest`), moteur de site (`SiteCoreTest`), intégration HTTP bout en bout (`SiteRenderingTest`), intégration SQL réelle MySQL 8.0 / MariaDB 10.6 (`SiteDatabaseIntegrationTest`), tests JS natifs (`appearance.test.mjs`), mise à jour CI (`js-tests`, smoke test Docker Aurora + `/sitemap.xml` + `/robots.txt` + `themes:list` + 7 écrans admin) | Terminé | `tests/Unit/{Themes/ThemeEngineTest,Site/SiteCoreTest}.php`, `tests/Integration/{SiteRenderingTest,SiteDatabaseIntegrationTest}.php`, `tests/js/appearance.test.mjs`, `.github/workflows/ci.yml` |
| **L14** | Documentation complète : `docs/themes.md`, `docs/seo.md`, mises à jour `docs/hooks.md`, `docs/security.md`, `README.md` et présent rapport de phase | Terminé | `docs/{themes,seo,hooks,security}.md`, `README.md`, `docs/phases/PHASE-08-REPORT.md` |

---

## 2. Sécurité, architecture et qualité vérifiées

- **Défense en profondeur du rendu public** :
  - `PublicCsp` impose `script-src 'self'`, `style-src 'self'`, `object-src 'none'`, `frame-ancestors 'self'`, zéro script ou `<style>` inline (les variables CSS du Personnaliseur sont publiées dans `options-{hash}.css`).
  - `SiteView` et `EntryView` échappent par défaut toutes les sorties textuelles ; les templates sont verrouillés contre la traversée de répertoire (`TemplateResolver::isSafeRelativeTemplate()`).
  - Les contenus brouillons/privés retournent `404` aux anonymes ; la prévisualisation exige une session authentifiée autorisée (`EntryService::canEdit()`) et émet `X-Robots-Tag: noindex, nofollow` + `Cache-Control: no-store`.
  - L'aperçu live du Personnaliseur vérifie `themes.manage` côté serveur et `event.origin === window.location.origin` côté client.
- **Zéro dépendance runtime & conformité design system** :
  - PHP 8.2/8.3 natif, JS Vanilla ES2022 sans bundler ni dépendance externe.
  - Audit automatisé exécuté sur les **17 routes clés** (8 routes publiques Aurora + 7 écrans admin Phase 8 + création d'article + profil) : **0 script inline exécutable, 0 `<style>` inline, 0 handler `on*=`, 0 URL `javascript:`, 0 lien d'asset cassé, 0 icône SVG manquante, 100 % des `data-*` admin conformes à `data-prime-*` / `data-theme`, 0 couleur hexadécimale en dur dans `appearance.css`**.
- **Anomalies réelles détectées et corrigées par les tests bout-en-bout & PHPStan niveau 8** :
  1. Alignement de la migration `000020_create_menus_tables.php` sur `MenuRepository` (`location`, `type`, `target_id`, `target`, `css_class`) et passage de `insert()` à `insertGetId()` dans `MenuRepository` et `ZoneRepository`.
  2. Alignement de `ZoneRepository` sur le schéma scopé par thème `(id, theme, slug)` de la migration `000021`, passant `int $zoneId` à `MediaUsageRepository::syncForZoneDocument()`.
  3. Correction dans `UrlResolver` de `$result['last_page']` (au lieu de `total_pages`) et retour strict `404` sur les pages hors limites (`/page/99`).
  4. Correction dans `SiteController` de `new Response(...)` pour `/sitemap.xml`, `/sitemap-{type}-{page}.xml` et `/robots.txt`, et ajout de `EntryService::contentTypes()`.
  5. Correction dans `EntryView` et `SiteView` de la signature d'appel `MediaHelper::image($media, ['variant' => ...])`, et dans `ThemeOptions` de `$item->diskPath` et `Settings::delete()`.
  6. Correction dans `ThemeAssetPublisher::unpublish()` (`AssetPublisher::remove()`), dans `AuthServiceProvider` (marquage `authInstantiated` après construction réussie) et dans `SiteServiceProvider` (garde `app.key` avant résolution d'`Auth`).
  7. Correction dans Aurora (`templates/{entry,archive,term}/default.php`), `SeoManager` et `MenuController` vers `ContentType::label('singular'|'plural')` / `Taxonomy::label('singular')`.

---

## 3. Résultats des vérifications automatisées

- **PHPUnit (Unit + HTTP Integration)** : **447 tests passés**, 0 échec, 0 ignoré, **6 244 assertions** (vérifié sur PHP 8.2 & 8.3).
- **Tests SQL réels (`#[Group('database')]`)** : **tous passés** (incluant `SiteDatabaseIntegrationTest` : 23 migrations + rollback complet, hiérarchie menus 3 niveaux + cascade FK, zones + `pc_media_zone_usage`, redirections 301 + hits, `UserRepository::findByAuthorSlug`/`findByIds`) sur **MySQL 8.0 (PHP 8.2 & 8.3)** et **MariaDB 10.6 (PHP 8.3)**.
- **Tests JS natifs (`node tests/js/run.mjs`)** : **37 tests passés**, 0 échec (dont `appearance.test.mjs` + syntaxe ES2022 sur `content/themes/aurora/assets/js/aurora.js` et tous les scripts admin/plugins).
- **PHPStan (niveau 8)** : **0 erreur** (PHP 8.2 & 8.3).
- **PHP-CS-Fixer (PSR-12)** : **0 fichier à corriger**.
- **Smoke test Docker Compose** : **passé** (installation CLI, 23 migrations, `themes:list`, rendu public Aurora sur `/` et `/blog/bienvenue-docker`, `/sitemap.xml`, `/robots.txt` et 7 nouveaux écrans admin authentifiés).

---

## 4. Points hors périmètre notés pour les phases suivantes

- **Cache de pages HTML complètes & invalidation par tag** (prévu en **Phase 9**).
- **Traduction multilingue complète FR / EN / IT des libellés publics** (prévu en **Phase 9**).
- **Rendu visuel de sections/widgets Prime Builder dans les templates de thèmes** (prévu en **Phases 11–12**).

---

## 5. Prochaine étape

**Phase 9 — Réglages, langues, cache, mails, tâches** : pages de réglages généraux, i18n (FR, EN, IT), cache de pages et d'objets, client SMTP, planificateur de tâches et journal d'audit.
