# Rapport de Phase 5 — Système de plugins

- **Version livrée** : `0.5.0`
- **Branche** : `arena/63a44fc6-prime-cms`
- **Date** : 2026-11-15
- **Statut** : Complet (livrables L0 à L13 validés)

---

## 1. Synthèse des livrables (L0 – L13)

| ID | Livrable | Implémentation principale |
|----|----------|---------------------------|
| **L0** | Correction double sortie CLI sur exception + PHPStan niveau 8 + `0.5.0` | `src/Kernel/ErrorHandler.php` (`log_errors=0` en CLI, garde anti-réentrance, `formatCliException()`, `exit(1)`), `src/Kernel/Application.php` (`VERSION = '0.5.0'`), `phpstan.neon.dist` (`level: 8`), corrections typage sur `AdminMenu`, `Auth`, `EnvWriter`, `Hasher`, `View`. |
| **L1** | Structure `content/plugins/{slug}/`, `PluginInterface`, `AbstractPlugin`, validation stricte `plugin.json` | `src/Plugins/PluginInterface.php`, `AbstractPlugin.php`, `PluginManifest.php`, `ManifestValidator.php`, `PluginInfo.php`. |
| **L2** | API officielle `PluginContext` complète | `src/Plugins/PluginContext.php`, `PluginHooks.php`, `PluginRouter.php`, `PluginSettings.php`, `PluginCapabilities.php`, `PluginNotices.php`. |
| **L3** | Moteur SemVer 2.0 complet | `src/Support/Version.php`, `src/Support/Exception/InvalidVersionException.php` (**112 tests unitaires**, 361 assertions). |
| **L4** | Tables `pc_plugins` + `pc_migrations.source`, `PluginManager`, cycle de vie & graphe topologique | `database/migrations/2026_11_15_000007_add_source_to_migrations_table.php`, `2026_11_15_000008_create_plugins_table.php`, `MigrationRepository.php`, `Migrator.php`, `PluginRepository.php`, `DependencyResolver.php`, `PluginManager.php`, `Settings::allByPrefix()` / `deleteByPrefix()`. |
| **L5** | Chargement isolé, `FatalLoadGuard`, `Hooks` owner tracking, `PRIME_SAFE_MODE` | `src/Hooks/Hooks.php` (`withOwner`, `setOwnerErrorHandler`, `removeByOwner`, `inspect`), `src/Plugins/FatalLoadGuard.php`, `PluginManager::bootActivePlugins()`, `PluginServiceProvider.php`. |
| **L6** | Publication sécurisée d'assets statiques | `src/Plugins/AssetPublisher.php` (liste blanche d'extensions, exclusion PHP/dotfiles/symlinks, URL cache-busted `?v={version}-{mtime}`). |
| **L7** | Installateur ZIP sécurisé | `src/Plugins/PluginInstaller.php` (signature PK, MIME, anti-Zip Slip/traversal/NUL/backslash/symlink, interdiction `.phar`/`.phtml`/`.htaccess`/`.user.ini`, anti-ZIP bomb, swap atomique avec rollback). |
| **L8** | Service de notifications persistantes `Prime\Admin\Notices` | `src/Admin/Notice.php`, `src/Admin/Notices.php`, `src/Admin/Controller/NoticeController.php`, compteur cloche topbar et pile de bandeaux dans `resources/views/admin/layouts/app.php`. |
| **L9** | Interface `/admin/plugins` (`plugins.manage`) | `src/Admin/Controller/PluginController.php`, `resources/views/admin/plugins/index.php`, `resources/assets/admin/css/components/plugins.css` (compilé dans `admin.css`), `public/assets/admin/js/plugins.js`. Un seul bouton corail (`--brand-600`) sur l'écran, drawer d'upload drag-and-drop avec barre de progression, modale de suppression nommée, zéro script inline. |
| **L10** | Plugin de référence `hello-prime` + fixtures de test | `content/plugins/hello-prime/*` + `tests/Fixtures/plugins/{boot-throw,syntax-error,listener-throw,cycle-alpha,cycle-beta,impossible-prime,base-lib,dep-consumer}`. |
| **L11** | 7 commandes CLI plugins | `plugin:list`, `plugin:activate`, `plugin:deactivate`, `plugin:uninstall`, `plugin:install`, `plugin:publish-assets`, `make:plugin`. |
| **L12** | Qualité, tests Unit + MySQL/MariaDB + CI Docker smoke | PHPStan **niveau 8** sans erreur, 322 tests unitaires/HTTP + 13 tests `#[Group('database')]` (MySQL 8.0 & MariaDB 10.6), smoke test Docker activant `hello-prime` et vérifiant `GET /hello-prime`. |
| **L13** | Documentation complète | `docs/plugins.md`, `docs/hooks.md`, `README.md`, `.env.example`, `docs/phases/PHASE-05-REPORT.md`. |

---

## 2. Vérification d'adéquation de l'API pour Prime Builder (Phases 11–12) et Prime Commerce (Phases 13–14)

L'un des critères directeurs de la Phase 5 est que **Prime Builder** et **Prime Commerce** puissent être construits comme des plugins officiels strictement via `PluginContext`, sans aucun raccourci ni couplage interne dans `src/`.

| Besoin futur (Prime Builder / Prime Commerce) | Point d'API officiel couvert en Phase 5 | Verdict |
|-----------------------------------------------|------------------------------------------|---------|
| Tables SQL dédiées (blocs, modèles, produits, commandes, paniers, variantes) + mises à jour de schéma entre versions | `migrations/` avec `source = {slug}`, exécution automatique à l'activation et sur upgrade SemVer (`plugin.updated`), rollback atomique sur échec ou désinstallation | Couvert |
| Routes back-office protégées par RBAC (`/admin/builder/*`, `/admin/commerce/*`) | `$context->router()->adminGroup(..., capability: '...')` | Couvert |
| Routes front-office (boutique, panier, tunnel de commande, webhooks paiement, prévisualisation builder) | `$context->router()->frontGroup(...)` + méthodes HTTP `get/post/put/patch/delete` | Couvert |
| Permissions dédiées visibles dans la matrice `/admin/roles` (ex. `commerce.orders.manage`, `builder.templates.edit`) | `$context->capabilities()->register()` / `registerMany()` groupées automatiquement sous le nom du plugin | Couvert |
| Entrées de menu latérales, sous-menus et palette de commandes | Filtres `admin.menu`, `admin.create.items`, `admin.command_palette` via `$context->hooks()` | Couvert |
| Raccourcis et tuiles KPI sur le tableau de bord `/admin` | Filtres `admin.dashboard.shortcuts` et `admin.dashboard.stats` | Couvert |
| Réglages isolés (devises, TVA, clés passerelles paiement, options éditeur) | `$context->settings()` préfixé automatiquement `plugin.{slug}.` avec typage JSON | Couvert |
| Templates front et back dédiés | `$context->views()` avec namespace `{slug}::` enregistré automatiquement | Couvert |
| Assets CSS/JS/icônes (éditeur drag-and-drop, styles boutique) publiés sans exposer PHP | `AssetPublisher` + `$context->url()` cache-busted + filtre `admin.assets` | Couvert |
| Services internes & injection de dépendances (panier, moteurs de rendu, passerelles) | `$context->app()->container()` (singletons PSR-4 enregistrés dans `register()`) | Couvert |
| Commandes CLI dédiées (`commerce:sync`, `builder:compile`) | Hook `console.commands` via `$context->hooks()->on('console.commands', ...)` | Couvert |
| Journalisation métier dédiée (paiements, webhooks, erreurs d'export) | `$context->logger()` -> canal `plugin.{slug}` | Couvert |
| Alertes persistantes administrateur (clé Stripe manquante, rupture de stock, mise à jour requise) | `$context->notices()->add(...)` avec ciblage par capacité et bouton d'action | Couvert |
| Dépendances entre plugins (ex. addon `prime-commerce-stripe` dépendant de `prime-commerce: "^1.0"`) | `plugin.json` -> `dependencies` + tri topologique `DependencyResolver` + blocage désactivation/suppression | Couvert |

**Conclusion d'architecture** : l'API `PluginContext` couvre 100 % des besoins identifiés pour Prime Builder et Prime Commerce sans aucun contournement du noyau.

---

## 3. Sécurité & Résilience vérifiées

- **Zero runtime dependency** : fonctionnement vérifié sans `vendor/` (`php bin/prime list`, `plugin:list`, `assets:build`).
- **Isolation totale des fautes** : exceptions dans `register()`, `boot()`, `activate()`, erreurs de syntaxe (`ParseError`), exceptions dans listeners/filtres de hooks et crashs fatals (`loading.json`) isolent uniquement le plugin fautif tout en préservant la disponibilité du site et de l'administration.
- **Durcissement ZIP** : couverture complète par tests unitaires dédiés contre Zip Slip (`../`), chemins absolus, backslashes, symlinks Unix, fichiers interdits (`.phar`, `.phtml`, `.htaccess`, `.user.ini`), racines multiples, manifestes falsifiés et ZIP bombs.
- **Design system & CSP strict** : un seul bouton corail `--brand-600` sur `/admin/plugins`, zéro script inline, tous les attributs personnalisés préfixés `data-prime-*`, 100 % Vanilla ES2022.

---

## 4. Corrections (revue 1)

Suite à la revue sur installation MariaDB réelle, les 5 corrections suivantes (C1 à C5) ont été appliquées et validées :

| ID | Correction | Détail de l'implémentation |
|----|------------|----------------------------|
| **C1** | **Normalisation des données filtrées d'administration & correction `hello-prime` (bloquant)** | Correction de la clé `title` dans le raccourci `admin.dashboard.shortcuts` de `hello-prime`. Introduction des objets valeur `Prime\Admin\Data\{DashboardShortcut,DashboardStat,CreateMenuItem,CommandPaletteItem,AdminAssets,FilterValueHelper}`, de `MenuItem::fromArray()` / `AdminMenu::sanitize()`, du durcissement de `Notice::fromArray()` et de `Hooks::applyValidated()`. Toute entrée invalide renvoyée par un filtre d'administration (`admin.dashboard.shortcuts`, `admin.dashboard.stats`, `admin.menu`, `admin.create.items`, `admin.command_palette`, `admin.assets`, `admin.notices`) est ignorée, journalisée en `warning` avec le slug du plugin propriétaire, et ne peut plus faire planter une page du noyau. Smoke test Docker complété (`GET /admin` = 200 avec « Hello Prime » après activation). |
| **C2** | **Suppression des chemins absolus du serveur dans l'UI** | `PluginManager::{formatPublicException,formatPublicFatalError,sanitizePublicMessage,relativePluginPath}` et `FatalLoadGuard` formatent désormais `last_error` avec la classe courte de l'exception, le message nettoyé tronqué à 300 caractères et le chemin strictement relatif au dossier du plugin (`src/Plugin.php:15`), ou sans chemin si le fichier est hors du plugin. Le chemin absolu complet et la stack trace sont consignés exclusivement dans le journal `plugin.{slug}`. |
| **C3** | **Notification de synthèse unique pour les plugins en erreur** | `PluginManager::syncErroredPluginsNotice()` remplace les bannières individuelles par une unique notification `plugins.errored.summary` (« Le plugin X a été désactivé suite à une erreur » pour 1 plugin, ou « N plugins ont été désactivés suite à une erreur » au-delà) avec lien « Voir les plugins en erreur » vers `/admin/plugins?status=errored`. Le détail et le bouton « Réessayer l'activation » restent sur chaque carte et le compteur de cloche reste exact. |
| **C4** | **Licence `proprietary` par défaut** | `content/plugins/hello-prime/plugin.json` passe à `"license": "proprietary"` ; `make:plugin` génère `"license": "proprietary"` par défaut et accepte `--license=...`. |
| **C5** | **Isolation complète de l'environnement de tests** | Ajout de `tests/bootstrap.php` (configuré dans `phpunit.xml.dist`), prise en charge de `PRIME_DISABLE_DOTENV` et `PRIME_STORAGE_PATH` dans `Application` et `config/logging.php`, `loadEnv: false` explicite dans les tests Phase 5. Les tests s'exécutent dans un stockage temporaire isolé (`installed.lock`, sessions, logs) sans jamais lire le `.env` local (vérifié avec et sans `.env` + `storage/installed.lock` présents). |

**Compteur de tests à jour** : **331 tests** au total (**318 tests unitaires et HTTP** + **13 tests d'intégration `#[Group('database')]`** sur MySQL 8.0 et MariaDB 10.6), PHPStan **niveau 8** (0 erreur, 0 `phpstan-ignore`), PSR-12 (`composer cs:check`) et smoke test Docker au vert.
