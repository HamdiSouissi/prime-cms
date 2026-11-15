# Prime CMS

**Prime CMS** est un système de gestion de contenu (CMS) 100 % original écrit en **PHP 8.2+ natif**, sans framework et avec **zéro dépendance au runtime**.

> Version actuelle : **0.1.0** (Phase 1 — Fondations du noyau)
> Référence complète d'architecture et de conventions : [`docs/CONTEXT.md`](docs/CONTEXT.md)

---

## Prérequis

- **Avec Docker** : Docker et Docker Compose v2
- **Sans Docker** :
  - PHP **8.2** ou **8.3** (`mbstring`, `json`, `gd`, `fileinfo`, `openssl`, `zip`, `pdo_mysql`, `intl` recommandée)
  - Composer (uniquement pour les outils qualité de développement : PHPUnit, PHPStan, PHP-CS-Fixer)
  - MySQL 8.0+ ou MariaDB 10.6+ (utilisé à partir de la phase 3)

---

## Démarrage rapide avec Docker

```bash
cp .env.example .env
docker compose up -d
```

L'application est immédiatement accessible sur :

- **Accueil** : [http://localhost:8080](http://localhost:8080)
- **Santé (JSON)** : [http://localhost:8080/health](http://localhost:8080/health)

Pour arrêter les conteneurs :

```bash
docker compose down
```

---

## Démarrage sans Docker (et sans `vendor/`)

Prime CMS embarque son propre autoloader PSR-4 (`bootstrap/autoload.php`) et fonctionne sans jamais exécuter `composer install` :

```bash
cp .env.example .env
php -S localhost:8080 -t public
```

Ouvrir ensuite [http://localhost:8080](http://localhost:8080).

Pour un déploiement Apache ou Nginx, pointer le document root exclusivement vers `public/` (voir `public/.htaccess` et [`docs/nginx.conf.example`](docs/nginx.conf.example)).

---

## Interface en ligne de commande (CLI)

```bash
php bin/prime list      # Liste toutes les commandes disponibles
php bin/prime about     # Version, PHP, environnement et chemins du noyau
php bin/prime routes    # Tableau des routes HTTP enregistrées
```

---

## Commandes qualité (développement)

Installer les outils de développement (uniquement `require-dev`) :

```bash
composer install
```

Exécuter les vérifications :

```bash
composer cs:check       # Vérification PSR-12 (PHP-CS-Fixer)
composer cs:fix         # Correction automatique du style
composer stan           # Analyse statique PHPStan (niveau 6)
composer test           # Suite de tests PHPUnit 11 (Unit + Integration)
```

---

## Structure du dépôt

```text
prime-cms/
├── bin/prime                 # CLI extensible
├── bootstrap/                # Autoloader PSR-4 maison et initialisation
├── config/                   # Fichiers de configuration PHP
├── content/                  # Plugins, thèmes et uploads propres au site
├── database/migrations/      # Migrations du noyau (phase 3+)
├── docker/php/               # Dockerfile PHP 8.3 + Apache et configuration
├── docs/                     # CONTEXT.md, hooks.md, nginx.conf.example, rapports de phases
├── public/                   # Seul dossier exposé au web (index.php, .htaccess, assets/)
├── resources/                # Vues PHP et fichiers de langue
├── src/                      # Code source du noyau (namespace Prime\)
├── storage/                  # Cache, logs, sessions, fichiers temporaires (non versionné)
└── tests/                    # Tests unitaires et d'intégration
```

---

## Documentation

- [`docs/CONTEXT.md`](docs/CONTEXT.md) — Architecture, règles absolues, design system et feuille de route en 15 phases
- [`docs/hooks.md`](docs/hooks.md) — Référence de tous les hooks (événements et filtres) déclenchés par le noyau
- [`docs/nginx.conf.example`](docs/nginx.conf.example) — Exemple de configuration Nginx
- [`docs/phases/`](docs/phases/) — Rapports détaillés de chaque phase
