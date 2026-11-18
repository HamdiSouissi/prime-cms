# Prime CMS — Contexte du projet pour l'agent développeur

> Ce document est ta référence permanente. Lis-le entièrement avant chaque phase.
> Il doit être commité dans le repo sous `docs/CONTEXT.md` (phase 1) et tenu à jour si une phase le demande.

---

## 1. Le projet en une phrase

**Prime CMS** est un CMS de création de sites internet, **inspiré de WordPress dans son principe** (contenus, thèmes, plugins, dashboard), mais **100 % original** : notre propre architecture, notre propre schéma de base de données, notre propre API de plugins et notre propre code.

## 2. Règles absolues

1. **Aucune copie de code WordPress** (ni fichiers, ni fonctions, ni schéma de tables). Aucune compatibilité avec les thèmes et plugins WordPress n'est recherchée. Les noms de fonctions, classes, tables et hooks sont **les nôtres**.
2. **PHP natif, sans framework** (pas de Laravel, Symfony, Slim…). **MySQL** comme base de données.
3. **Zéro dépendance au runtime.** Composer sert **uniquement** aux outils de développement (PHPUnit, PHPStan, PHP-CS-Fixer). Le CMS doit tourner sans `vendor/` grâce à son propre autoloader.
4. **JavaScript vanilla** (ES2022, modules natifs), **sans bundler ni framework**, et sans bibliothèque externe. Tout le JS est écrit par nous.
5. **Sécurité par défaut** : requêtes préparées uniquement, échappement systématique en sortie, CSRF sur toutes les actions qui modifient des données, mots de passe en `password_hash` (Argon2id si disponible).
6. **Tu ne merges jamais dans `main`.** Tu travailles sur la branche que ta plateforme te crée automatiquement (format `arena/xxxxxxxx-prime-cms`). La validation est faite par l'architecte (Claude), puis Hamdi merge dans `main`.
7. **Tu ne sors pas du périmètre de la phase.** Si tu vois un besoin hors périmètre, tu le notes dans le rapport de phase au lieu de le coder.

## 3. Équipe et workflow

| Rôle | Qui | Responsabilité |
|---|---|---|
| Porteur du projet | Hamdi | Décide, transmet les prompts, merge les PR validées |
| Architecte, lead dev, DA, UI/UX, chef de projet | Claude | Conçoit, rédige les prompts de phase, relit et teste chaque branche |
| Développeur | **Toi (agent IA)** | Implémente chaque phase selon son prompt |

Cycle de chaque phase :
1. Tu reçois le prompt de la phase N.
2. Tu travailles sur la branche créée automatiquement par ta plateforme (`arena/xxxxxxxx-prime-cms`). **Tu ne crées pas et ne renommes pas de branche.** Avant de coder, vérifie que ta branche part bien du dernier état de `main` (`git fetch origin && git log origin/main -1`) ; sinon, mets-la à jour avec `git rebase origin/main` (ou `git merge origin/main` si le rebase est impossible).
3. Tu implémentes, tu testes, tu commits régulièrement.
4. Tu rédiges `docs/phases/PHASE-NN-REPORT.md` (modèle en section 12), en indiquant le **nom exact de ta branche**.
5. Tu pousses ta branche. Si ta plateforme le permet, tu ouvres une PR vers `main` intitulée `Phase NN — Nom de la phase` ; sinon, tu donnes simplement le nom de la branche.
6. Tu termines toujours ta réponse par le **nom exact de la branche** (et le lien de la PR s'il existe) : Hamdi le transmet à Claude, qui relit la branche.
7. Tu reçois soit un **prompt de correction**, soit la validation.
   - Correction : tu corriges sur la même branche si tu y as accès. Si ta plateforme te donne une nouvelle branche, tu commences par y récupérer le travail précédent (`git fetch origin && git merge origin/<branche précédente>`), puis tu corriges, et tu donnes le nom de la nouvelle branche.
   - Validation : Hamdi merge dans `main`, et la phase suivante repart de `main` à jour.

## 4. Découpage en 12 phases

| # | Phase | Contenu principal |
|---|---|---|
| **1** | **Fondations du noyau** | Structure du repo, autoloader, kernel, config/.env, conteneur, moteur de hooks, HTTP (requête, réponse, routeur, middlewares), gestion des erreurs, logs, vues, Docker, outils qualité, CI |
| **2** | **Design system et coque du dashboard** | Tokens CSS, polices locales, bibliothèque de composants UI, layout admin (sidebar, barre supérieure, palette de commandes), registre de menus admin, page « styleguide » |
| **3** | **Base de données et installateur** | Connexion PDO, query builder, système de migrations, schéma du noyau, CLI `bin/prime`, assistant d'installation web |
| **4** | **Authentification, utilisateurs, rôles** | Sessions sécurisées, connexion, mot de passe oublié, limitation des tentatives, rôles et permissions, gestion des utilisateurs, profil, 2FA (TOTP) |
| **5** | **Système de plugins** | Manifeste `plugin.json`, découverte, activation, désactivation, désinstallation, dépendances, migrations de plugins, installation par ZIP, isolation des erreurs, plugin d'exemple |
| **6** | **Contenus** | Types de contenu (pages, articles, types personnalisés), statuts, planification, slugs, révisions, taxonomies, champs personnalisés, corbeille, éditeur de blocs « Prime Editor » |
| **7** | **Médiathèque** | Upload sécurisé, traitement d'image (GD : miniatures, WebP), bibliothèque, dossiers, sélecteur de média réutilisable |
| **8** | **Thèmes et rendu du site** | Système de thèmes, hiérarchie de templates, permaliens, menus, zones de widgets, SEO de base (meta, sitemap, robots), thème par défaut « Aurora », options de thème |
| **9** | **Réglages, langues, cache, mails, tâches** | Pages de réglages, i18n (FR, EN, IT), cache de pages et d'objets, client SMTP, planificateur de tâches, journal d'audit |
| **10** | **API REST** | API JSON versionnée, authentification par jetons, endpoints du noyau, extension par les plugins (base pour le builder et la boutique) |
| **11** | **Socle de plugin** | Plugin de base complet `prime-starter` installé et activé, `make:plugin` qui génère ce socle, points d'extension pour les futurs gros plugins |
| **12** | **Finalisation v1.0** | Performance, audit de sécurité, accessibilité, tests de bout en bout, mises à jour, contenu de démo, documentation, release 1.0.0 |

Prime Builder (constructeur de pages) et Prime Commerce (boutique) seront développés à part, comme plugins indépendants, après la v1.0.

Le contenu détaillé de chaque phase te sera donné dans son prompt. **Ne code pas en avance sur les phases suivantes.**

## 5. Stack technique

- **PHP 8.2 minimum** (testé en 8.2 et 8.3), `declare(strict_types=1);` dans tous les fichiers PHP
- **MySQL 8.0+** ou **MariaDB 10.6+**, moteur InnoDB, `utf8mb4_unicode_ci`
- Accès base : **PDO** uniquement, requêtes préparées
- Serveur : Apache (avec `.htaccess`) ou Nginx (config d'exemple dans `docs/`), document root = `public/`
- Environnement local : **Docker Compose** (PHP 8.3 + Apache, MySQL 8)
- Extensions PHP requises : `pdo_mysql`, `mbstring`, `json`, `gd`, `fileinfo`, `openssl`, `zip`, `intl` (optionnelle, avec repli)
- Outils de dev (Composer, `require-dev` uniquement) : PHPUnit 11, PHPStan 2 (niveau 6 au départ, puis 8 à partir de la phase 5), PHP-CS-Fixer (PSR-12)

## 6. Architecture

### 6.1 Arborescence cible

```
prime-cms/
├── bin/
│   └── prime                 # CLI (migrations, cache, utilisateurs…)
├── bootstrap/
│   ├── autoload.php          # autoloader PSR-4 maison
│   └── app.php               # construit et retourne l'application
├── config/                   # fichiers PHP qui retournent des tableaux
├── content/                  # tout ce qui est propre au site
│   ├── plugins/
│   ├── themes/
│   └── uploads/
├── database/
│   └── migrations/           # migrations du noyau
├── docs/
│   ├── CONTEXT.md
│   └── phases/               # rapports de phase
├── public/                   # seul dossier exposé au web
│   ├── index.php             # front controller unique
│   ├── .htaccess
│   └── assets/
│       ├── brand/            # logo, favicons
│       ├── fonts/
│       └── admin/            # css/ js/ icons/
├── resources/
│   ├── views/                # vues du noyau (admin, erreurs, installateur)
│   └── lang/                 # fr.php, en.php, it.php
├── src/                      # namespace racine Prime\
│   ├── Kernel/               # Application, Container, Config, Env, ServiceProviders
│   ├── Http/                 # Request, Response, Router, Route, Middleware
│   ├── Hooks/                # moteur d'événements et de filtres
│   ├── Database/             # Connection, QueryBuilder, Schema, Migrations
│   ├── Security/             # Csrf, Escaper, Sanitizer, RateLimiter, Hasher
│   ├── Auth/                 # Guard, Session, Permissions
│   ├── View/                 # moteur de vues PHP
│   ├── Admin/                # contrôleurs et registre de menus du dashboard
│   ├── Content/  Media/  Themes/  Plugins/  Settings/  Users/
│   ├── Mail/  Cache/  Log/  I18n/  Scheduler/  Api/
│   └── Support/              # helpers, Str, Arr, Collection
├── storage/                  # non versionné (sauf .gitkeep)
│   ├── cache/  logs/  sessions/  tmp/
├── tests/
│   ├── Unit/
│   └── Integration/
├── .env.example
├── composer.json             # dev uniquement
├── docker-compose.yml
└── README.md
```

### 6.2 Cycle d'une requête

`public/index.php` → `bootstrap/app.php` (autoload, `.env`, config, conteneur, service providers) → chargement des plugins actifs → chargement du thème actif → `Kernel::handle(Request)` → pipeline de middlewares → `Router` → contrôleur → `Response` → envoi.

### 6.3 Moteur de hooks (le cœur de l'extensibilité)

Notre API, à nous, utilisée par le noyau, les plugins et les thèmes :

```php
$hooks->on('content.saved', $callback, priority: 10);     // écouter un événement
$hooks->emit('content.saved', $entry);                     // déclencher un événement
$hooks->filter('content.title', $callback, priority: 10);  // enregistrer un filtre
$title = $hooks->apply('content.title', $title, $entry);   // appliquer les filtres
$hooks->off(...); $hooks->has(...); $hooks->fired(...);
```

- Noms de hooks en **minuscules, notation pointée** : `domaine.action` (`app.booted`, `admin.menu`, `http.response`, `content.saved`, `commerce.order.paid`…)
- Priorité : entier, la plus basse passe en premier, 10 par défaut ; ordre d'insertion conservé à priorité égale
- Chaque hook déclenché par le noyau est **documenté** dans `docs/hooks.md` (créé en phase 1, enrichi à chaque phase)

### 6.4 Plugins (détaillé en phase 5)

Un plugin = un dossier dans `content/plugins/{slug}/` avec :
- `plugin.json` : `slug`, `name`, `description`, `version`, `author`, `requires` (`prime`, `php`), `dependencies`, `namespace`, `main`
- Une classe principale qui implémente `Prime\Plugins\PluginInterface` : `register()`, `boot()`, `activate()`, `deactivate()`, `uninstall()`
- Optionnel : `migrations/`, `views/`, `assets/`, `lang/`

Les plugins officiels (Prime Builder, Prime Commerce) suivent **exactement** la même API que n'importe quel plugin tiers : aucun passe-droit dans le noyau.

### 6.5 Thèmes (détaillé en phase 8)

Un thème = un dossier dans `content/themes/{slug}/` avec `theme.json`, une classe `Theme` (implémente `Prime\Themes\ThemeInterface`), `templates/`, `assets/`, `screenshot.png`. Hiérarchie de templates **propre à Prime CMS** (définie en phase 8).

### 6.6 Base de données (détaillé en phase 3)

- Préfixe configurable, `pc_` par défaut
- Tables au **pluriel, snake_case** : `pc_users`, `pc_roles`, `pc_entries`, `pc_entry_meta`, `pc_taxonomies`, `pc_terms`, `pc_media`, `pc_settings`, `pc_plugins`, `pc_migrations`…
- Clés primaires `id BIGINT UNSIGNED AUTO_INCREMENT`, colonnes `created_at` / `updated_at` en `DATETIME` (UTC), clés étrangères explicites
- Toute modification de schéma passe par une **migration** versionnée (jamais de SQL manuel)

## 7. Conventions de code

- **PSR-12** pour le style, **PSR-4** pour l'autoload (namespace `Prime\` → `src/`)
- Code, commentaires, noms de variables et messages de commit **en anglais** ; documentation et interface **en français** (l'interface passe par l'i18n à partir de la phase 9)
- Classes `final` par défaut, propriétés typées, types de retour partout, `readonly` quand c'est pertinent
- Pas d'état global, sauf via le conteneur. Les helpers globaux (`app()`, `config()`, `e()`, `url()`, `asset()`…) sont de fines façades sur le conteneur, définies dans `src/Support/helpers.php`, et préfixées pour éviter les collisions si nécessaire
- Pas de `echo` dans les classes métier : tout passe par `Response` et les vues
- Exceptions métier dans des classes dédiées (`Prime\...\Exception\...`)
- Un contrôleur fin, la logique dans des services
- Échappement en sortie avec `e()` dans **toutes** les vues ; HTML brut seulement via une méthode explicitement nommée (`raw()`)
- **Tests** : toute classe du noyau a des tests unitaires ; toute fonctionnalité a au moins un test d'intégration à partir de la phase 3
- Avant chaque push, ces trois commandes doivent passer : `composer cs:check`, `composer stan`, `composer test`

### Git
- Branches : nommées automatiquement par la plateforme (`arena/xxxxxxxx-prime-cms`). Ne jamais créer, renommer ou supprimer de branche ; ne jamais pousser sur `main`. Le numéro de phase apparaît dans le titre de la PR, dans le rapport et dans les messages de commit si utile.
- Commits au format **Conventional Commits** : `feat(http): add router with named routes`, `fix(...)`, `test(...)`, `docs(...)`, `chore(...)`, `refactor(...)`
- Commits petits et cohérents (pas un seul commit géant)
- Jamais de secrets, de `.env`, de `vendor/` ni de `storage/*` dans le repo

## 8. Identité visuelle et design system

Le dashboard doit être **premium, élégant, simple à utiliser, avec des couleurs vivantes**. Tout est dérivé du logo.

### 8.1 Logo
Icône carrée aux coins très arrondis, fond **orange corail #ED6647**, monogramme **« PC »** blanc au trait fin. Fichiers : `public/assets/brand/logo.svg` et `public/assets/brand/logo.png` (fournis par Hamdi), plus des favicons dérivés.

Ce que le logo impose : **coins arrondis généreux**, **trait fin** (icônes en ligne, stroke 1,5 px), **beaucoup de blanc** avec le corail en accent.

### 8.2 Tokens de couleur (variables CSS)

```css
:root {
  /* Marque — corail du logo */
  --brand-50:  #FFF4F1;
  --brand-100: #FDE3DC;
  --brand-200: #FAC4B6;
  --brand-400: #F28468;
  --brand-500: #ED6647; /* couleur exacte du logo : accents, icônes actives, focus, graphiques */
  --brand-600: #C9441F; /* boutons principaux avec texte blanc, liens (contraste AA 4,85:1) */
  --brand-700: #A8381A; /* survol / appui des boutons principaux */

  /* Neutres — encre chaude */
  --ink-900: #1A1D29;   /* sidebar, titres */
  --ink-700: #3A3F52;   /* texte courant */
  --ink-500: #6B7085;   /* texte secondaire */
  --ink-300: #C9CCD6;   /* bordures de champs */
  --ink-200: #E6E4E1;   /* séparateurs, bordures de cartes */
  --ink-100: #F3F1EE;   /* zones secondaires */
  --canvas:  #FAF8F6;   /* fond général */
  --surface: #FFFFFF;   /* cartes, panneaux, modales */

  /* Couleurs vivantes d'accompagnement */
  --violet:  #7C5CFF;   /* plugins, builder */
  --teal:    #14B8A6;   /* médias, contenus */
  --sky:     #3B82F6;   /* information, utilisateurs */
  --amber:   #F5A524;   /* avertissement, boutique */
  --success: #1F9D63;
  --danger:  #D92D4A;   /* cramoisi, distinct du corail */
}
```

**Règle de contraste** : pas de texte de taille normale en blanc sur `--brand-500` (3,19:1 seulement). Les boutons principaux utilisent `--brand-600`.

### 8.3 Typographie
- Titres : **Plus Jakarta Sans** (500 à 700) — texte et UI : **Inter** (400 à 600) — code : **JetBrains Mono**
- Polices **hébergées dans le projet** (`public/assets/fonts/`, woff2, avec leur licence OFL), jamais de CDN
- Échelle : 12 / 13 / **14 (base)** / 16 / 20 / 24 / 32 px

### 8.4 Formes et espacements
- Grille de 4 / 8 px
- Rayons : 6 px (badges, champs), 10 px (boutons), **14 px (cartes)**, 20 px (modales), 12 px (tuiles d'icône)
- Ombre douce : `0 1px 2px rgba(26,29,41,.06), 0 4px 16px rgba(26,29,41,.06)` ; une bordure fine avant toute ombre
- Icônes SVG en ligne type Lucide (stroke 1,5 px), stockées localement

### 8.5 Structure du dashboard
- **Sidebar sombre** (`--ink-900`), logo en haut, élément actif marqué d'une pastille `--brand-500` et d'un fond `rgba(237,102,71,.14)`, repliable en icônes
- **Barre supérieure blanche** : recherche globale (Ctrl/⌘ + K), bouton « Créer » corail, notifications, avatar
- **Contenu** sur fond `--canvas`, cartes blanches
- Chaque module a sa couleur d'accompagnement pour son icône
- Responsive de 375 px à 1440 px et plus ; la sidebar devient un tiroir sur mobile

### 8.6 Principes UX
- Une action principale par écran, en corail
- Libellés de boutons avec un verbe (« Publier la page », jamais « OK » ni « Valider »)
- États vides avec un appel à l'action, chargements en squelettes, toasts de confirmation
- Suppression : confirmation qui nomme l'élément, ou corbeille avec annulation
- Accessibilité WCAG AA : contrastes, focus visible (anneau `--brand-500` de 2 px), navigation au clavier, `aria-*` corrects
- Animations de 150 à 200 ms, désactivées si `prefers-reduced-motion`
- Tokens prévus pour un futur mode sombre (`[data-theme="dark"]`)

## 9. Sécurité (checklist permanente)

- Requêtes SQL **toujours** préparées ; noms de tables et colonnes passés par une liste blanche
- `e()` sur toute donnée affichée ; attributs et URLs échappés selon leur contexte
- Jeton CSRF sur tout formulaire et toute requête qui modifie des données
- Cookies de session `HttpOnly`, `SameSite=Lax`, `Secure` en HTTPS ; régénération de l'ID à la connexion
- En-têtes de sécurité : `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`, `Content-Security-Policy`
- Uploads : vérification du type MIME réel, renommage, aucune exécution PHP dans `content/uploads/`
- Aucun détail d'erreur affiché en production (`APP_DEBUG=false`) ; tout est journalisé
- Pas de fonction dangereuse (`eval`, `extract` sur des données externes, `unserialize` sur des données utilisateur)

## 10. Définition de « terminé » pour une phase

Une phase est terminée quand **tous** ces points sont vrais :
- [ ] Tous les livrables du prompt sont présents
- [ ] Tous les critères d'acceptation du prompt sont satisfaits
- [ ] `composer cs:check`, `composer stan` et `composer test` passent
- [ ] La CI GitHub est verte sur ta branche
- [ ] L'application démarre avec `docker compose up` sans erreur ni warning PHP
- [ ] Les écrans livrés respectent le design system (section 8)
- [ ] `docs/hooks.md` est à jour avec les nouveaux hooks
- [ ] Le rapport `docs/phases/PHASE-NN-REPORT.md` est rédigé
- [ ] La branche est poussée (PR ouverte vers `main` si possible, sans merge) et son nom exact figure dans le rapport et à la fin de ta réponse

## 11. Ce que tu fais quand tu bloques

- Une ambiguïté mineure : tu prends la décision la plus simple et la plus cohérente avec ce document, et tu la notes dans le rapport (section « Décisions prises »).
- Un vrai blocage (contradiction, choix structurant) : tu l'écris clairement dans le rapport et dans la description de la PR, et tu avances sur le reste.

## 12. Modèle de rapport de phase

`docs/phases/PHASE-NN-REPORT.md` :

```markdown
# Phase NN — Nom

## Branche
`arena/xxxxxxxx-prime-cms` (nom exact) — lien de la PR s'il existe

## Résumé
Ce qui a été livré en quelques lignes.

## Livrables
- [x] Livrable 1 — fichiers principaux
- [x] Livrable 2 — ...

## Comment tester
Commandes et URLs exactes pour vérifier chaque fonctionnalité.

## Résultats qualité
- cs:check : OK
- stan (niveau X) : OK
- tests : N tests, N assertions, OK

## Hooks ajoutés
Liste (aussi documentés dans docs/hooks.md).

## Décisions prises
Choix faits en autonomie et pourquoi.

## Limites connues et points ouverts
Ce qui n'est pas fait, ce qui est reporté.
```
