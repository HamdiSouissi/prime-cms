# Prime CMS

**Prime CMS** est un système de gestion de contenu (CMS) 100 % original écrit en **PHP 8.2+ natif**, sans framework et avec **zéro dépendance au runtime**.

> Version actuelle : **0.1.0** (Phase 3 — Base de données et installateur)
> Référence complète d'architecture et de conventions : [`docs/CONTEXT.md`](docs/CONTEXT.md)

---

## Prérequis

- **Avec Docker** : Docker et Docker Compose v2
- **Sans Docker** :
  - PHP **8.2** ou **8.3** (`mbstring`, `json`, `gd`, `fileinfo`, `openssl`, `zip`, `pdo_mysql`, `intl` recommandée)
  - **MySQL 8.0+** ou **MariaDB 10.6+**
  - Composer (uniquement pour les outils qualité de développement : PHPUnit, PHPStan, PHP-CS-Fixer)

---

## Démarrage rapide avec Docker

```bash
cp .env.example .env
docker compose up -d
```

1. Ouvrir [http://localhost:8080](http://localhost:8080) : tant que le site n'est pas installé, Prime CMS redirige automatiquement vers l'assistant `/install`.
2. Suivre les 7 étapes de l'assistant web (ou installer en une commande via `docker compose exec app php bin/prime install ...`).
3. Une fois installé, les routes `/install*` retournent `404` et le site est accessible :
   - **Accueil** : [http://localhost:8080](http://localhost:8080)
   - **Santé (JSON)** : [http://localhost:8080/health](http://localhost:8080/health)
   - **Dashboard (aperçu local)** : [http://localhost:8080/admin](http://localhost:8080/admin)
   - **Design System (styleguide)** : [http://localhost:8080/admin/styleguide](http://localhost:8080/admin/styleguide)

> **Sécurité** : tant que l'authentification n'est pas en place (Phase 4), toutes les routes `/admin/*` sont restreintes à `APP_ENV=local` par `AdminPreviewGate` et retournent `404` dans tout autre environnement.

Pour arrêter les conteneurs :

```bash
docker compose down
```

---

## Démarrage sans Docker (et sans `vendor/`)

Prime CMS embarque son propre autoloader PSR-4 (`bootstrap/autoload.php`) et fonctionne sans jamais exécuter `composer install` :

```bash
php -S localhost:8080 -t public
```

Ouvrir ensuite [http://localhost:8080/install](http://localhost:8080/install) pour configurer la base de données, le site et le compte administrateur.

Pour un déploiement Apache ou Nginx, pointer le document root exclusivement vers `public/` (voir `public/.htaccess` et [`docs/nginx.conf.example`](docs/nginx.conf.example)).

---

## Interface en ligne de commande (CLI)

```bash
php bin/prime list                           # Liste toutes les commandes disponibles
php bin/prime about                          # Version, PHP, environnement et chemins du noyau
php bin/prime routes                         # Tableau des routes HTTP enregistrées
php bin/prime assets:build                   # Compile le CSS admin dans public/assets/admin/css/admin.css
php bin/prime db:check                       # Teste la connexion SQL et affiche la version serveur
php bin/prime migrate                        # Exécute les migrations en attente
php bin/prime migrate:status                 # État de toutes les migrations
php bin/prime migrate:rollback --step=1      # Annule les N derniers lots de migrations
php bin/prime migrate:reset                  # Annule toutes les migrations
php bin/prime migrate:fresh                  # Réinitialise le schéma (--force en production)
php bin/prime make:migration <nom>           # Crée un fichier de migration horodaté
php bin/prime settings:get <nom>             # Lit la valeur JSON d'un réglage
php bin/prime settings:set <nom> <json>      # Enregistre un réglage typé JSON
php bin/prime install [options]              # Installe Prime CMS sans interaction
```

---

## Commandes qualité et tests (développement)

Installer les outils de développement (uniquement `require-dev`) :

```bash
composer install
```

Exécuter les vérifications :

```bash
composer cs:check                                    # Vérification PSR-12 (PHP-CS-Fixer)
composer cs:fix                                      # Correction automatique du style
composer stan                                        # Analyse statique PHPStan (niveau 6)
composer test                                        # Suite complète (Unit + Integration)
vendor/bin/phpunit --group database --fail-on-skipped # Tests SQL réels (requiert PRIME_TEST_DB_*)
```

---

## Structure du dépôt

```text
prime-cms/
├── bin/prime                 # CLI extensible
├── bootstrap/                # Autoloader PSR-4 maison et initialisation
├── config/                   # Fichiers de configuration PHP (app, database, logging)
├── content/                  # Plugins, thèmes et uploads propres au site
├── database/migrations/      # Migrations du noyau (pc_settings, pc_users, …)
├── docker/php/               # Dockerfile PHP 8.3 + Apache et configuration
├── docs/                     # CONTEXT.md, database.md, install.md, design-system.md, hooks.md, rapports
├── public/                   # Seul dossier exposé au web (index.php, .htaccess, assets/)
├── resources/                # Vues PHP (layouts, admin, install, errors) et assets sources
├── src/                      # Code source du noyau (namespace Prime\)
├── storage/                  # Cache, logs, sessions, installed.lock (non versionné)
└── tests/                    # Tests unitaires et d'intégration (y compris #[Group('database')])
```

---

## Documentation

- [`docs/CONTEXT.md`](docs/CONTEXT.md) — Architecture, règles absolues, design system et feuille de route en 15 phases
- [`docs/database.md`](docs/database.md) — Couche PDO, `QueryBuilder`, `Schema`/`Blueprint`, migrations et `Settings`
- [`docs/install.md`](docs/install.md) — Assistant d'installation web, repli `.env` lecture seule et CLI `bin/prime install`
- [`docs/design-system.md`](docs/design-system.md) — Guide complet du design system, des composants, icônes et modules JS
- [`docs/hooks.md`](docs/hooks.md) — Référence de tous les hooks (événements et filtres)
- [`docs/nginx.conf.example`](docs/nginx.conf.example) — Exemple de configuration Nginx
- [`docs/phases/`](docs/phases/) — Rapports détaillés de chaque phase
