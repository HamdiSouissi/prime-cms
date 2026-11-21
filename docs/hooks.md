# Référence des hooks — Prime CMS

Ce document recense l'ensemble des hooks (événements et filtres) déclenchés par le noyau de **Prime CMS**. Il est enrichi à chaque phase.

## Conventions

- Tous les noms sont en **minuscules, notation pointée** : `domaine.action` (ex. `app.booted`).
- Priorité par défaut : `10` (la priorité la plus basse s'exécute en premier ; l'ordre d'insertion est conservé à priorité égale).
- Événements : `$hooks->on('domaine.action', $callback, priority: 10)`.
- Filtres : `$hooks->filter('domaine.action', $callback, priority: 10)` — le callback reçoit la valeur en premier argument et doit retourner la valeur (éventuellement modifiée).

---

## Hooks du noyau (Phase 1)

| Nom | Type | Arguments | Moment du déclenchement |
|---|---|---|---|
| `app.registered` | Événement | `Prime\Kernel\Application $app` | Immédiatement après l'appel de `register()` sur tous les service providers configurés dans `config/app.php`. |
| `app.booted` | Événement | `Prime\Kernel\Application $app` | Immédiatement après l'appel de `boot()` sur tous les service providers (l'application est prête). |
| `routes.register` | Événement | `Prime\Http\Router $router` | Lors du démarrage de `HttpServiceProvider::boot()`, juste après l'enregistrement des routes du noyau (`/` et `/health`). Permet d'enregistrer des routes supplémentaires. |
| `console.commands` | Événement | `Prime\Console\ConsoleApplication $console` | Lors de l'initialisation de la CLI (`bin/prime`), après l'enregistrement des commandes de base (`list`, `about`, `routes`). Permet d'ajouter des commandes via `$console->register(CommandInterface)`. |
| `http.response` | Filtre | `Prime\Http\Response $response`, `Prime\Http\Request $request` | À la fin de `Prime\Http\Kernel::handle()`, après exécution du pipeline de middlewares et du contrôleur (ou du gestionnaire d'erreurs). Doit retourner une instance de `Prime\Http\Response`. |
| `view.data` | Filtre | `array<string, mixed> $data`, `string $template` | Juste avant l'évaluation d'un template dans `Prime\View\View::render()` (y compris pour les layouts et partiels). Permet d'injecter ou de transformer les variables transmises à une vue. |

---

## Hooks du dashboard d'administration (Phase 2)

| Nom | Type | Arguments | Moment du déclenchement |
|---|---|---|---|
| `admin.menu` | Filtre | `Prime\Admin\Menu\AdminMenu $menu` | Lors de la construction du contexte d'administration (`AdminContext::build()`), sur une copie isolée du menu par défaut. Permet d'ajouter, réordonner ou retirer des entrées et sous-menus via `$menu->add()`, `$menu->addChild()`, `$menu->remove()`. |
| `admin.create.items` | Filtre | `list<array{id: string, label: string, description: string, icon: string, accent: string, url: string}> $items` | Lors de la génération du menu déroulant « Créer » de la barre supérieure. Permet aux plugins d'ajouter des raccourcis de création rapide. |
| `admin.command_palette` | Filtre | `list<array<string, mixed>> $entries` | Lors de la construction du payload JSON de la palette de commandes (`Ctrl/⌘ + K`). Permet d'ajouter des pages, recherches ou actions rapides. |
| `admin.assets` | Filtre | `array{css: list<string>, js: list<string>} $assets` | Lors de la préparation des feuilles de style et modules ES chargés dans le layout `admin::layouts/app`. |
| `admin.notices` | Filtre | `list<Prime\Admin\Notice> $notices`, `?Prime\Auth\User $user` | Lors de la construction du contexte d'administration, juste après la résolution des notifications persistantes visibles pour l'utilisateur courant. |
| `admin.head` | Filtre | `string $html` | Juste avant la fermeture de `<head>` dans `admin::layouts/app`. Permet d'injecter des balises `<meta>` ou `<link>` supplémentaires. |
| `admin.footer` | Filtre | `string $html` | Juste avant la fermeture de `</body>` dans `admin::layouts/app`. Permet d'ajouter des panneaux ou conteneurs HTML additionnels. |

---

## Hooks de base de données, réglages et installation (Phase 3)

| Nom | Type | Arguments | Moment du déclenchement |
|---|---|---|---|
| `db.query` | Événement | `string $sql`, `array<int\|string, mixed> $bindings`, `float $durationMs` | Après l'exécution de chaque requête SQL dans `Prime\Database\Connection` (select, insert, update, delete, statement). Permet le profilage ou la télémétrie. |
| `migrations.paths` | Filtre | `list<string> $paths` | Lors de la découverte des migrations par `Prime\Database\Migrations\Migrator`. Permet aux plugins d'ajouter leurs propres dossiers de migrations. |
| `settings.updated` | Événement | `string $name`, `mixed $value`, `bool $autoload` | Après l'enregistrement réussi d'un réglage via `Prime\Settings\Settings::set()`. |
| `install.completed` | Événement | `array<string, mixed> $payload` | Immédiatement après l'écriture de `storage/installed.lock` à la fin d'une installation (web ou CLI), avec les métadonnées sans secret (`site_name`, `site_url`, `site_locale`, `timezone`, `installed_at`, `version`, `migrations`). |

---

## Hooks d'authentification, sécurité et RBAC (Phase 4)

| Nom | Type | Arguments | Moment du déclenchement |
|---|---|---|---|
| `auth.capabilities` | Filtre | `array<string, array<string, string>> $groups` | Lors de la résolution du catalogue de permissions dans `Prime\Auth\Capabilities::grouped()`. Permet aux plugins d'ajouter de nouveaux groupes et clés de permissions dans la matrice `/admin/roles`. |
| `auth.login` | Événement | `Prime\Auth\User $user`, `string $ip` | Après une authentification complète réussie (y compris après validation du second facteur 2FA ou connexion automatique par cookie « Rester connecté »). |
| `auth.logout` | Événement | `Prime\Auth\User $user`, `string $ip` | Lors de la déconnexion d'un utilisateur via `Prime\Auth\Auth::logout()`. |
| `auth.failed` | Événement | `string $email`, `string $ip`, `string $reason` | Après un échec d'authentification (mot de passe incorrect, compte inconnu ou désactivé, code 2FA invalide). |
| `auth.lockout` | Événement | `string $email`, `string $ip`, `int $retryAfterSeconds` | Lorsqu'une tentative de connexion ou de défi 2FA dépasse le seuil du `RateLimiter` (HTTP 429). |
| `auth.password_reset` | Événement | `Prime\Auth\User $user` | Après une réinitialisation réussie du mot de passe via un jeton `/admin/reset-password/{token}`. |
| `auth.two_factor_enabled` | Événement | `Prime\Auth\User $user` | Immédiatement après confirmation et activation de la 2FA (TOTP) sur un compte. |
| `auth.two_factor_disabled` | Événement | `Prime\Auth\User $user`, `?int $actorUserId` | Après désactivation ou réinitialisation (profil, administrateur ou CLI `user:2fa:reset`) de la 2FA. |

---

## Exemples d'utilisation

### Ajouter une route via `routes.register`

```php
$hooks->on('routes.register', static function (Prime\Http\Router $router): void {
    $router->get('/ping', static fn (): Prime\Http\Response => Prime\Http\Response::text('pong'))
        ->name('ping');
});
```

### Enrichir une réponse HTTP via `http.response`

```php
$hooks->filter('http.response', static function (Prime\Http\Response $response, Prime\Http\Request $request): Prime\Http\Response {
    return $response->withHeader('X-Powered-By-Prime', '1');
});
```

### Injecter une variable globale dans les vues via `view.data`

```php
$hooks->filter('view.data', static function (array $data, string $template): array {
    $data['siteYear'] = (int) date('Y');

    return $data;
});
```

### Ajouter une entrée dans le menu d'administration via `admin.menu`

```php
use Prime\Admin\Menu\AdminMenu;
use Prime\Admin\Menu\MenuItem;

$hooks->filter('admin.menu', static function (AdminMenu $menu): AdminMenu {
    $menu->add(new MenuItem(
        id: 'commerce',
        label: 'Boutique',
        icon: 'shopping-bag',
        url: '/admin/commerce',
        position: 45,
        accent: 'amber',
        group: 'Contenu',
    ));

    return $menu;
});
```

### Ajouter un dossier de migrations depuis un plugin via `migrations.paths`

```php
$hooks->filter('migrations.paths', static function (array $paths): array {
    $paths[] = __DIR__ . '/migrations';

    return $paths;
});
```

### Écouter la fin de l'installation via `install.completed`

```php
$hooks->on('install.completed', static function (array $payload): void {
    // Exécuté une fois les migrations, le compte admin et storage/installed.lock en place.
});
```

### Déclarer des permissions personnalisées via `auth.capabilities`

```php
$hooks->filter('auth.capabilities', static function (array $groups): array {
    $groups['Boutique'] = [
        'shop.orders.view' => 'Consulter les commandes',
        'shop.orders.manage' => 'Gérer les commandes',
    ];

    return $groups;
});
```

### Journaliser les échecs de connexion via `auth.failed`

```php
$hooks->on('auth.failed', static function (string $email, string $ip, string $reason): void {
    // Alerter un outil SIEM ou enregistrer un audit métier personnalisé.
});
```

---

## Hooks du système de plugins (Phase 5)

### Événements du cycle de vie des plugins

| Hook | Type | Paramètres | Déclenchement |
|------|------|------------|---------------|
| `plugins.loaded` | Événement | `array<string, PluginContext> $contexts` | Une fois tous les plugins actifs enregistrés (`register()`) et démarrés (`boot()`) dans l'ordre topologique. |
| `plugin.installed` | Événement | `PluginManifest $manifest, bool $replaced, ?string $previousVersion` | Après l'installation ou le remplacement d'un plugin depuis une archive ZIP. |
| `plugin.activated` | Événement | `PluginManifest $manifest, PluginContext $context` | Après la réussite des 7 étapes d'activation d'un plugin. |
| `plugin.deactivated` | Événement | `?PluginManifest $manifest, string $slug` | Après la désactivation d'un plugin (données conservées). |
| `plugin.uninstalled` | Événement | `string $slug, bool $deleteFiles` | Après la désinstallation complète (migrations annulées, réglages/assets/notices/ligne DB nettoyés). |
| `plugin.updated` | Événement | `PluginManifest $manifest, string $previousVersion` | Lorsqu'un plugin actif passe à une version SemVer supérieure (après exécution des nouvelles migrations et republication des assets). |
| `plugin.failed` | Événement | `string $slug, string $phase, string $errorMessage, ?Throwable $exception` | Lorsqu'un plugin est isolé au statut `errored` (erreur dans `register`, `boot`, `activate`, un listener/filtre, dépendance circulaire ou crash fatal récupéré). |

### Filtres du tableau de bord enrichis en Phase 5

| Hook | Type | Valeur filtrée | Usage |
|------|------|----------------|-------|
| `admin.dashboard.shortcuts` | Filtre | `list<array{title: string, description: string, icon: string, accent: string, url: string}>` | Ajouter ou réorganiser les raccourcis affichés sur `/admin` (reçoit `?User` en second argument). |
| `admin.dashboard.stats` | Filtre | `list<array{label: string, value: string, icon: string, accent: string, trend: string, trendDirection: 'up'\|'down'\|'flat', hint: string, url: string}>` | Ajouter ou modifier les tuiles statistiques du tableau de bord `/admin` (reçoit `?User` en second argument). |

### Format exact et normalisation des filtres d'administration

Chaque donnée structurée exposée à une vue du noyau après un filtre est validée et normalisée étape par étape via `Hooks::applyValidated()` et un objet valeur dédié. Une entrée invalide (clé manquante, mauvais type, URL dangereuse comme `javascript:`, accent inconnu ou valeur non tableau) est **ignorée**, journalisée au niveau `warning` avec le slug du plugin propriétaire lorsqu'il est connu, et **n'empêche jamais le rendu de la page**.

| Filtre | Objet valeur | Clés requises | Clés optionnelles et valeurs autorisées |
|---|---|---|---|
| `admin.dashboard.shortcuts` | `Prime\Admin\Data\DashboardShortcut::fromArray()` | `title` (`string` non vide), `url` (`string` URL relative `/` ou `http(s)://`) | `description` (`string`, défaut `''`), `icon` (`string` `^[a-z0-9-]+$`, défaut `'sparkles'`), `accent` (`brand\|violet\|teal\|sky\|amber\|success\|neutral`, défaut `'brand'`) |
| `admin.dashboard.stats` | `Prime\Admin\Data\DashboardStat::fromArray()` | `label` (`string` non vide), `value` (`string\|int\|float` non vide) | `icon` (`string`, défaut `'activity'`), `accent` (`brand\|violet\|teal\|sky\|amber\|success\|neutral`, défaut `'brand'`), `trend` (`string`, défaut `''`), `trendDirection` (`'up'\|'down'\|'flat'`, défaut `'flat'`), `hint` (`string`, défaut `''`), `url` (`string` vide ou URL sûre, défaut `''`) |
| `admin.menu` | `Prime\Admin\Menu\AdminMenu` & `MenuItem::fromArray()` | Instance de `AdminMenu` ; chaque `MenuItem` requiert `id`, `label`, `icon` (`^[a-z0-9-]+$`), `url` (URL sûre) | `position` (`int`, défaut `50`), `accent` (`MenuItem::ALLOWED_ACCENTS`, défaut `'neutral'`), `badge` (`?string`), `group` (`?string`), `capability` (`?string`), `route` (`?string`). Les entrées au format d'URL ou d'icône invalide sont retirées par `AdminMenu::sanitize()`. |
| `admin.create.items` | `Prime\Admin\Data\CreateMenuItem::fromArray()` | `id` (`string`), `label` (`string`), `url` (URL sûre) | `description` (`string`, défaut `''`), `icon` (`string`, défaut `'plus'`), `accent` (`MenuItem::ALLOWED_ACCENTS`, défaut `'brand'`), `capability` (`?string`, défaut `null`) |
| `admin.command_palette` | `Prime\Admin\Data\CommandPaletteItem::fromArray()` | `id` (`string`), `label` (`string`), `url` (URL sûre) | `subtitle` (`string`, défaut `''`), `group` (`string`, défaut `'Raccourcis'`), `section` (`string`, défaut `'Navigation'`), `icon` (`string`, défaut `'arrow-right'`), `accent` (`MenuItem::ALLOWED_ACCENTS`, défaut `'brand'`), `keywords` (`list<string>`, défaut `[]`) |
| `admin.assets` | `Prime\Admin\Data\AdminAssets::fromArray()` | Tableau associatif `['css' => list<string>, 'js' => list<string>]` | Chaque URL doit être une chaîne non vide sûre (`/` ou `http(s)://`) ; toute entrée non conforme est ignorée sans supprimer les feuilles/scripts valides. |
| `admin.notices` | `Prime\Admin\Notice::fromArray()` | Instance `Notice` ou tableau avec `id` (`^[a-zA-Z0-9._-]{1,120}$`), `type` (`info\|success\|warning\|danger`), `title` (`string` non vide), `message` (`string` non vide) | `action_label` (`?string`), `action_url` (`?string` URL sûre), `action_method` (`'GET'\|'POST'`, défaut `'GET'`), `capability` (`?string`), `dismissible` (`bool`, défaut `true`), `source` (`?string`), `created_at` (`?string`) |

### Attribution et isolation des erreurs de plugins (`Hooks::withOwner`)

Tout écouteur ou filtre enregistré via `$context->hooks()` (ou pendant l'exécution de `register()`, `boot()`, `activate()`, `deactivate()`, `uninstall()`) est automatiquement attribué au slug du plugin propriétaire.

- Si un callback appartenant à un **plugin** lève une `Throwable` (exception ou `Error`), `Hooks` intercepte l'erreur, détache les hooks du plugin fautif pour la suite de la requête, délègue à `PluginManager` (statut `errored`, log dans `plugin.{slug}`, notice persistante, événement `plugin.failed`) et **laisse les autres écouteurs et filtres poursuivre normalement** (pour un filtre, la valeur précédente reste intacte).
- Les écouteurs du **noyau** (sans propriétaire plugin) conservent leur comportement standard : leurs exceptions continuent de se propager normalement.

---

## Hooks de contenus et Prime Editor (Phase 6)

### Filtres de registres et de rendu

| Hook | Type | Valeur filtrée / Arguments | Usage |
|------|------|----------------------------|-------|
| `content.types` | Filtre | `array<string, Prime\Content\ContentType> $types` | Ajouter, enrichir ou modifier les types de contenus enregistrés (ex. ajouter une taxonomie à `post`). |
| `content.taxonomies` | Filtre | `array<string, Prime\Content\Taxonomy> $taxonomies` | Modifier ou filtrer les taxonomies disponibles. |
| `content.field_groups` | Filtre | `list<Prime\Content\Fields\FieldGroup> $groups`, `string $contentType` | Modifier les groupes de champs personnalisés affichés et validés pour un type de contenu. |
| `editor.blocks` | Filtre | `array<string, Prime\Editor\BlockDefinition> $blocks` | Ajouter ou restreindre les définitions de blocs Prime Editor. |
| `content.saving` | Filtre | `array<string, mixed> $payload`, `?Prime\Content\Entry $existing`, `Prime\Content\ContentType $type`, `Prime\Auth\User $actor` | Inspecter ou enrichir les données d'un contenu juste avant sa persistance en base. |
| `content.render` | Filtre | `string $html`, `?Prime\Content\Entry $entry`, `array<string, mixed> $document` | Transformer le HTML généré par `Prime\Editor\Renderer::renderHtml()`. |

### Événements du cycle de vie des contenus

| Hook | Type | Paramètres | Déclenchement |
|------|------|------------|---------------|
| `content.created` | Événement | `Prime\Content\Entry $entry`, `Prime\Auth\User $actor` | Après création réussie d'un contenu (brouillon, publié, planifié…). |
| `content.updated` | Événement | `Prime\Content\Entry $entry`, `Prime\Content\Entry $previous`, `Prime\Auth\User $actor` | Après mise à jour réussie d'un contenu. |
| `content.published` | Événement | `Prime\Content\Entry $entry`, `?Prime\Auth\User $actor` | Lorsqu'un contenu passe au statut `published` (création publiée, mise à jour vers `published` ou publication planifiée automatique où `$actor` vaut `null`). |
| `content.trashed` | Événement | `Prime\Content\Entry $entry`, `Prime\Auth\User $actor` | Après mise à la corbeille logique d'un contenu. |
| `content.restored` | Événement | `Prime\Content\Entry $entry`, `Prime\Auth\User $actor` | Après restauration d'un contenu depuis la corbeille ou d'une révision. |
| `content.deleted` | Événement | `Prime\Content\Entry $entry`, `?Prime\Auth\User $actor` | Après suppression définitive d'un contenu (manuelle ou purge automatique de la corbeille). |

---

## Hooks de la Médiathèque (Phase 7)

### Filtres de configuration média

| Hook | Type | Valeur filtrée | Usage |
|------|------|----------------|-------|
| `media.allowed_types` | Filtre | `array<string, array{kind: string, mimes: list<string>}> $types` | Autoriser ou restreindre des extensions et types MIME acceptés au téléversement. |
| `media.image_sizes` | Filtre | `array<string, array{width: int, height: int, crop: bool}> $sizes` | Ajouter ou ajuster les formats de variantes GD générés automatiquement (ex. `card` 600×400). |

### Événements du cycle de vie des médias

| Hook | Type | Paramètres | Déclenchement |
|------|------|------------|---------------|
| `media.before_upload` | Événement | `array<string, mixed> $validated`, `?Prime\Auth\User $actor`, `?int $folderId` | Juste après validation de sécurité et avant l'écriture sur disque (permet de rejeter un upload via exception). |
| `media.uploaded` | Événement | `Prime\Media\Media $media`, `?Prime\Auth\User $actor`, `?Prime\Media\Media $duplicate` | Après traitement GD, génération WebP et persistance en base (indique un éventuel doublon SHA-256). |
| `media.updated` | Événement | `Prime\Media\Media $media`, `?Prime\Auth\User $actor` | Après mise à jour des métadonnées, changement de point focal, remplacement de fichier ou régénération des variantes. |
| `media.deleted` | Événement | `Prime\Media\Media $media`, `?Prime\Auth\User $actor` | Après suppression en base et suppression des fichiers originaux et variantes sur disque. |

---

## Hooks de Thèmes et Rendu du site (Phase 8)

### Événements du moteur de thèmes

| Hook | Type | Paramètres | Déclenchement |
|------|------|------------|---------------|
| `theme.booted` | Événement | `string $slug`, `Prime\Themes\ThemeManifest $manifest`, `?Prime\Themes\ThemeManifest $parent` | Après le démarrage réussi du thème actif (et de son parent éventuel). |
| `theme.activated` | Événement | `string $newSlug`, `?string $previousSlug`, `Prime\Themes\ThemeManifest $manifest` | Après activation et publication des assets d'un thème. |
| `theme.installed` | Événement | `Prime\Themes\ThemeManifest $manifest`, `bool $replaced`, `?string $previousVersion` | Après installation ou mise à jour d'un thème depuis une archive ZIP. |
| `theme.deleted` | Événement | `string $slug` | Après suppression d'un thème inactif et nettoyage de ses options/assets. |

### Filtres de rendu, templates, menus, blocs dynamiques et SEO

| Hook | Type | Valeur filtrée / Arguments | Usage |
|------|------|----------------------------|-------|
| `site.reserved_slugs` | Filtre | `list<string> $slugs` | Ajouter des segments d'URL racine réservés protégés par `ReservedSlugValidator`. |
| `site.plugin_template_paths` | Filtre | `list<string> $directories` | Déclarer des répertoires de templates fournis par des plugins (repli après le thème actif et son parent, ex. `hello-prime`). |
| `site.template_candidates` | Filtre | `list<string> $candidates`, `Prime\Site\PageContext $context` | Modifier la liste ordonnée des templates recherchés pour le contexte courant. |
| `site.template` | Filtre | `?string $resolvedPath`, `Prime\Site\PageContext $context` | Remplacer le fichier template résolu (vérifié contre toute traversée de répertoire). |
| `site.head` | Filtre | `string $html`, `Prime\Site\PageContext $context` | Injecter des balises dans `<head>` via `$site->head()`. |
| `site.footer` | Filtre | `string $html`, `Prime\Site\PageContext $context` | Injecter du HTML avant `</body>` via `$site->footer()`. |
| `site.partial` | Filtre | `?string $html` (null), `string $name`, `Prime\Site\PageContext $context`, `array $data` | Remplacer un partial du thème (`header`, `footer`…) appelé par `$site->partial()` : renvoyer une chaîne pour le remplacer, toute autre valeur garde le partial du thème. Depuis 1.0.1. |
| `site.menu_items` | Filtre | `list<array<string, mixed>> $items`, `string $location`, `array $menu`, `array $options` | Filtrer ou enrichir l'arbre d'éléments d'un menu avant rendu HTML. |
| `site.dynamic_blocks` | Filtre | `array<string, array<string, mixed>> $blocks` | Enregistrer des blocs dynamiques utilisables dans les zones et contenus (ex. `hello_testimonial`). |
| `site.seo_title` | Filtre | `string $title`, `Prime\Site\PageContext $context` | Personnaliser la balise `<title>` calculée. |
| `site.seo_description` | Filtre | `string $description`, `Prime\Site\PageContext $context` | Personnaliser la meta description calculée. |
| `site.seo_canonical` | Filtre | `string $url`, `Prime\Site\PageContext $context` | Modifier l'URL canonique calculée. |
| `site.seo_robots` | Filtre | `string $directives`, `Prime\Site\PageContext $context` | Ajuster la directive `robots` (`index, follow` / `noindex, follow`). |
| `site.seo_og` | Filtre | `array<string, string> $tags`, `Prime\Site\PageContext $context` | Enrichir les métadonnées Open Graph / Twitter Cards. |
| `site.seo_schema` | Filtre | `list<array<string, mixed>> $schemas`, `Prime\Site\PageContext $context` | Modifier ou ajouter des objets JSON-LD (`WebSite`, `Article`, `BreadcrumbList`…). |
| `site.sitemap_entries` | Filtre | `list<array{loc: string, lastmod?: string}> $urls`, `string $type` | Filtrer les URL incluses dans un sous-sitemap XML. |
| `site.robots_txt` | Filtre | `string $content` | Personnaliser le contenu de `/robots.txt`. |
| `site.csp` | Filtre | `array<string, string>|string $directives` | Ajuster les directives de la Content-Security-Policy publique (`PublicCsp`). |






---

## Hooks de réglages, i18n, cache, e-mails et tâches de fond (Phase 9)

| Hook | Type | Arguments | Déclenchement |
|------|------|-----------|---------------|
| `settings.register` | Filtre | `SettingsRegistry $registry` | Au boot (`app.booted`), avant le verrouillage du registre : déclarer sections et champs. Voir [`settings.md`](settings.md). |
| `settings.validate` | Filtre | `mixed $value`, `SettingsField $field`, `array $input` | Après validation d'une valeur soumise, avant écriture. |
| `settings.before_save` | Filtre | `bool $allow`, `string $name`, `mixed $current`, `mixed $value`, `SettingsField $field` | Retourner `false` refuse l'écriture du réglage. |
| `settings.saved` | Événement | `string $section`, `list<string> $saved`, `array $errors` | Après l'enregistrement d'une section par `SettingsFormProcessor`. |
| `translations.register` | Filtre | `Translator $translator` | Ajouter des catalogues à l'exécution (`$translator->register($locale, $messages)`). |
| `translations.missing` | Événement | `string $key`, `string $locale` | Clé introuvable dans la langue active et la langue de secours. |
| `locale.resolved` / `locale.changed` | Événement | `string $locale` | Langue retenue pour la requête / changée par l'utilisateur. |
| `cache.page.cacheable` | Filtre | `bool $cacheable`, `Request $request`, `?Response $response` | Exclure une page du cache de pages. |
| `cache.purged` | Événement | `list<string> $paths` | Après une purge du cache de pages. |
| `mail.message` | Filtre | `Message $message`, `?string $template` | Réécrire tout message avant envoi (file ou direct). |
| `mail.sent` / `mail.failed` | Événement | `Message $message`, … | Après un envoi réussi / échoué. |
| `mail.log.recorded` / `mail.log.purged` | Événement | `int $id`, … | Écriture / purge du journal `pc_mail_log`. |
| `scheduler.register` | Filtre | `Schedule $schedule` | Au boot : ajouter des tâches (`call()`, `command()`). |
| `scheduler.ran` / `scheduler.task.ran` | Événement | résultats / `string $name`, `string $tick` | Après un passage du planificateur / l'exécution d'une tâche. |
| `jobs.register_handlers` | Filtre | `JobHandlerRegistry $registry` | Au boot : déclarer les gestionnaires de tâches en file. |
| `job.pushed` / `job.started` / `job.completed` / `job.released` / `job.failed` | Événement | `Job $job`, … | Cycle de vie d'une tâche de la file. |
| `system.checks` | Filtre | `list<array> $checks`, `SystemStatus $status` | Ajouter des contrôles à **Outils › État du système** (`id`, `group`, `label`, `status`, `value`, `help`). |
