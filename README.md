# Prime CMS

**Prime CMS** est un système de gestion de contenu (CMS) 100 % original écrit en **PHP 8.2+ natif**, sans framework et avec **zéro dépendance au runtime**.

> Version actuelle : **0.7.0** (Phase 7 — Médiathèque : upload sécurisé, GD/WebP, bibliothèque, sélecteur réutilisable & intégration complète)
> Référence complète d'architecture et de conventions : [`docs/CONTEXT.md`](docs/CONTEXT.md)

---

## Prérequis

- **Avec Docker** : Docker et Docker Compose v2
- **Sans Docker** :
  - PHP **8.2** ou **8.3** (`mbstring`, `json`, `gd`, `exif`, `fileinfo`, `openssl`, `zip`, `pdo_mysql`, `intl` recommandée)
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
   - **Connexion administration** : [http://localhost:8080/admin/login](http://localhost:8080/admin/login)
   - **Tableau de bord (authentifié)** : [http://localhost:8080/admin](http://localhost:8080/admin)
   - **Contenus & Taxonomies** : `/admin/content/page`, `/admin/content/post`, `/admin/taxonomies/category`, `/admin/taxonomies/tag` (éditeur de blocs natif **Prime Editor**, image à la une, champs `media`, révisions/diff, autosave, verrous d'édition et aperçu sécurisé)
   - **Médiathèque** : `/admin/media` (upload parallèle drag & drop, traitement GD/WebP, dossiers hiérarchiques, point focal, suivi des utilisations et sélecteur réutilisable)
   - **Plugins** : `/admin/plugins` (incluant le plugin de référence `hello-prime`)
   - **Utilisateurs, rôles & profil** : `/admin/users`, `/admin/roles`, `/admin/profile`
   - **Design System (styleguide, admin + `APP_ENV=local`)** : [http://localhost:8080/admin/styleguide](http://localhost:8080/admin/styleguide)

> **Sécurité** : toutes les routes `/admin/*` sont protégées par session authentifiée, contrôle d'accès par rôles et permissions (RBAC), jetons CSRF, limitation de débit (HTTP 429), 2FA TOTP optionnelle et en-têtes `Cache-Control: no-store`. Voir [`docs/security.md`](docs/security.md).

Pour arrêter les conteneurs :

```bash
docker compose down
```

---

## Démarrage sans Docker (et sans `vendor/`)

Prime CMS embarque son propre autoloader PSR-4 (`bootstrap/autoload.php`) et fonctionne sans jamais exécuter `composer install` :

```bash
php -S localhost:8080 -t public public/router.php
```

> Le script `public/router.php` sert directement les fichiers statiques existants de `public/` et délègue tout le reste à `public/index.php` (ce qui permet notamment au repli PHP `/uploads/…` de fonctionner en développement sans lien symbolique).

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
php bin/prime role:list                      # Liste les rôles, permissions et utilisateurs assignés
php bin/prime user:list [options]            # Liste les utilisateurs (--role, --status, --search)
php bin/prime user:create [options]          # Crée un utilisateur (--email, --name, --role, --password)
php bin/prime user:password <email>          # Définit ou génère un nouveau mot de passe (révoque les sessions)
php bin/prime user:role <email> <roles>      # Assigne, ajoute (--add) ou retire (--remove) des rôles
php bin/prime user:disable <email>           # Désactive un compte et révoque ses sessions
php bin/prime user:enable <email>            # Réactive un compte désactivé
php bin/prime user:2fa:reset <email>         # Réinitialisation d'urgence de la 2FA (TOTP)
php bin/prime plugin:list [options]          # Liste les plugins (--status=..., --json)
php bin/prime plugin:activate <slug>         # Active un plugin (migrations + assets + hooks)
php bin/prime plugin:deactivate <slug>       # Désactive un plugin (données conservées)
php bin/prime plugin:uninstall <slug>        # Désinstalle un plugin inactif (--keep-files)
php bin/prime plugin:install <zip>           # Installe un plugin depuis une archive ZIP (--replace, --force)
php bin/prime plugin:publish-assets [slug]   # Publie les assets statiques autorisés
php bin/prime make:plugin <slug>             # Génère le squelette d'un nouveau plugin
php bin/prime content:types                  # Liste les types de contenus et taxonomies enregistrés
php bin/prime content:list [options]         # Liste les contenus (--type, --status, --search, --limit)
php bin/prime content:create [options]       # Crée un contenu (--type, --title, --status, --body)
php bin/prime content:publish-scheduled      # Publie les contenus planifiés arrivés à échéance
php bin/prime content:purge-trash [--days=N] # Purge définitivement les contenus en corbeille
php bin/prime content:rerender [--type=...]  # Re-génère content_html et content_text depuis JSON
php bin/prime media:regenerate [options]     # Régénère les variantes GD et WebP (--id, --variant, --force)
php bin/prime media:link                     # Crée ou vérifie le symlink public/uploads et .htaccess
php bin/prime media:cleanup [--dry-run]      # Nettoie les fichiers orphelins dans content/uploads/
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
composer stan                                        # Analyse statique PHPStan (niveau 8)
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
- [`docs/security.md`](docs/security.md) — Architecture sécurité (Argon2id, AES-256-GCM, sessions, remember-me, rate limiting, 2FA TOTP)
- [`docs/users-and-roles.md`](docs/users-and-roles.md) — Rôles, catalogue de permissions (RBAC), garde `UserGuard` et commandes CLI
- [`docs/plugins.md`](docs/plugins.md) — Guide complet du système de plugins, API `PluginContext`, SemVer, isolation et sécurité ZIP
- [`docs/content.md`](docs/content.md) — Types de contenus (CPT), taxonomies, champs personnalisés, `EntryService`, `EntryQuery`, révisions et verrous
- [`docs/editor.md`](docs/editor.md) — Prime Editor (format JSON v1, 15 blocs natifs, `HtmlSanitizer`, CSP aperçu et blocs de plugins)
- [`docs/media.md`](docs/media.md) — Médiathèque (pipeline upload sécurisé, GD/WebP, point focal, sélecteur `Prime.media.open()`, helpers responsive et CLI)
- [`docs/design-system.md`](docs/design-system.md) — Guide complet du design system, des composants, icônes et modules JS
- [`docs/hooks.md`](docs/hooks.md) — Référence de tous les hooks (événements et filtres)
- [`docs/nginx.conf.example`](docs/nginx.conf.example) — Exemple de configuration Nginx
- [`docs/phases/`](docs/phases/) — Rapports détaillés de chaque phase
