# Phase 11 — Plugin de départ et points d'extension (v0.11.0)

- **Branche** : `arena/a57092d4-prime-cms`, écrite par Claude.
- **Version noyau** : `0.11.0` (`Prime\Kernel\Application::VERSION`).
- **Migrations du noyau** : 26 (inchangé). Le plugin apporte sa propre migration.
- **Tests** : 867 PHPUnit hors base (dont 7 dans `content/plugins/prime-starter/tests`, suite « Plugins »), 31 tests `database`, 57 tests JS.

---

## 1. Livrables

| Périmètre | Détail | État |
|---|---|---|
| Éditeurs alternatifs | `EditorRegistry` + `EditorDefinition` ; `PluginContext::editors()` (nom `{slug}` ou `{slug}-…`) ; un type de contenu accepte tout slug comme `editor` ; filtre `content.editor`. L'écran d'édition délègue le rendu au plugin ; éditeur absent ou en erreur → Prime Editor et un avertissement (l'erreur est journalisée, jamais affichée) ; blocs inconnus → bloc HTML avec le rendu enregistré. | Terminé |
| Pages publiques de plugin | `PageContext::TYPE_PLUGIN`, `PluginContext::renderPage()` : le HTML du plugin est placé dans `layouts/base.php` du thème actif ; titre et description SEO fournis par le plugin. | Terminé |
| Routes publiques gardées | `frontGroup()` refuse au démarrage un préfixe du noyau (`/admin`, `/api`, `/uploads`, `/login`…) et une méthode + chemin déjà pris. | Terminé |
| E-mails de plugin | `Mailer::render()` accepte un gabarit `{slug}::mail/…`. | Terminé |
| Désinstallation complète | `PluginDataCleaner` : capacité retirée des rôles et des jetons d'API, travaux en file du plugin, horodatages du planificateur. Appelé dans les deux chemins de `PluginManager::uninstall`. | Terminé |
| Traductions tardives | Le dossier `lang/` d'un plugin chargé après la construction du traducteur (envoi ZIP, `make:plugin`) est ajouté à la volée. | Terminé |
| Installation | L'assistant écrit `site.name`, `language.site`, `language.admin`, `general.timezone` (les clés lues par l'admin et le site) et active `plugins.default_active` (`prime-starter`). | Terminé |
| `prime-starter` | Carnet de notes Markdown : admin (liste, formulaire, suppression confirmée, état vide), réglages, page `/notes` dans le thème, API `/api/prime-starter/v1/notes`, bloc Prime Editor, bloc de zone, éditeur Markdown des articles, tâche hebdomadaire, travail en file, gabarit d'e-mail, ligne d'état du système, FR/EN, tests. | Terminé |
| `make:plugin` | Copie `prime-starter` en réécrivant slug, préfixe snake_case, namespace et nom (fichiers et noms de fichiers) ; manifeste en `0.1.0`. `--minimal` garde le squelette vide. | Terminé |
| Documentation | `docs/plugins.md` (tableau « Points d'extension », désinstallation), `docs/plugin-starter.md`, README du plugin. | Terminé |

## 2. Corrections apportées en cours de phase

- **Installateur.** Il enregistrait `site_name`, `site_locale` et `timezone`, que l'admin ne lit plus : un site fraîchement installé s'appelait « Prime CMS ». Les clés actuelles sont écrites (les anciennes restent pour compatibilité).
- **Traductions d'un plugin installé après le démarrage.** Elles s'affichaient en clés brutes (`plugin::slug.ui.title`) jusqu'à la requête suivante.
- **Désinstallation.** Les capacités du plugin restaient attachées aux rôles et aux jetons, et ses travaux en file échouaient ensuite en boucle.

## 3. Sécurité

- Le Markdown est échappé ligne par ligne avant tout ajout de balise ; seuls les liens `http(s)`, `mailto` et relatifs deviennent des liens ; le bloc repasse le résultat dans `HtmlSanitizer` (profil `basic`). Test : `<script>`, `javascript:` et `<img onerror>` restent du texte.
- Les écrans d'admin du plugin exigent `prime_starter.manage` (403 pour un contributeur, testé) ; les formulaires passent par le CSRF du noyau.
- L'API ne montre une note privée qu'à un appelant qui a la capacité (404 sinon, pour ne pas révéler son existence).
- Aucun script ni style en ligne : CSP inchangée.

## 4. Vérifications dans le navigateur

Plugin activé sur la base locale, Chromium (Playwright) :

- **Admin › Carnet** : état vide, création (titre, Markdown, couleur, épinglée, publique), liste avec pastille de couleur et badges, erreur « Donnez un titre à la note. » sous le champ et dans l'alerte (422).
- **`/notes`** : la note publique seule, rendue en Markdown dans le thème Aurora ; la note privée absente.
- **Réglages › Général** : section « Carnet ».
- **Outils** : ligne « Carnet (Prime Starter) » dans l'état du système ; tâche `prime-starter.weekly-digest` dans le planificateur.
- **Articles** avec le réglage « Écrire les articles en Markdown » : éditeur Markdown à la place de Prime Editor ; `content_json` = un bloc `prime-starter/markdown` ; publication ; HTML enregistré avec titres, gras et lien.

## 5. Points ouverts

- `make:plugin` ne propose pas encore de modules à la carte (`--with=api,admin…`) : on copie tout puis on retire.
