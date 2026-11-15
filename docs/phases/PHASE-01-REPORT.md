# Phase 01 — Fondations du noyau

## Branche
`arena/1adbf8ff-prime-cms` — https://github.com/HamdiSouissi/prime-cms/pull/1

## Résumé
Mise en place complète du noyau technique de **Prime CMS** (`v0.1.0`) en PHP 8.2+ natif, sans framework ni dépendance au runtime : structure complète du dépôt, autoloader PSR-4 maison, parseur `.env`, configuration en notation pointée, conteneur DI avec autowiring et détection de cycles, cycle applicatif par service providers, moteur de hooks, couche HTTP complète (requête, réponse, routeur, middlewares, kernel), journalisation quotidienne, gestionnaire d'erreurs (dev/prod/JSON), moteur de vues PHP pur sans `extract()`, pages livrées aux couleurs de la marque, CLI extensible `bin/prime`, environnement Docker Compose (`PHP 8.3 + Apache`, `MySQL 8.0`), outils qualité (PHPUnit 11, PHPStan 2 niveau 6, PHP-CS-Fixer PSR-12), 72 tests et CI GitHub verte en PHP 8.2, PHP 8.3 et Docker Compose.

## Livrables
- [x] **L1. Structure du repo** — arborescence complète (`CONTEXT.md` §6.1) avec `.gitkeep`, `.gitignore`, `.editorconfig`, `.env.example`, `public/assets/brand/` (`logo.svg`, `logo.png`, `favicon-32.png`, `apple-touch-icon.png`, `README.md`), constante `Prime\Kernel\Application::VERSION = '0.1.0'`
- [x] **L2. Autoloader maison** — `src/Kernel/Autoloader.php`, `bootstrap/autoload.php` (PSR-4, multi-dossiers par namespace, `prepend`, chargement automatique de `src/Support/helpers.php`, fonctionnement garanti sans `vendor/`)
- [x] **L3. Environnement et configuration** — `src/Kernel/Env.php`, `src/Kernel/Config.php`, `config/app.php`, `config/logging.php`
- [x] **L4. Conteneur de services** — `src/Kernel/Container.php`, `src/Kernel/Exception/{ContainerException,NotFoundException,CircularDependencyException}.php` (autowiring par réflexion, singletons, paramètres explicites, détection de dépendances circulaires)
- [x] **L5. Application et service providers** — `src/Kernel/Application.php`, `src/Kernel/ServiceProvider.php`, `src/Kernel/Providers/{Hooks,Log,View,Http}ServiceProvider.php`, `bootstrap/app.php`
- [x] **L6. Moteur de hooks** — `src/Hooks/Hooks.php`, `src/Hooks/Exception/InvalidHookNameException.php` (`on`, `emit`, `filter`, `apply`, `off`, `has`, `fired`, `current`, priorités, stabilité, snapshots sûrs pendant l'exécution, validation `minuscules.pointées`)
- [x] **L7. Couche HTTP** — `src/Http/{Request,Response,Route,Router,Kernel}.php`, `src/Http/Middleware/{MiddlewareInterface,SecurityHeaders,TrailingSlash}.php`, `src/Http/Exception/{HttpException,NotFoundException,MethodNotAllowedException}.php`, `src/Http/Controller/HomeController.php`
- [x] **L8. Erreurs et logs** — `src/Log/Logger.php`, `src/Kernel/ErrorHandler.php` (rotation quotidienne `storage/logs/prime-YYYY-MM-DD.log`, interpolation `{placeholder}`, contexte JSON, page de développement avec extrait de code et pile d'appels, page 500 générique, réponses JSON)
- [x] **L9. Moteur de vues** — `src/View/{View,TemplateContext}.php`, `src/View/Exception/{ViewException,ViewNotFoundException}.php` (notation pointée, namespaces `admin::...`, layouts, sections, partiels, échappement `e()` / `$this->e()`, `$this->raw()`, filtre `view.data`, zéro `extract()`)
- [x] **L10. Helpers** — `src/Support/helpers.php` (`app()`, `config()`, `env()`, `e()`, `url()`, `asset()`, `view()`, `logger()`)
- [x] **L11. Pages livrées** — `resources/views/layouts/base.php`, `resources/views/home.php`, `resources/views/errors/{404,405,500,debug}.php` (tokens du design system, responsive 375 px–1440 px+, `<html lang="fr">`, favicons)
- [x] **L12. Front controller et serveur** — `public/index.php`, `public/.htaccess`, `docs/nginx.conf.example`
- [x] **L13. Docker** — `docker-compose.yml`, `docker/php/{Dockerfile,apache.conf,php.ini,entrypoint.sh}`
- [x] **L14. CLI minimale** — `bin/prime`, `src/Console/{CommandInterface,ConsoleApplication,ConsoleOutput}.php`, `src/Console/Commands/{ListCommand,AboutCommand,RoutesCommand}.php`
- [x] **L15. Qualité et CI** — `composer.json` (`require-dev` uniquement), `phpunit.xml.dist`, `phpstan.neon.dist` (niveau 6), `.php-cs-fixer.dist.php`, `.github/workflows/ci.yml`, `tests/Unit/` (13 fichiers) et `tests/Integration/` (2 fichiers)
- [x] **L16. Documentation** — `README.md`, `docs/CONTEXT.md`, `docs/hooks.md`, `docs/phases/PHASE-01-REPORT.md`

## Comment tester

### 1. Sans Docker et sans `vendor/`
```bash
cp .env.example .env
php -S localhost:8080 -t public
```
Vérifier dans le navigateur ou avec `curl` :
- `GET http://localhost:8080/` → 200 HTML aux couleurs de la marque (logo, version `0.1.0`, « Le noyau est en place », état de PHP et de `storage/`).
- `GET http://localhost:8080/health` → 200 JSON `{ "status": "ok", "app": "Prime CMS", "version": "0.1.0", "php": "8.x.x", "time": "..." }`.
- `GET http://localhost:8080/inconnu` → 404 HTML soignée avec lien « Retourner à l'accueil ».
- `GET http://localhost:8080/health/` → 301 `Location: /health`.
- `POST http://localhost:8080/health` → 405 avec en-tête `Allow: GET`.
- Vérifier les en-têtes de sécurité (`X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`, `Content-Security-Policy`) sur toutes les réponses.

### 2. Avec Docker Compose
```bash
docker compose up -d --build
curl -sf http://localhost:8080/health
```
Ouvrir `http://localhost:8080` dans le navigateur, puis `docker compose down`.

### 3. CLI
```bash
php bin/prime list
php bin/prime about
php bin/prime routes
```

### 4. Outils qualité
```bash
composer install
composer cs:check
composer stan
composer test
```

## Résultats qualité
- `cs:check` (PHP-CS-Fixer PSR-12) : OK (CI PHP 8.2 et 8.3)
- `stan` (PHPStan 2, niveau 6) : OK — 0 erreur (local + CI PHP 8.2 et 8.3)
- `test` (PHPUnit 11) : **72 tests, 343 assertions, 0 échec** (PHP 8.2 et 8.3)
- Docker Compose smoke test (CI GitHub) : OK (`docker compose up -d --build`, vérification `/`, `/health`, 404, 301, 405, en-têtes de sécurité et extensions PHP)

## Corrections (revue 1)
- [x] **C1. Décodage des paramètres de route par segment** — `Prime\Http\Route::matchesPath()` découpe désormais le chemin sur `/` avant d'appliquer `rawurldecode()` sur chaque segment et valide les contraintes (avec drapeau UTF-8 `/u`) sur la valeur décodée ; un `%2F` encodé reste contenu dans un seul segment. Tests ajoutés dans `tests/Unit/Http/RouterTest.php` (espace encodé `%20`, accents UTF-8 `%C3%A9t%C3%A9`, `%2F` conservé sur un seul segment et rejet de `/a/b`).
- [x] **C2. Encodage des paramètres lors de la génération d'URL** — `Prime\Http\Route::generateUrl()` valide la valeur brute contre la contrainte (avec drapeau `/u`, et `.+` par défaut pour permettre `/` dans une valeur de segment unique) puis l'insère encodée via `rawurlencode()`. Tests ajoutés dans `tests/Unit/Http/RouterTest.php` (espaces, accents UTF-8 et `/` encodé en `%2F`).
- [x] **C3. Normalisation des chemins multi-slashes (`//`) et protection open redirect** — `Prime\Http\Request::fromGlobals()` et `Request::create()` n'utilisent plus `parse_url()` sur les `REQUEST_URI` relatifs : découpage sur `#` puis `?` et fusion des slashes consécutifs (`//health` → `/health`, `///x//y/` → `/x/y/`), `parse_url()` étant réservé aux URI absolues `http://`/`https://` dans `Request::create()`. `TrailingSlash` garantit en défense en profondeur que `Location` ne commence jamais par `//`. Tests ajoutés dans `tests/Unit/Http/RequestTest.php` et `tests/Unit/Http/MiddlewarePipelineTest.php`.
- [x] **C4. Enregistrement précoce du gestionnaire d'erreurs** — `Application::__construct()` accepte `bool $registerErrorHandler = false` et expose `registerErrorHandler()` pour créer et enregistrer l'`ErrorHandler` (avec un `Logger` et une `View` de base) immédiatement après le chargement de `.env` et de la configuration, avant le démarrage des providers. `bootstrap/app.php` l'active hors contexte PHPUnit (`!defined('PHPUNIT_COMPOSER_INSTALL') && !defined('__PHPUNIT_PHAR__')`), l'appel tardif dans `public/index.php` est supprimé et `LogServiceProvider` conserve les instances déjà enregistrées tout en mettant à jour la `View` au `boot()`. Tests d'intégration ajoutés dans `tests/Integration/HttpKernelTest.php` pour un échec dans le `boot()` d'un provider en mode production et debug.
- [x] **C5. Protection de la clé réservée `this` dans le moteur de vues** — `Prime\View\View::evaluateTemplate()` rejette explicitement la clé `this` en levant une `ViewException` claire (et l'ignore également dans la boucle d'injection). Test dédié ajouté dans `tests/Unit/View/ViewTest.php`.
- [x] **C6. Licence en attente de décision** — `"license": "MIT"` remplacé par `"license": "proprietary"` dans `composer.json`, sans fichier `LICENSE`.
- [x] **C7. Verrouillage des outils de dev** — `composer.lock` généré (64 paquets dev compatibles PHP 8.2 et 8.3, zéro paquet runtime, `content-hash` synchronisé avec `composer.json`), non ignoré par `.gitignore`, commité et validé par `composer install` en CI PHP 8.2 et 8.3.
- [x] **C8. Masquage des informations système en production (`APP_DEBUG=false`)** — lorsque `APP_DEBUG=false`, `HomeController::index()` et `resources/views/home.php` n'affichent plus la carte « État du système » (uniquement logo, titre, version de Prime CMS et sous-titre) et `HomeController::health()` omet le champ `php` ; en mode `APP_DEBUG=true`, le comportement détaillé est conservé. Tests ajoutés pour les deux modes dans `tests/Integration/HttpKernelTest.php`.

## Hooks ajoutés
Tous documentés dans `docs/hooks.md` :
- `app.registered` (événement) — déclenché après `register()` de tous les service providers
- `app.booted` (événement) — déclenché après `boot()` de tous les service providers
- `routes.register` (événement) — déclenché lors du démarrage du routeur pour permettre l'enregistrement de routes supplémentaires
- `console.commands` (événement) — déclenché lors de l'initialisation de la CLI `bin/prime` pour enregistrer des commandes `CommandInterface`
- `http.response` (filtre) — appliqué à la `Response` finale dans `Prime\Http\Kernel::handle()`
- `view.data` (filtre) — appliqué aux données d'un template avant rendu dans `Prime\View\View::render()`

## Décisions prises
1. **Logo temporaire** : les fichiers `logo.svg` et `logo.png` n'ayant pas été joints au prompt, un logo SVG vectoriel temporaire fidèle à la section 8.1 (carré arrondi `#ED6647`, monogramme « PC » blanc au trait fin) a été créé, décliné en `logo.png` (512×512), `apple-touch-icon.png` (180×180) et `favicon-32.png` (32×32), et documenté dans `public/assets/brand/README.md`.
2. **Support `HEAD` sur les routes `GET`** : `Route::matchesMethod()` accepte automatiquement `HEAD` sur une route `GET` (conformément à RFC 9110) sans ajouter `HEAD` à la liste retournée dans l'en-tête `Allow`, afin de préserver exactement `Allow: GET` sur `POST /health`.
3. **Gestionnaire d'erreurs précoce et non intrusif pendant les tests** : `bootstrap/app.php` active l'enregistrement de `ErrorHandler` dans `Application` dès le chargement de `.env` et de la configuration (avant `boot()`) hors exécution PHPUnit, afin que les erreurs de démarrage des providers soient journalisées et affichées proprement. Pendant le traitement HTTP, `Http\Kernel::handle()` délègue toute exception à `ErrorHandler::handleException()` à l'intérieur du pipeline de middlewares afin que toutes les réponses d'erreur (404, 405, 500) reçoivent systématiquement les en-têtes de sécurité et passent par le filtre `http.response`.
4. **Vue dédiée `errors/405.php`** : ajoutée en complément de `404.php`, `500.php` et `debug.php` afin que les réponses HTML 405 bénéficient également d'un rendu soigné aux couleurs de la marque.
5. **Dockerfile PHP 8.3** : l'extension `mbstring` étant déjà compilée statiquement dans l'image officielle `php:8.3-apache`, elle n'est pas réinstallée via `docker-php-ext-install` pour éviter tout warning `Module "mbstring" is already loaded`. Un script d'entrée léger (`docker/php/entrypoint.sh`) garantit l'accessibilité en écriture de `storage/*` et `content/uploads` sous Apache (`www-data`) quel que soit l'OS hôte.
6. **Smoke test Docker Compose en CI** : ajouté en complément de la matrice PHP 8.2 / 8.3 dans `.github/workflows/ci.yml` pour valider automatiquement le démarrage complet de la stack Docker et les endpoints HTTP à chaque push.

## Limites connues et points ouverts
- **Logo officiel à intégrer** : remplacer les fichiers temporaires de `public/assets/brand/` dès réception des fichiers officiels fournis par Hamdi (`logo.svg`, `logo.png`), puis régénérer `favicon-32.png` et `apple-touch-icon.png`.
- **Polices système en phase 1** : comme prévu dans le prompt, les vues de la phase 1 utilisent la pile de polices système ; les polices locales (`Plus Jakarta Sans`, `Inter`, `JetBrains Mono`) arriveront en phase 2.
- **Base de données non connectée** : le conteneur `db` (MySQL 8.0) et les variables `DB_*` commentées dans `.env.example` sont prêts pour la phase 3, mais aucune connexion PDO n'est encore ouverte par l'application (hors périmètre phase 1).
