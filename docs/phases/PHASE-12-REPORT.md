# Phase 12 — Finalisation v1.0 (v1.0.0)

- **Branche** : `arena/a57092d4-prime-cms`, écrite par Claude.
- **Version** : `1.0.0` (`Prime\Kernel\Application::VERSION`). Le tag `v1.0.0` est créé après la
  fusion dans `main`.
- **Migrations du noyau** : 27 (`000027` : index de listes).
- **Tests** : 881 PHPUnit (dont 34 qui demandent une base, lancés par les jobs `database` sur
  MySQL 8.0 et MariaDB 10.6), 57 tests JS, 13 tests de bout en bout Playwright (dont l'audit axe),
  dans les 8 jobs de la CI.

---

## L1. Performance

Jeu de données : `php bin/prime demo:install --posts=1000 --media=300 --terms=50` (1 032 contenus,
310 médias, 51 termes). Mesure : `php tests/perf/measure.php --runs=15`, requêtes traitées par le
noyau HTTP dans le processus (temps serveur, sans réseau), médiane de 15 requêtes, PHP 8.3, MariaDB.

| Page | Avant : sans cache (ms) | Avant : requêtes | Après : sans cache (ms) | Après : avec cache (ms) | Après : requêtes |
|---|---|---|---|---|---|
| Accueil | 9,6 | 43 | 6,4 | 0,1 | 20 |
| Article | 85,7 | 86 | 21,8 | 0,1 | 44 |
| Archive (catégorie) | — ¹ | — | 14,0 | 0,1 | 26 |
| Page 404 | 158,9 | 23 | 8,9 | — ² | 21 |
| Blog | 16,1 | 45 | 12,9 | 0,2 | 25 |
| API `/api/v1/content/post` | 3,9 | 5 | 4,6 | — | 5 |
| Tableau de bord | 20,9 | 35 | 16,1 | — | 20 |
| Liste des contenus | 21,9 | 29 | 15,7 | — | 19 |
| Médiathèque | 13,3 | 20 | 11,7 | — | 17 |

¹ L'URL mesurée au départ était fausse (`/category/` au lieu de `/categorie/`) : c'est elle qui a
révélé la lenteur de la page 404. ² Une 404 n'est pas mise en cache.

**Objectifs** : page publique depuis le cache < 30 ms (0,1 à 0,2 ms) ; sans cache < 150 ms (22 ms
au plus) ; aucune page d'admin > 25 requêtes (20 au plus) ; pas de N+1 — **atteints**.

**Corrections**
- « Précédent / suivant » d'un article chargeait **tous** les articles du type, puis leurs méta :
  requête bornée à 10 voisins autour de la date.
- `SiteView::entries()` ignorait type, limite et tri (`EntryQuery` est immuable et le résultat des
  appels était perdu) : la page 404 rendait 1 000 cartes au lieu de 3.
- Menus : une requête par élément pointant un contenu ou un terme → une requête par type.
- Zones du thème : lues une fois par requête (cinq lectures de la barre latérale avant).
- Rôles : la liste (3 requêtes) était relue à chaque hydratation d'utilisateurs → mémorisée,
  oubliée à chaque écriture.
- Médias sur les pages publiques et les vignettes : sans le nom de l'auteur ni le compte
  d'utilisations (4 requêtes de moins par lecture).
- Tableau de bord : statistiques d'e-mails en une requête groupée, auteurs récents chargés par
  identifiant, nombre d'utilisateurs par `COUNT`.
- Index : `entries(type, updated_at)`, `entries(type, created_at)`, `jobs(handler)`.
- Déjà en place et vérifié : CSS d'administration minifié avec empreinte `?v=`, en-têtes
  `Cache-Control: immutable` sur les assets (`public/.htaccess`), images publiques en
  `loading="lazy"` avec dimensions.

**Test** : `PerformanceAndDemoDatabaseTest::testQueryCountsDoNotGrowWithTheNumberOfEntries`
mesure 9 pages (publiques, API, admin) avant et après l'ajout de 25 articles : le nombre de
requêtes doit être identique, et ≤ 25 sur l'admin.

## L2. Sécurité

Rapport complet : [`docs/security-audit.md`](../security-audit.md). Failles corrigées, chacune avec
son test :

| Faille | Test |
|---|---|
| L'installateur web répondait 500 à chaque étape dans un navigateur (CORS global qui lisait les réglages dès qu'un en-tête `Origin` était présent) | `ApiCorsScopeTest`, suite e2e `01-install` |
| Détails d'erreur (traces, code) affichés en production si `APP_DEBUG` restait actif | `ProductionHardeningTest` |
| Coût d'une page proportionnel à la taille du site (404, article) | `PerformanceAndDemoDatabaseTest` |
| `X-Powered-By` envoyé hors Docker | (en-tête retiré dans `Response::send()`) |

**Table des routes** : `RouteAccessAuditTest` parcourt toutes les routes de l'admin (anonyme →
connexion, contributeur → 403 sur chaque route dont il n'a pas la permission, plus de 50 routes) et
toutes les routes protégées de l'API (anonyme → 401, contributeur → 403). Une route d'admin sans
permission déclarée fait échouer le test tant qu'elle n'est pas ajoutée, sciemment, à l'une des
deux listes courtes (connexion et mot de passe ; profil et notifications).

## L3. Accessibilité

axe-core 4.10 via Playwright, règles WCAG 2.0/2.1/2.2 A et AA, échec sur toute violation
**sérieuse ou critique** :

- installateur : 7 écrans (bienvenue → terminé) ;
- administration : connexion, tableau de bord, liste et édition d'articles, médiathèque, réglages,
  état du système, mises à jour, profil, plugins ;
- Aurora : accueil, blog, article, page, catégorie, recherche, 404.

**Résultat : 0 violation sérieuse ou critique.** Corrigé pour y arriver : bouton « Inutilisés » de
la médiathèque sans nom (balise mal fermée : le texte était devenu un attribut), grille ARIA de la
médiathèque sans `row`, cases à cocher de 15 px (24 px), contraste du badge corail (4,26 → 5,7) et
des liens corail sur fond rosé ; dans Aurora, le corail des liens, étiquettes et éléments actifs
(4,33 sur fond teinté) passe par `--aurora-accent-text`, une version assombrie de la couleur
d'accent, valable quelle que soit la couleur choisie dans le Personnaliseur.

Parcours clavier : décrits dans le [guide de l'administrateur](../user-guide.md#accessibilité-et-clavier)
(publication d'un article, envoi d'un média, création d'un jeton d'API, menu mobile d'Aurora). Le
menu mobile est vérifié par la suite e2e (ouverture à `Entrée`, fermeture à `Échap`, focus rendu
au bouton).

## L4. Tests de bout en bout

`tests/e2e/` (Playwright 1.63, Chromium), lancé par le job **End-to-end & accessibility** sur
l'installation Docker (`docker compose up`), captures et traces conservées en artefact en cas
d'échec :

1. installation web complète, avec le contenu de démonstration coché ;
2. connexion refusée avec un mauvais mot de passe ;
3. écrans d'admin (axe) ;
4. article avec image envoyée depuis le sélecteur, publié, rendu sur le site ;
5. menu et recherche sur le site ;
6. réglage modifié visible sur le site ;
7. langue de l'interface (anglais puis français) ;
8. jeton d'API créé dans le profil, appel `/api/v1/me` (200) et sans jeton (401) ;
9. `prime-starter` désactivé (`/notes` → 404) puis réactivé ;
10. mise à jour depuis **Outils › Mises à jour** ;
11. 2FA : activation, puis connexion avec un code TOTP ;
12. pages d'Aurora (axe) ; 13. menu mobile au clavier.

Les tests ont trouvé, en plus des points d'accessibilité :
- l'installateur en 500 (ci-dessus) ;
- **l'e-mail d'administration non enregistré par l'installateur**, qui rendait **Réglages ›
  Général** impossible à enregistrer sur un site neuf (champ obligatoire vide) ;
- **les fichiers publics du thème jamais publiés à l'installation** (`public/assets/themes/` n'est
  pas versionné) : un site neuf s'affichait sans feuille de style ni script. Le premier passage du
  job en CI l'a montré ; localement, des fichiers déjà publiés le masquaient.

## L5. Mises à jour

`php bin/prime update [--check] (--backup-done | --backup)` et **Outils › Mises à jour**
(`settings.manage`) : sauvegarde confirmée ou `mysqldump` dans `storage/backups/`, maintenance,
migrations du noyau puis des plugins actifs, fichiers publics, caches, version, fin de la
maintenance (ou maintien si elle était active). Journal de chaque étape.
Test : `UpdaterDatabaseTest` monte une base **0.9** (migrations jusqu'à `000025`), vérifie le refus
sans sauvegarde, puis la mise à niveau complète. Guide : [`docs/upgrade.md`](../upgrade.md).

## L6. Contenu de démonstration

`demo:install [--posts --media --terms]` / `demo:remove` : 3 pages (accueil devenue page d'accueil,
à propos, contact), articles avec image à la une, catégories, étiquettes, menus principal et pied
de page, zones. Images **générées par GD** (compositions abstraites, aucune photo). Tout est listé
dans le réglage `demo.manifest` ; le retrait supprime exactement cela et restaure les menus qui
occupaient les emplacements, les zones et les réglages de lecture remplacés. Option **décochée**
à la dernière étape de l'installateur.
Test : `PerformanceAndDemoDatabaseTest::testDemoContentIsInstalledThenRemovedWithoutTrace`
(comptes de 9 tables et fichiers identiques avant et après, emplacement de menu restauré).

## L7. Documentation et version

- Nouveau : [`user-guide.md`](../user-guide.md), [`upgrade.md`](../upgrade.md),
  [`security-audit.md`](../security-audit.md), [`CHANGELOG.md`](../../CHANGELOG.md) (phases 1 à 12).
- README à jour (version, commandes, tests e2e et perf, index de la documentation).
- `DocumentationLinksTest` vérifie chaque lien relatif et chaque ancre du README, du CHANGELOG,
  de `docs/` et des README de plugins.

## Points ouverts

- Les temps sont mesurés dans le processus PHP (sans serveur web ni réseau) : ils comparent les
  versions entre elles, pas un temps de réponse perçu.
- Le thème Aurora publie son CSS tel quel (29 Ko, compressé par le serveur) ; seul le CSS
  d'administration est minifié.
- La page d'article reste à 44 requêtes (articles liés, précédent, suivant, menus, zones) : stable
  quel que soit le volume, et servie depuis le cache en 0,1 ms.
