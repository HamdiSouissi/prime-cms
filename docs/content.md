# Gestion des contenus — Prime CMS

Le moteur de contenus de **Prime CMS** (Phase 6, `v0.6.0`) unifie pages, articles et tout type de contenu personnalisé (CPT) autour d'une architecture 100 % modulaire, strictement typée en PHP 8.2+ et extensible par les plugins sans jamais modifier le noyau.

---

## 1. Architecture et tables SQL

Six tables composent le socle de persistance (migrations `000010` à `000014`) :

| Table | Rôle | Contraintes et index notables |
|-------|------|-------------------------------|
| `pc_entries` | Tous les contenus (`page`, `post`, CPT) | `UNIQUE (type, parent_id, slug)`, `FULLTEXT (title, excerpt, content_text)`, index `(type, status, published_at)`, `author_id` `ON DELETE SET NULL`. |
| `pc_entry_meta` | Métadonnées et champs personnalisés typés (JSON) | `UNIQUE (entry_id, meta_key)`, index `(meta_key, entry_id)`, `entry_id` `ON DELETE CASCADE`. |
| `pc_revisions` | Historique des révisions + brouillon `autosave` | Index `(entry_id, is_autosave, created_at)`, `entry_id` `ON DELETE CASCADE`, `author_id` `ON DELETE SET NULL`. |
| `pc_terms` | Termes de taxonomies (catégories, étiquettes, custom) | `UNIQUE (taxonomy, slug)`, index `(taxonomy, parent_id)`. Seed initial : catégorie protégée « Non classé » (`non-classe`). |
| `pc_entry_terms` | Association N-N entre contenus et termes | `PRIMARY KEY (entry_id, term_id)`, `ON DELETE CASCADE` sur les deux clés étrangères. |
| `pc_entry_locks` | Verrous d'édition coopératifs (TTL 120 s par défaut) | `PRIMARY KEY (entry_id)`, `ON DELETE CASCADE` sur `entry_id` et `user_id`. |

---

## 2. Types de contenus (CPT)

Prime CMS enregistre nativement deux types de contenus :
- **`page`** : hiérarchique (`parent_id`, `menu_order`, `template`), icône `file-text`, accent `brand`, sans taxonomies par défaut.
- **`post`** : non hiérarchique, icône `newspaper`, accent `violet`, taxonomies `category` et `tag`.

Tout plugin peut enregistrer ses propres CPT via `$context->contentTypes()->register(new ContentType(...))` :

```php
use Prime\Content\ContentType;

$context->contentTypes()->register(new ContentType(
    name: 'testimonial',
    labels: [
        'singular' => 'Témoignage',
        'plural' => 'Témoignages',
        'add_new' => 'Nouveau témoignage',
        'edit' => 'Modifier le témoignage',
    ],
    icon: 'quote',
    accent: 'teal',
    hierarchical: false,
    public: true,
    supports: ['title', 'editor', 'excerpt', 'revisions', 'custom_fields'],
    taxonomies: ['testimonial_source'],
    menuPosition: 34,
));
```

Un CPT enregistré reçoit automatiquement :
- son entrée dans le menu latéral d'administration, le menu « Créer » et la palette `Ctrl/⌘+K` ;
- son jeu complet d'écrans back-office (`/admin/content/{type}`, `/create`, `/{id}/edit`, `/{id}/revisions`, `/{id}/preview`) ;
- sa visibilité dans `php bin/prime content:types`, `content:list` et `content:create`.

---

## 3. Taxonomies et termes

Prime CMS fournit nativement :
- **`category`** : hiérarchique, associée à `post`, avec terme par défaut protégé `non-classe` (« Non classé ») impossible à supprimer.
- **`tag`** : plate, associée à `post`, saisie libre par chips avec création automatique à la volée.

Les plugins déclarent des taxonomies via `$context->taxonomies()->register(new Taxonomy(...))`. Chaque taxonomie dispose automatiquement de son écran CRUD `/admin/taxonomies/{taxonomy}` (split-screen formulaire + arbre hiérarchique avec compteur d'usages) ainsi que d'un endpoint AJAX de création rapide dans la barre latérale de l'éditeur.

---

## 4. Champs personnalisés (Custom Fields)

`FieldRegistry` permet d'attacher des groupes de champs (`FieldGroup`, position `main` ou `sidebar`) à un ou plusieurs CPT. Douze types sont pris en charge nativement et validés par `FieldValidator` :

`text`, `textarea`, `number`, `email`, `url`, `date`, `datetime`, `select`, `checkbox`, `toggle`, `color`, `entry` (relation vers un autre contenu avec autocomplétion asynchrone sur `/admin/content/search-entries`).

```php
use Prime\Content\Fields\Field;
use Prime\Content\Fields\FieldGroup;

$context->fields()->registerGroup(new FieldGroup(
    id: 'mon-plugin.details',
    title: 'Détails produit',
    contentTypes: ['product'],
    position: 'sidebar',
    priority: 10,
    fields: [
        new Field(name: 'sku', type: 'text', label: 'Référence SKU', required: true),
        new Field(name: 'price', type: 'number', label: 'Prix (€)', options: ['min' => 0, 'step' => 0.01]),
        new Field(name: 'featured', type: 'toggle', label: 'Mis en avant', default: false),
        new Field(name: 'related_page', type: 'entry', label: 'Page liée', options: ['content_types' => ['page']]),
    ],
));
```

---

## 5. Cycle de vie, `EntryService` et `EntryQuery`

### Statuts et transitions

| Statut | Description |
|--------|-------------|
| `draft` | Brouillon non public (créable par tout utilisateur ayant `content.create`). |
| `pending` | En attente de relecture éditoriale. |
| `published` | Publié (requiert `content.publish`). |
| `scheduled` | Planifié dans le futur (`published_at > NOW()`). Publié automatiquement par le planificateur paresseux (throttlé par `CONTENT_SCHEDULER_INTERVAL`) ou la commande CLI `content:publish-scheduled`. |
| `private` | Privé (requiert `content.publish`). |
| `trash` | Corbeille logique : conserve `previous_status` et `trashed_at` pour restauration exacte, purgeable après `CONTENT_TRASH_RETENTION_DAYS` jours (30 par défaut). |

### Service métier `EntryService`

Toute écriture passe par `Prime\Content\EntryService` :
- contrôle RBAC (`content.view`, `content.create`, `content.edit_own`, `content.edit_any`, `content.publish`, `content.delete_own`, `content.delete_any`, `content.unfiltered_html`) ;
- génération de slug unique par `(type, parent_id)` avec protection des slugs racine réservés aux routes du noyau (`admin`, `install`, `health`, `assets`, …) ;
- validation et rendu serveur des blocs Prime Editor (`content`, `content_html`, `content_text`) ;
- création automatique de révisions plafonnées à `CONTENT_REVISIONS_MAX` (25 par défaut) et autosave dédié (max 1 par auteur et contenu) ;
- verrous coopératifs (`EntryLockRepository`, TTL 120 s, battement 30 s, reprise forcée protégée CSRF pour éditeurs/administrateurs) ;
- émission des hooks `content.saving`, `content.created`, `content.updated`, `content.published`, `content.trashed`, `content.restored`, `content.deleted`.

### Requête fluide `EntryQuery`

```php
/** @var Prime\Content\EntryQuery $query */
$result = $query->newQuery()
    ->type('post')
    ->published()
    ->whereTerm('category', 'tutoriels')
    ->whereMeta('score', 80, '>=')
    ->search('architecture')
    ->orderBy('published_at', 'DESC')
    ->paginate(page: 1, perPage: 20);
```

- Sur MySQL/MariaDB, `search()` exécute une recherche `FULLTEXT` (`MATCH ... AGAINST ... IN BOOLEAN MODE`) avec repli `LIKE` automatique sur les mots courts (< 3 caractères) et en tests SQLite.
- `whereMeta()` applique automatiquement `CAST(meta_value AS DECIMAL(20,6))` pour les comparaisons numériques (`>`, `>=`, `<`, `<=`) afin d'éviter le tri lexicographique.
- Hydratation groupée (meta, termes, auteurs) en nombre constant de requêtes (0 N+1).

---

## 6. Configuration (`.env` / `config/content.php`)

| Variable | Défaut | Description |
|----------|--------|-------------|
| `CONTENT_REVISIONS_MAX` | `25` | Nombre maximal de révisions conservées par contenu (hors autosaves). |
| `CONTENT_AUTOSAVE_INTERVAL` | `30` | Intervalle d'autosave en secondes dans l'éditeur. |
| `CONTENT_LOCK_TTL` | `120` | Durée de validité d'un verrou d'édition en secondes. |
| `CONTENT_LOCK_HEARTBEAT` | `30` | Fréquence de rafraîchissement du verrou par l'éditeur. |
| `CONTENT_SCHEDULER_INTERVAL` | `60` | Intervalle minimal entre deux passes automatiques de publication planifiée. |
| `CONTENT_TRASH_RETENTION_DAYS` | `30` | Rétention par défaut pour `content:purge-trash`. |

---

## 7. Commandes CLI

```bash
php bin/prime content:types
php bin/prime content:list [--type=post] [--status=published] [--search=...] [--limit=20]
php bin/prime content:create --type=post --title="Titre" [--status=draft] [--slug=...] [--author=email] [--body="..."]
php bin/prime content:publish-scheduled
php bin/prime content:purge-trash [--days=30]
php bin/prime content:rerender [--type=post]
```
