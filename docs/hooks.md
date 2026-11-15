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
