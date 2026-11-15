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
| `install.completed` | Événement | `Prime\Install\InstallResult $result` | Immédiatement après l'écriture de `storage/installed.lock` à la fin d'une installation (web ou CLI). |

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
$hooks->on('install.completed', static function (Prime\Install\InstallResult $result): void {
    // Exécuté une fois les migrations, le compte admin et storage/installed.lock en place.
});
```

