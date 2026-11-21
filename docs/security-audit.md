# Audit de sécurité — Prime CMS 1.0

Audit mené à la phase 12 sur le code de la version 1.0.0. Une ligne par point : comment il a été
vérifié, le résultat, et le correctif éventuel avec le test qui l'empêche de revenir.

## Synthèse

| Point | Vérifié comment | Résultat | Correctif / test |
|---|---|---|---|
| Injections SQL | Recherche dans `src/` de toute requête construite par concaténation (`select(`, `statement(`, `sprintf(`) ; revue des 14 occurrences | Les seules interpolations sont des **noms de tables** préfixés en interne (`prefixed()`), jamais une valeur. Toutes les valeurs passent par des paramètres liés (`QueryBuilder`, `?`). Les colonnes de tri de l'API et des listes sont filtrées par liste blanche (`EntryQuery::orderBy`). | — |
| XSS — vues | Recherche des `<?=` sans échappement dans `resources/views`, `content/themes`, `content/plugins` | Toutes les sorties passent par `$this->e()`, `$site->e()` ou `raw()` sur du HTML déjà filtré (contenu rendu par `HtmlSanitizer`, fil d'Ariane échappé à la construction). | — |
| XSS — éditeur et blocs | Revue de `HtmlSanitizer` et des blocs ; tests existants `HtmlSanitizerTest` | Liste blanche de balises et d'attributs, schémas d'URL filtrés. Le bloc Markdown de `prime-starter` échappe avant d'ajouter une balise puis repasse dans le filtre. | `PrimeStarterTest::testMarkdownIsEscapedBeforeAnyTagIsAdded`, `testEveryExtensionPointIsWired` (`<img onerror>`) |
| XSS — API et e-mails | Revue des ressources de l'API (JSON, `Content-Type` forcé) et des gabarits `mail/` | JSON uniquement ; les gabarits d'e-mail échappent toutes les variables. | — |
| CSRF | `VerifyCsrfToken` global ; l'API par session exige `X-Prime-CSRF` | Toute écriture admin sans jeton est refusée (419) ; l'API par jeton Bearer n'utilise pas de cookie. | `CsrfTest`, `ApiContractTest` |
| Contrôle d'accès | **Test qui parcourt toute la table des routes** (admin et API) en anonyme et en contributeur | Aucune route accessible sans la permission déclarée ; toute route d'admin sans `can:` doit figurer dans une courte liste revue (connexion, mot de passe, profil, notifications). | `RouteAccessAuditTest` (4 tests) |
| IDOR | Contributeur sur le contenu d'un autre auteur (admin et API) | Refus (403) : `content.edit_own` est vérifié sur l'auteur. Une note privée de `prime-starter` répond 404 à qui n'a pas la capacité. | `ApiContractStep3Test`, `PrimeStarterTest::testTheApiReadsPubliclyAndWritesWithTheCapability` |
| Envois de fichiers | Revue de `UploadService` (types MIME réels, extensions, double extension, SVG, ZIP de plugins) | Type détecté par `finfo`, liste blanche, renommage, `.htaccess` qui interdit l'exécution dans `content/uploads`, protection « zip slip ». | `UploadAndImageProcessorTest`, `MediaServingAndSecurityTest`, `PluginInstallerTest` |
| Redirections ouvertes | Paramètre de retour après connexion | L'URL demandée avant connexion n'est mémorisée que si c'est un chemin interne `/admin…` (`Authenticate::SESSION_INTENDED_KEY`). | — |
| En-têtes et CSP | `curl -I` sur l'admin et le site | CSP sans `unsafe-inline` pour les scripts, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`. **`X-Powered-By` était envoyé** hors Docker. | Corrigé : `Response::send()` retire l'en-tête |
| Sessions et cookies | En-têtes `Set-Cookie`, revue de `Session` | `HttpOnly`, `SameSite=Lax`, `Secure` en HTTPS, régénération à la connexion, révocation par appareil. | `SessionTest` |
| Limitation de débit | Connexion, réinitialisation du mot de passe, API | Limites par compte et par IP ; l'API refuse **avant** d'exécuter la requête (corrigé en phase 10). | `ApiContractStep4Test` |
| Secrets | Recherche des secrets dans les journaux, réponses, rapports d'état | Mots de passe et jetons jamais journalisés ; le préfixe affiché d'un jeton ne contient rien du secret ; `mail.password` chiffré en base (AES-256-GCM). | Tests des jetons (phase 10) |
| Dépendances | `composer.json` | Aucune dépendance au runtime ; Playwright et axe uniquement pour les tests de bout en bout. | — |
| Mode debug en production | `APP_ENV=production` + `APP_DEBUG=true` | **Les traces et extraits de code s'affichaient** si le debug restait actif en production. | Corrigé : `Application::showsErrorDetails()` ; `ProductionHardeningTest`. « État du système » signale toujours le réglage. |
| Disponibilité | Mesures de performance (phase 12) | **La page 404 chargeait tous les articles** (`SiteView::entries` ignorait ses arguments) et chaque article chargeait tous les articles pour « précédent / suivant » : une page coûtait plus cher à mesure que le site grossissait. | Corrigé ; `PerformanceAndDemoDatabaseTest` (nombre de requêtes constant, 404 à 3 cartes) |
| Installation | Installateur web dans un vrai navigateur | **Chaque étape répondait 500** : le CORS global lisait les réglages dès qu'un navigateur envoyait `Origin`, avant que la base existe. | Corrigé : CORS limité à `/api` ; `ApiCorsScopeTest` et la suite de bout en bout |
| Installation — fichiers publics | Suite e2e sur l'image Docker | **Le thème n'était pas publié** à l'installation : site sans CSS ni JS (et donc sans CSP utile côté scripts). | Corrigé : `Installer::publishThemeAssets()` ; job e2e |
| Routes de plugins | Revue de `PluginRouter` | Un plugin pouvait déclarer `/admin/…` ou une route déjà prise et masquer une page. | Corrigé en phase 11 ; `PluginExtensionPointsTest::testAPluginCannotClaimACorePrefixOrAnExistingRoute` |

## Procédure de signalement

Signalez une faille en privé au mainteneur du dépôt (onglet « Security » de GitHub) plutôt que dans
une issue publique. Indiquez la version, les étapes pour reproduire et l'impact.
