# Journal des versions

Toutes les versions de Prime CMS. Le détail de chaque phase (livrables, tests, captures) est dans
[`docs/phases/`](docs/phases/). Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/)
et les numéros [SemVer](https://semver.org/lang/fr/).

## [1.0.2] — 2026-11-21

Correctifs de sécurité signalés par la revue du plugin Prime Builder.

### Sécurité
- **Jetons d'API** : les capacités effectives d'un jeton sont désormais l'intersection de celles
  du jeton et de celles que le compte possède **au moment de l'appel**. Retirer un rôle retire
  immédiatement le droit correspondant à tous ses jetons ; un `*` stocké dans un jeton n'est plus
  jamais interprété comme un accès administrateur.
- **Réglages** : la capacité déclarée par une section (`'capability'`, par défaut
  `settings.manage`) est appliquée par **Réglages** (section masquée, enregistrement ignoré) et par
  l'API (`GET` sans les champs non publics de la section, `PATCH` refusé en 403).

## [1.0.1] — 2026-11-21

Points d'extension demandés par le plugin Prime Builder (constructeur de pages).

### Ajouté
- Filtre `site.partial` (`?string $html`, `string $name`, `PageContext $context`, `array $data`) :
  un plugin peut remplacer un partial du thème, par exemple `header` ou `footer`, en renvoyant une
  chaîne ; toute autre valeur garde le partial du thème.

### Corrigé
- Le corps d'un contenu n'est plus réévalué comme contenant des blocs dynamiques parce que son JSON
  contient le mot « dynamic » : la détection se fait sur les types de blocs décodés (`dynamic` ou un
  type enregistré comme dynamique). Le HTML enregistré d'un bloc de plugin reste ainsi affiché quand
  le plugin est désactivé.

## [1.0.0] — 2026-11-21

Première version stable. Phase 12 : finalisation ([rapport](docs/phases/PHASE-12-REPORT.md)).

### Ajouté
- `php bin/prime update` et **Outils › Mises à jour** : sauvegarde confirmée ou `mysqldump`,
  maintenance, migrations du noyau et des plugins, fichiers publics, caches ([guide](docs/upgrade.md)).
- `demo:install` / `demo:remove` : pages, articles, images générées, catégories, étiquettes, menus
  et zones, retirés sans trace ; option décochée à la dernière étape de l'installateur.
- Suite de bout en bout Playwright (`tests/e2e/`) et audit axe-core (WCAG 2.2 A/AA) sur
  l'installateur, l'administration et le thème Aurora, dans un 8ᵉ job de CI.
- Test qui parcourt toute la table des routes admin et API ; [audit de sécurité](docs/security-audit.md).
- Mesures de performance (`tests/perf/measure.php`) et test de comptage des requêtes SQL.
- [Guide de l'administrateur](docs/user-guide.md) ; test automatique des liens de la documentation.

### Corrigé
- Installateur web : chaque étape répondait 500 dans un navigateur (le CORS lisait les réglages
  avant que la base existe) ; `site.admin_email` n'était pas enregistré, ce qui bloquait
  **Réglages › Général** ; les fichiers publics des thèmes n'étaient pas publiés (site neuf sans
  feuille de style ni script).
- Pages lentes sur un gros site : « précédent / suivant » chargeait tous les articles, la page 404
  affichait tous les articles au lieu de trois ; requêtes en double sur les menus, les zones, les
  rôles et les médias. Index ajoutés (migration `000027`).
- Détails d'erreur visibles en production si `APP_DEBUG` restait actif ; en-tête `X-Powered-By`.
- Médiathèque : bouton « Inutilisés » sans texte, grille ARIA incomplète, cases trop petites ;
  contrastes du badge et des liens corail ; Aurora : contraste des liens et étiquettes avec la
  couleur d'accent par défaut.

## [0.11.0] — 2026-11-21

Phase 11 : plugin de départ et points d'extension ([rapport](docs/phases/PHASE-11-REPORT.md)).
- `prime-starter`, actif après l'installation : carnet de notes Markdown qui utilise chaque point
  d'extension. `make:plugin` le copie sous un nouveau nom.
- Éditeurs de contenu alternatifs, pages publiques de plugin dans le thème, routes publiques
  gardées, gabarits d'e-mail de plugin, désinstallation complète.

## [0.10.0] — 2026-11-18

Phase 10 : API REST ([rapport](docs/phases/PHASE-10-REPORT.md)).
- `/api/v1` : contenus, termes, médias, utilisateurs, réglages, menus, site, profil ; jetons
  d'API par compte avec capacités, CORS, limites de débit, ETag, document OpenAPI.
- API des plugins (`$context->api()`), **Outils › API**, onglet « Jetons d'API » du profil.

## [0.9.0] — 2026-11-17

Phase 9 : réglages, langues, cache, e-mails et tâches ([rapport](docs/phases/PHASE-09-REPORT.md)).
- Registre des réglages (Général, Sécurité, E-mails), interface FR/EN, cache de données et de
  pages, envoi SMTP natif avec journal, planificateur et file de tâches, **Outils**, 2FA obligatoire
  par rôle et QR code d'activation.

## [0.8.0]

Phase 8 : thèmes et rendu du site ([rapport](docs/phases/PHASE-08-REPORT.md)).
- Thèmes avec héritage, hiérarchie de templates, Personnaliseur, thème Aurora, permaliens,
  redirections, menus, zones, SEO, sitemaps et `robots.txt`.

## [0.7.0]

Phase 7 : médiathèque ([rapport](docs/phases/PHASE-07-REPORT.md)).
- Envoi sécurisé, variantes GD et WebP, point focal, dossiers, sélecteur de médias.

## [0.6.0]

Phase 6 : contenus et Prime Editor ([rapport](docs/phases/PHASE-06-REPORT.md)).
- Types de contenus, taxonomies, champs personnalisés, révisions, verrous, éditeur par blocs JSON.

## [0.5.0]

Phase 5 : système de plugins ([rapport](docs/phases/PHASE-05-REPORT.md)).
- Hooks (actions, filtres), manifeste, dépendances SemVer, isolation des erreurs, installation ZIP.

## [0.4.0]

Phase 4 : authentification, utilisateurs, rôles ([rapport](docs/phases/PHASE-04-REPORT.md)).
- Argon2id, sessions durcies, 2FA TOTP, rôles et permissions, limitation de débit.

## [0.3.0]

Phase 3 : base de données et installateur ([rapport](docs/phases/PHASE-03-REPORT.md)).
- Couche PDO, `QueryBuilder`, migrations, réglages, assistant d'installation web et CLI.

## [0.2.0]

Phase 2 : design system et coque du tableau de bord ([rapport](docs/phases/PHASE-02-REPORT.md)).

## [0.1.0]

Phase 1 : fondations du noyau ([rapport](docs/phases/PHASE-01-REPORT.md)) — routeur, conteneur,
autoloader, configuration, journal, CLI.
