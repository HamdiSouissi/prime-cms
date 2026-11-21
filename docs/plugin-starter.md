# Prime Starter — le plugin de départ

`content/plugins/prime-starter/` est actif après l'installation. C'est un vrai plugin (un carnet de
notes en Markdown) et c'est aussi le modèle copié par `make:plugin`. Il utilise chaque point
d'extension de Prime CMS, une section numérotée par point dans `src/Plugin.php`.

## Ce qu'il fait

| Où | Quoi |
|----|------|
| **Admin › Carnet** (`/admin/prime-starter`) | liste, création, modification, suppression avec confirmation ; état vide ; erreurs de saisie affichées sous les champs |
| **Réglages › Général › Carnet** | page publique activée ou non, son titre, éditeur Markdown pour les articles, e-mail du résumé hebdomadaire |
| **Site** (`/notes`) | les notes publiques, dans le thème actif (en-tête, menus, pied de page, balises SEO) |
| **API** (`/api/prime-starter/v1/notes`) | lecture publique des notes publiques ; création, modification et suppression avec la capacité `prime_starter.manage` |
| **Prime Editor** | bloc « Markdown » (`prime-starter/markdown`) |
| **Apparence › Zones** | bloc dynamique « Notes du carnet » |
| **Articles** | éditeur Markdown à la place de Prime Editor quand le réglage est actif |
| **Outils › Planificateur** | tâche `prime-starter.weekly-digest` (lundi 8 h) qui met en file `prime-starter.digest` |
| **E-mail** | gabarit `prime-starter::mail/digest` |
| **Outils › État du système** | ligne « Carnet (Prime Starter) » |

## Créer son plugin

```bash
php bin/prime make:plugin acme-shop --name="Acme Shop" --author="Acme"
php bin/prime plugin:activate acme-shop
vendor/bin/phpunit content/plugins/acme-shop/tests
```

La commande copie `prime-starter` et réécrit, dans les fichiers comme dans leurs noms :

| Dans prime-starter | Dans la copie |
|--------------------|---------------|
| `prime-starter` (slug, URL, clés `plugin::…`, nom de l'éditeur, tâches) | `acme-shop` |
| `prime_starter` (capacité, table) | `acme_shop` |
| `PrimeStarter` (namespace PHP) | `AcmeShop` (ou `--namespace=`) |
| `Prime Starter` (nom affiché) | `Acme Shop` |

Le manifeste repart en version `0.1.0` et exige la version courante de Prime CMS. Les tests copiés
passent tels quels : gardez-les verts en retirant ce dont vous n'avez pas besoin.

`--minimal` produit l'ancien squelette (`plugin.json`, `src/Plugin.php`, `README.md`) ; c'est aussi
ce que fait la commande si `prime-starter` a été supprimé.

## Les règles que le starter respecte

- **Nommage** : tout ce que le plugin déclare porte son slug (`{slug}.…` pour les tâches et
  travaux, `{slug}-…` pour un éditeur, `{slug_snake}.…` pour une capacité, `plugin.{slug}.…` pour
  un réglage). C'est ce qui permet au noyau de tout retrouver à la désinstallation.
- **Échappement** : les vues échappent tout avec `$this->e()`. Le Markdown est converti par
  `PrimeStarter\Markdown`, qui échappe chaque ligne avant d'ajouter une balise et n'accepte que les
  liens `http(s)`, `mailto` et relatifs ; le bloc repasse le résultat dans `HtmlSanitizer`.
- **CSP** : aucun script ni style en ligne ; tout est dans `assets/` et publié par le noyau.
- **Écritures** : formulaires protégés par le CSRF du noyau ; API soumise aux jetons, au CORS et
  aux limites de débit de `/api`.
- **Désinstallation** : la migration crée la seule table du plugin ; le reste (réglages, capacité,
  travaux, horodatages) est nettoyé par le noyau.

## Fichiers

```text
prime-starter/
├── plugin.json
├── src/
│   ├── Plugin.php              # les 11 points d'extension
│   ├── NoteRepository.php      # accès à la table, validation
│   ├── Markdown.php            # conversion sûre
│   ├── Controller/NotesController.php
│   ├── Api/NotesApi.php
│   └── Jobs/DigestJob.php
├── views/                      # admin/index, admin/form, admin/editor, public, mail/digest
├── assets/                     # css/admin.css, css/public.css, js/markdown-editor.js, js/markdown-block.js
├── lang/{fr,en}/messages.php
├── migrations/
└── tests/PrimeStarterTest.php
```
