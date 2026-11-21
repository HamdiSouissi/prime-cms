# Système de plugins Prime CMS

Prime CMS intègre un système de plugins modulaire, isolé et 100 % natif PHP 8.2+ conçu pour héberger aussi bien des extensions légères que des suites complètes telles que **Prime Builder** (Phases 11–12) et **Prime Commerce** (Phases 13–14) — exclusivement via l'API officielle `Prime\Plugins\PluginContext`, sans aucun contournement du noyau.

---

## 1. Structure d'un plugin

Chaque plugin vit dans son propre dossier `content/plugins/{slug}/` :

```text
content/plugins/mon-plugin/
├── plugin.json          # Manifeste obligatoire (validé strictement)
├── src/
│   └── Plugin.php       # Classe principale implémentant Prime\Plugins\PluginInterface
├── migrations/          # Optionnel : migrations horodatées (YYYY_MM_DD_HHMMSS_nom.php)
├── views/               # Optionnel : vues PHP (espace de noms `mon-plugin::...`)
├── assets/              # Optionnel : fichiers statiques publiés dans public/assets/plugins/mon-plugin/
└── README.md            # Optionnel : documentation du plugin
```

Partez d'une copie de `prime-starter`, renommée partout (slug, namespace, capacité, table, clés de traduction) et livrée avec ses tests :

```bash
php bin/prime make:plugin mon-plugin --name="Mon Plugin" --author="Mon Équipe" [--license="proprietary"]
php bin/prime make:plugin mon-plugin --minimal   # squelette vide (plugin.json + src/Plugin.php)
```

Voir [`plugin-starter.md`](plugin-starter.md).

---

## 2. Manifeste `plugin.json`

```json
{
  "slug": "hello-prime",
  "name": "Hello Prime",
  "description": "Plugin de référence Prime CMS.",
  "version": "1.0.0",
  "author": {
    "name": "Prime CMS",
    "url": "https://github.com/HamdiSouissi/prime-cms"
  },
  "license": "proprietary",
  "namespace": "HelloPrime\\",
  "main": "HelloPrime\\Plugin",
  "requires": {
    "prime": "^0.5",
    "php": "^8.2",
    "extensions": ["mbstring"]
  },
  "dependencies": {
    "autre-plugin": "^1.0"
  },
  "icon": "assets/icon.svg",
  "accent": "violet",
  "settings_url": "/admin/hello-prime"
}
```

### Règles de validation (`ManifestValidator`)

- **Champs requis** : `slug`, `name`, `description`, `version`, `author.name`, `namespace`, `main`.
- **`slug`** : `^[a-z0-9]+(-[a-z0-9]+)*$`, longueur 3 à 64 caractères, **doit correspondre exactement au nom du dossier**.
- **`version`** : format SemVer 2.0 strict validé par `Prime\Support\Version`.
- **`namespace`** : préfixe PSR-4 terminé par `\`, associé automatiquement au dossier `src/` (le préfixe `Prime\` est réservé au noyau).
- **`main`** : nom complet de classe appartenant à `namespace` et implémentant `Prime\Plugins\PluginInterface` (ou héritant de `Prime\Plugins\AbstractPlugin`).
- **Champs inconnus** : génèrent un **avertissement non bloquant** (compatibilité ascendante).

---

## 3. Cycle de vie et `PluginInterface`

Tout plugin implémente `Prime\Plugins\PluginInterface` (ou étend `Prime\Plugins\AbstractPlugin` qui fournit des méthodes vides par défaut) :

- `register(PluginContext $context): void` — Déclare services, hooks, routes, vues, menus, capacités et réglages.
- `boot(PluginContext $context): void` — Exécuté une fois tous les plugins actifs passés par `register()` (ordre topologique des dépendances).
- `activate(PluginContext $context): void` — Exécuté une fois lors de l'activation (après migrations et publication des assets).
- `deactivate(PluginContext $context): void` — Exécuté lors de la désactivation ; **conserve toujours les données utilisateur**.
- `uninstall(PluginContext $context): void` — Exécuté avant le rollback intégral des migrations, la suppression des réglages `plugin.{slug}.*`, des notices, des assets publiés, de l'entrée `pc_plugins` et des fichiers.

### Activation atomique en 7 étapes (`PluginManager::activate`)

1. Validation du manifeste `plugin.json`.
2. Vérification des prérequis système (`requires.prime`, `requires.php`, `requires.extensions`).
3. Vérification des dépendances entre plugins (installées, **actives**, compatibles SemVer, sans cycle).
4. Exécution des migrations SQL en attente (`source = {slug}` dans `pc_migrations`).
5. Publication des assets statiques autorisés (`AssetPublisher`).
6. Exécution de `register()`, `boot()`, `activate()` dans le garde d'isolation : en cas de `Throwable`, **rollback automatique du lot de migrations fraîchement appliqué**, nettoyage des assets et marquage `errored`.
7. Passage en statut `active` dans `pc_plugins` et émission de l'événement `plugin.activated`.

---

## 4. API officielle `Prime\Plugins\PluginContext`

| Méthode | Description |
|---------|-------------|
| `$context->manifest()` | Objet `PluginManifest` immuable. |
| `$context->slug()` / `$context->version()` | Raccourcis vers le slug et la version SemVer. |
| `$context->path(string $relative = '')` | Chemin absolu dans `content/plugins/{slug}/`. |
| `$context->url(string $asset)` | URL publique cache-busted (`?v={version}-{mtime}`) d'un asset publié. |
| `$context->app()` | Instance `Prime\Kernel\Application` (container, config). |
| `$context->hooks()` | Façade `PluginHooks` attribuant automatiquement tous les listeners/filtres au plugin. |
| `$context->router()` | Façade `PluginRouter` (`adminGroup()`, `frontGroup()`, `get/post/put/patch/delete`). |
| `$context->views()` | Moteur `View` (espace de noms `{slug}::` enregistré automatiquement si `views/` existe). |
| `$context->adminMenu()` | Accès à `AdminMenu` (recommandé : filtrer `admin.menu`). |
| `$context->capabilities()` | Enregistrement de capacités regroupées automatiquement sous le nom du plugin dans `/admin/roles`. |
| `$context->settings()` | Stockage clé/valeur typé JSON préfixé automatiquement par `plugin.{slug}.`. |
| `$context->db()` | Instance `Prime\Database\Connection` (`QueryBuilder` + requêtes préparées). |
| `$context->logger()` | Canal dédié `plugin.{slug}` (`storage/logs/plugin.{slug}-YYYY-MM-DD.log`). |
| `$context->notices()` | Notifications persistantes du tableau de bord (`info`, `success`, `warning`, `danger`), nettoyées automatiquement à la désinstallation. |
| `$context->contentTypes()` | Façade `PluginContentTypes` : enregistre des types de contenus (CPT) attribués automatiquement à `plugin:{slug}`. |
| `$context->taxonomies()` | Façade `PluginTaxonomies` : enregistre des taxonomies attribuées automatiquement à `plugin:{slug}`. |
| `$context->fields()` | Façade `PluginFields` : enregistre des groupes de champs personnalisés (`main`/`sidebar`) attribués à `plugin:{slug}`. |
| `$context->blocks()` | Façade `PluginBlocks` : enregistre des blocs serveur Prime Editor et publie leurs scripts ES client via `enqueueScript()`. |
| `$context->editors()` | Façade `PluginEditors` : éditeur de contenu alternatif (nom `{slug}` ou `{slug}-…`), choisi par un type de contenu (`editor:`) ou le filtre `content.editor`. |
| `$context->api()` | Routes REST sous `/api/{slug}/v1` (voir plus bas). |
| `$context->renderPage(Request, $title, $html, $description = '', $status = 200)` | Page publique encadrée par le thème actif (en-tête, menus, pied, SEO). |
| `$context->setSettingsUrl(string $url)` | Définit le lien du bouton **Réglages** sur `/admin/plugins`. |

---

## 5. SemVer 2.0 (`Prime\Support\Version`)

La classe `Prime\Support\Version` gère le parsing, la comparaison et les contraintes SemVer 2.0 :

- Opérateurs : `=`, `>`, `>=`, `<`, `<=`, `!=`, `^` (caret), `~` (tilde), jokers `*`, `1.*`, `1.2.x`.
- Combinaisons : ET logique (espace ou virgule), OU logique (`||`), gestion complète des pré-versions (`1.0.0-alpha.1 < 1.0.0-beta < 1.0.0`).

---

## 6. Isolation des erreurs & Mode sans échec

- Chaque phase (`register`, `boot`, `activate`, écouteurs/filtres de hooks) est encapsulée dans un garde `try/catch (Throwable)` couvrant y compris `ParseError` et `TypeError`.
- Un fichier témoin `storage/plugins/loading.json` (`FatalLoadGuard`) détecte les erreurs fatales PHP (`E_ERROR`, `E_COMPILE_ERROR`, OOM) au shutdown ou au démarrage suivant.
- Tout plugin défaillant passe en statut `errored` dans `pc_plugins`, cesse d'être chargé aux requêtes suivantes, journalise le détail complet (chemin absolu et trace) uniquement dans `plugin.{slug}`, enregistre dans `last_error` un résumé sûr sans chemin serveur (classe courte d'exception, message tronqué à 300 caractères, chemin relatif au plugin uniquement), déclenche `plugin.failed` et alimente une unique notification de synthèse pointant vers `/admin/plugins?status=errored`. Le détail et le bouton **« Réessayer l'activation »** figurent sur la carte du plugin.
- En cas d'urgence, définir `PRIME_SAFE_MODE=true` dans `.env` désactive le chargement de **tous** les plugins et affiche un bandeau d'avertissement dans l'administration.

---

## 7. Publication d'assets & Sécurité ZIP

### `AssetPublisher`
- Seules les extensions statiques de la liste blanche (`css`, `js`, `mjs`, `json`, `map`, `svg`, `png`, `jpg`, `jpeg`, `gif`, `webp`, `avif`, `ico`, `woff`, `woff2`, `ttf`, `otf`, `txt`) sont copiées dans `public/assets/plugins/{slug}/`.
- Les scripts PHP, fichiers cachés (`.htaccess`, `.env`) et liens symboliques sont systématiquement ignorés.

### `PluginInstaller` (archives `.zip`)
Avant toute extraction et **sans jamais exécuter le code PHP du plugin** :
- Vérification de la taille maximale (`PLUGINS_MAX_UPLOAD_MB`, 20 Mo par défaut) et de `PLUGINS_ALLOW_UPLOAD`.
- Contrôle de signature `PK\x03\x04`, MIME `application/zip` et intégrité `ZipArchive::CHECKCONS`.
- Rejet systématique des chemins absolus, `..` (Zip Slip), octets NUL, antislashes `\`, liens symboliques Unix, et fichiers interdits (`.phar`, `.phtml`, `.htaccess`, `.user.ini`).
- Protection anti-ZIP bomb : max 2 000 entrées, max 100 Mo décompressés, ratio de compression contrôlé.
- Vérification d'un unique dossier racine correspondant au `slug` de `plugin.json` et validation complète du manifeste.
- Extraction flux par flux dans `storage/tmp/` puis échange atomique avec sauvegarde de rollback. Les nouveaux plugins sont toujours installés à l'état **inactif**.

---

## 8. Commandes CLI

```bash
php bin/prime plugin:list [--status=active|inactive|errored] [--json]
php bin/prime plugin:activate <slug>
php bin/prime plugin:deactivate <slug>
php bin/prime plugin:uninstall <slug> [--keep-files]
php bin/prime plugin:install <archive.zip> [--replace] [--force]
php bin/prime plugin:publish-assets [slug]
php bin/prime make:plugin <slug> [--name="..."] [--author="..."] [--namespace="..."] [--license="proprietary"] [--minimal]
```

---

## 9. Plugin de référence `hello-prime`

Le dépôt inclut `content/plugins/hello-prime/` (`v1.1.0`, installé inactif par défaut) qui exerce l'intégralité de l'API Phases 5 & 6 :
- menu violet, capacité `hello_prime.manage`, réglages préfixés, migration SQL (`pc_hello_prime_greetings`), route publique `/hello-prime`, feuille de style publiée via `admin.assets`, raccourci tableau de bord et notification d'accueil persistante ;
- type de contenu personnalisé `testimonial` (« Témoignages »), taxonomie `testimonial_source` (« Sources »), groupe de champs latéral `hello-prime.testimonial_details` (`author_role`, `rating`, `featured`) et bloc Prime Editor personnalisé `hello-prime/highlight` (PHP + `assets/js/highlight-block.js`) ;
- désinstallation sans trace (`EntryRepository::deleteByType('testimonial')` et `TermRepository::deleteByTaxonomy('testimonial_source')`).

Pour l'API complète de gestion des contenus et de l'éditeur de blocs, consultez [`docs/content.md`](content.md) et [`docs/editor.md`](editor.md).

---

## Phase 9 — réglages, traductions et tâches de fond

Un plugin s'intègre aux nouveaux services sans code d'interface :

- **Traductions** : déposer `lang/{fr,en}/messages.php` ; les clés s'utilisent sous la forme
  `plugin::<slug>.x.y` partout où un libellé est attendu (menu, type de contenu, champ,
  bloc, réglage, description du manifeste). Voir [`i18n.md`](i18n.md).
- **Réglages** : filtre `settings.register` — une section `'page' => 'general'|'security'|'emails'`
  s'affiche sur l'écran correspondant. Une section peut exiger une capacité en plus de
  `settings.manage` (`'capability' => 'mon-plugin.settings'`) : sans elle, la section est masquée
  sur l'écran, ses champs non publics sont absents de `GET /api/v1/settings` et toute écriture
  (écran ou `PATCH /api/v1/settings`) est refusée (depuis 1.0.2). Voir [`settings.md`](settings.md).
- **Tâches planifiées et file** : filtres `scheduler.register` et `jobs.register_handlers`.
  Voir [`scheduler.md`](scheduler.md).
- **Diagnostic** : filtre `system.checks` pour ajouter des contrôles à **Outils › État du système**.

Le plugin de référence `hello-prime` illustre la traduction de tous ses libellés (`content/plugins/hello-prime/lang/`).

## API REST des plugins

`PluginContext::api()` déclare des routes sous `/api/{slug}/v1`. Elles bénéficient de toute la mécanique du noyau :
- les mêmes middlewares : activation, authentification par jeton ou par session, limite de débit, contrôle du corps, capacité ;
- le même format d'erreur ;
- une entrée dans `GET /api/v1/openapi.json`.

```php
public function register(PluginContext $context): void
{
    // Schéma réutilisable dans la description OpenAPI (préfixez le nom par celui du plugin).
    $context->api()->schema('HelloPrimeGreeting', [
        'type' => 'object',
        'properties' => ['id' => ['type' => 'integer'], 'message' => ['type' => 'string']],
    ]);

    // Lecture publique : `public: true` doit être explicite.
    $context->api()->get('/greetings', [GreetingsApi::class, 'index'], public: true, options: [
        'summary' => 'Latest greetings',
        'response' => ['$ref' => '#/components/schemas/HelloPrimeGreeting'],
    ]);

    // Écriture protégée par une capacité déclarée par le plugin.
    $context->api()->post('/greetings', [GreetingsApi::class, 'store'], permission: 'hello_prime.manage', options: [
        'errors' => [401, 403, 422],
    ]);
}
```

**Règles :**
- **Accès toujours explicite.** Chaque route nomme une capacité (`permission:`), ou `ApiRoute::AUTHENTICATED` pour « tout appelant authentifié », ou se déclare `public: true`. Sans l'un des deux, une `InvalidArgumentException` est levée dès l'enregistrement.
- **Pas de doublon.** Deux routes qui répondent à la même méthode et à la même forme d'URL lèvent une `LogicException` qui nomme les deux déclarants.
- **Données de la requête.**
  - Le gestionnaire reçoit la `Request`, les paramètres de chemin par leur nom et les services du conteneur.
  - Le corps JSON validé se lit avec `$request->attribute('api.body')`.
  - L'utilisateur se lit avec `$request->attribute('api.user')` ; ses capacités sont déjà réduites à celles du jeton.
- **Erreurs.**
  - Une exception levée par le gestionnaire devient une erreur 500 `server_error`, sans aucun détail. Elle est journalisée avec le slug du plugin, qui reste actif.
  - Pour renvoyer une erreur au format commun, utilisez `Prime\Api\ApiError::response('validation_failed', 422, $request, ['fields' => [...]])`.
- **Capacités.** Celles déclarées par `capabilities()->register()` sont proposées à la création d'un jeton, comme celles du noyau.
- **Ressources du noyau.** Le filtre `api.resource.{entry|term|user}` permet d'ajouter des membres à une ressource. Un résultat invalide (exception, autre chose qu'un tableau, `id` modifié) est ignoré.

Le plugin `hello-prime` sert de référence : voir `content/plugins/hello-prime/src/Api/GreetingsApi.php`.

---

## Points d'extension (récapitulatif v1)

Tout ce qu'un plugin peut faire passe par une de ces portes ; aucune ne demande de toucher au noyau.
`prime-starter` les utilise toutes, une section numérotée par point dans `src/Plugin.php`.

| Besoin | Point d'extension | Règle |
|--------|-------------------|-------|
| Droits | `capabilities()->register('mon_plugin.x', …)` | préfixe `{slug en snake_case}.` ; retiré de tous les rôles et jetons à la désinstallation |
| Réglages | filtre `settings.register` | noms `plugin.{slug}.…` ; supprimés à la désinstallation |
| Écrans d'admin | `router()->adminGroup(…, capability:)` + filtre `admin.menu` | URL `/admin/{slug}/…`, CSRF et session du noyau |
| Page publique | `router()->frontGroup()` + `renderPage()` | refus au démarrage d'un préfixe du noyau (`/admin`, `/api`, `/uploads`…) ou d'une route déjà prise |
| Styles et scripts publics | `site.assets` (`ThemeAssetRegistry::style()/script()`) | fichiers de `assets/`, jamais de code en ligne (CSP) |
| API REST | `api()->get/post/patch/delete` + `api()->schema()` | `/api/{slug}/v1`, documentée dans l'OpenAPI |
| Bloc Prime Editor | `blocks()->register()` + `enqueueScript()` | validation et rendu côté serveur, HTML filtré |
| Bloc de zone | filtre `blocks.dynamic` | rendu serveur, échappement à la charge du plugin |
| Éditeur alternatif | `editors()->register()` + filtre `content.editor` | doit écrire `content_json` ; en cas d'absence ou d'erreur, retour à Prime Editor avec un avertissement |
| Tâche planifiée | filtre `scheduler.register` | nom `{slug}.…`, `owner: '{slug}'` |
| Travail en file | filtre `jobs.register_handlers` | nom `{slug}.…` ; travaux en attente supprimés à la désinstallation |
| E-mail | `Mailer::sendTemplate('{slug}::mail/…')` | gabarit dans `views/mail/`, mise en page `mail::layout` |
| Diagnostic | filtre `system.checks` | pas de secret dans la valeur |
| Traductions | `lang/{fr,en}/messages.php` | clés `plugin::{slug}.…`, chargées même pour un plugin installé après le démarrage |

### Désinstallation

`plugin:uninstall` (ou le bouton de l'admin) annule les migrations, supprime les réglages
`plugin.{slug}.*`, les notifications, la capacité sur chaque rôle et chaque jeton d'API, les travaux
en file du plugin (gestionnaire `{slug}.…` ou gabarit `{slug}::…`) et ses horodatages du
planificateur, puis le dossier — sauf avec `--keep-files`.
