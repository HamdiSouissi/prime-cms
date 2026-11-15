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
| `admin.dashboard.shortcuts` | Filtre | `list<array{label: string, description: string, icon: string, accent: string, url: string}>` | Ajouter ou réorganiser les raccourcis affichés sur `/admin` (reçoit `User\|null` en second argument). |
| `admin.dashboard.stats` | Filtre | `list<array<string, mixed>>` | Ajouter ou modifier les tuiles statistiques du tableau de bord `/admin` (reçoit `User\|null` en second argument). |

### Attribution et isolation des erreurs de plugins (`Hooks::withOwner`)

Tout écouteur ou filtre enregistré via `$context->hooks()` (ou pendant l'exécution de `register()`, `boot()`, `activate()`, `deactivate()`, `uninstall()`) est automatiquement attribué au slug du plugin propriétaire.

- Si un callback appartenant à un **plugin** lève une `Throwable` (exception ou `Error`), `Hooks` intercepte l'erreur, détache les hooks du plugin fautif pour la suite de la requête, délègue à `PluginManager` (statut `errored`, log dans `plugin.{slug}`, notice persistante, événement `plugin.failed`) et **laisse les autres écouteurs et filtres poursuivre normalement** (pour un filtre, la valeur précédente reste intacte).
- Les écouteurs du **noyau** (sans propriétaire plugin) conservent leur comportement standard : leurs exceptions continuent de se propager normalement.


