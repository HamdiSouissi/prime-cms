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

---

## 6. Revue 1 — Corrections (C1–C9) et vérifications navigateur réel

### 6.1 Correctifs livrés

| Point | Périmètre corrigé | Fichiers clés |
|-------|-------------------|---------------|
| **C1** | Bug bloquant `ThemeContext::script()` dans Aurora (`[], ['defer' => true]` au lieu de `['defer' => true]` en 3ᵉ argument `$deps`) ; ajout de `content/themes` et `content/plugins/hello-prime` dans `phpstan.neon.dist` (templates/partials/views exclus) ; journalisation d'erreur de repli `ThemeManager::recordDefaultThemeFailure()` ; resynchronisation de `options-{hash}.css` dans `SiteRenderer::render()` | `content/themes/aurora/src/Theme.php`, `phpstan.neon.dist`, `src/Themes/ThemeManager.php`, `src/Site/SiteRenderer.php` |
| **C2** | Blocs dynamiques sans JSON brut : 4 blocs cœur typés (`latest_entries`, `term_list`, `menu`, `search_form`) déclarant leur schéma de champs (`text`, `number`, `toggle`, `select`) et rendu serveur complet (vignettes + extraits préchargés sans N+1, compteur de termes, menus sauvegardés, recherche accessible sans réglage) ; rendu dynamique unifié dans les zones **et** dans les articles/pages (`EntryView::content()`, `ZoneRepository`) ; éditeur visuel de blocs dynamiques avec formulaires typés et résumé lisible en temps réel (0 textarea JSON) partagé entre Prime Editor et Zones ; migration du bloc `hello_testimonial` de `hello-prime` sur le schéma `fields` ; tests unitaires JS dédiés | `src/Site/Zone/{DynamicBlockRegistry,ZoneRepository}.php`, `src/Site/View/EntryView.php`, `src/Site/SiteServiceProvider.php`, `content/plugins/hello-prime/src/Plugin.php`, `resources/views/admin/appearance/zones.php`, `public/assets/admin/js/{zones-admin,editor/core}.js`, `tests/js/appearance.test.mjs` |
| **C3** | Prévisualisation et liens « Voir » : correction de `Entry::previewUrl()` (`/admin/content/{type}/{id}/preview`), aperçu d'URL temps réel fidèle au `UrlGenerator` (`__PRIME_SLUG__`) dans l'éditeur de contenu, affichage du lien « Voir » vers l'URL publique dans la barre d'édition et la liste des contenus pour les entrées publiées | `src/Content/Entry.php`, `src/Admin/Controller/ContentController.php`, `src/Admin/AdminServiceProvider.php`, `resources/views/admin/content/{edit,index}.php`, `public/assets/admin/js/content-admin.js` |
| **C4** | Styles complets des 15 blocs Prime Editor dans Aurora (`aurora.css` = **29 150 o / 28,47 Ko**, sous le budget de 30 Ko) : `.pc-block--gallery` / `.pc-gallery__grid` (2/3/4 colonnes, passage en 2 colonnes sous 640 px), `.pc-block--table` avec défilement horizontal sécurisé sur mobile, `<video>` 100 % arrondi, `<audio>` pleine largeur, listes à puces/numérotées, rythme des titres et blocs dynamiques ; fixture complète **« Tous les blocs »** (15 blocs cœur + bloc dynamique) | `content/themes/aurora/assets/css/aurora.css`, `database/seeds/tous-les-blocs.php` |
| **C5** | Avis unique de bascule de thème (`theme.fallback`) : remplacement de l'avis sans accumulation, effacement automatique à l'activation/suppression/démarrage sain ou lors de la fermeture (`NoticeController`), suppression du bandeau statique redondant dans `themes.php` | `src/Themes/ThemeManager.php`, `src/Admin/Controller/NoticeController.php`, `src/Admin/AdminServiceProvider.php`, `resources/views/admin/appearance/themes.php` |
| **C6** | Balises `<image:image>` (`xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"`) dans les sitemaps XML d'entrées : image mise en avant + blocs `image` et `gallery`, préchargement par lots (`MediaRepository::findByIds()`, zéro N+1) et déduplication | `src/Site/Seo/SitemapGenerator.php`, `src/Site/SiteServiceProvider.php` |
| **C7** | Extension massive des tests d'intégration HTTP (`SiteRenderingTest` : **10 tests, 227 assertions**) et SQL réels MySQL 8.0 / MariaDB 10.6 (`SiteDatabaseIntegrationTest` : parcours complet HTTP + DB, redirections 301, menus imbriqués + rejet `javascript:`, zones + 4 blocs dynamiques, SEO/sitemap `<image:image>`/robots/CSP, matrice permissions contributeur vs admin, isolation bascule/enfant/ZIP malveillant, preuve zéro N+1 à 10 vs 30 articles avec nombre de requêtes SQL constant) | `tests/Integration/SiteRenderingTest.php`, `tests/Integration/SiteDatabaseIntegrationTest.php` |
| **C8** | Finitions design : licence Aurora passée à `"proprietary"`, déplacement du badge « Actif » dans le corps de carte à côté du titre (sans chevauchement sur l'aperçu), passage des boutons « Supprimer » en `.pc-btn--danger-outline`, recompilation de `public/assets/admin/css/admin.css` (**124,18 Ko**) | `content/themes/aurora/theme.json`, `resources/views/admin/appearance/{themes,menus}.php`, `resources/views/admin/settings/permalinks.php`, `public/assets/admin/js/menus-admin.js`, `resources/assets/admin/css/components/appearance.css`, `public/assets/admin/css/admin.css` |

### 6.2 Anomalies supplémentaires détectées et corrigées pendant les tests C7 & navigateur réel

1. **Clé méta d'image mise en avant** : `EntryViewFactory`, `SeoManager` et `SitemapGenerator` lisaient `$entry->meta('featured_image')` au lieu de `$entry->featuredMediaId()` (`_featured_media_id`) — corrigé dans les trois classes.
2. **Légende média nullable** : `SitemapGenerator::extractEntryImages()` vérifie désormais `$media->caption !== null && $media->caption !== ''` avant repli sur `$media->alt`.
3. **Slug de taxonomie des étiquettes** : alignement du catalogue de repli de `DynamicBlockRegistry` et `zones-admin.js` sur `'tag'` (taxonomie officielle Prime CMS).
4. **Envoi explicite des cookies same-origin** : ajout de `credentials: 'same-origin'` sur les appels `fetch()` du Personnaliseur (`public/assets/admin/js/customizer.js`).

### 6.3 Vérifications navigateur réel (Chromium 153.0.8010.0) & captures visuelles

Exécutées contre le noyau HTTP réel de Prime CMS (sans erreur console, sans erreur JS, sans image cassée, sans débordement horizontal) :

1. **Menu mobile Aurora (375 px)** : `<script src="...aurora.js..." defer>` vérifié, ouverture/fermeture au clic et à la touche `Escape`, `aria-expanded` synchronisé, `scrollWidth === 375`.
2. **Recherche en-tête (1440 px & 375 px)** : ouverture du panneau de recherche, soumission `Tous les blocs`, affichage des résultats sur `/recherche?q=Tous+les+blocs`.
3. **Personnalisation publiée visible côté visiteur** : modification de `--aurora-accent` (`#1D4ED8`) dans `/admin/appearance/customizer`, publication (`HTTP 200`, nouveau hash `options-62a570d133.css`), vérification immédiate côté visiteur anonyme (`getComputedStyle` = `#1D4ED8`).
4. **Les 4 blocs dynamiques (barre latérale + article)** : insertion et configuration sans JSON dans `/admin/appearance/zones`, enregistrement et rechargement confirmant la persistance exacte de chaque option, rendu public complet sur `/blog` ; insertion dans un nouvel article, aperçu d'URL en direct (`/blog/article-dyn-e2e`), lien « Voir » et rendu public du bloc dans l'article.
5. **Aperçu d'un article brouillon** : liens « Aperçu » (`/admin/content/post/3/preview`) et « Voir » (`/blog/tous-les-blocs`) vérifiés dans la liste et l'éditeur, rendu du brouillon dans Aurora avec bandeau d'aperçu.
6. **Galerie et styles de tous les blocs** : `.pc-block--gallery` (`display: block`), `.pc-gallery--cols-3 .pc-gallery__grid` (`3` colonnes à 1440 px, `2` colonnes à 375 px), `.pc-gallery--cols-2 .pc-gallery__grid` (`2` colonnes), `.pc-block--table` (`overflow-x: auto`, défilement interne sans débordement de page à 375 px), `<video>` 100 % arrondi, `<audio>` pleine largeur, listes `disc` / `decimal`.
7. **Article « Tous les blocs » (captures visuelles inspectées)** :
   - Desktop **1440 px** (`scrollWidth = 1440`, 9/9 images chargées, 0 erreur) : `docs/phases/screens/phase-08/tous-les-blocs-desktop-1440.png`
   - Mobile **375 px** (`scrollWidth = 375`, 0 débordement horizontal, 0 erreur) : `docs/phases/screens/phase-08/tous-les-blocs-mobile-375.png`

### 6.4 Résultats finaux des suites automatisées (post-Revue 1)

- **PHPUnit (Unit + HTTP Integration)** : **452 tests passés**, 0 échec, 0 ignoré, **6 369 assertions**.
- **Tests JS natifs (`node tests/js/run.mjs`)** : **39 tests passés**, 0 échec.
- **PHP-CS-Fixer (PSR-12)** : **0 / 400 fichiers à corriger**.
- **Poids des assets Aurora** : `aurora.css` = **29 150 o** (28,47 Ko < 30 Ko), `aurora.js` = **2 087 o** (2,04 Ko < 8 Ko).

