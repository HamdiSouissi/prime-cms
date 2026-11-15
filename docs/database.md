# Base de données, migrations et réglages — Prime CMS

Prime CMS embarque sa propre couche d'accès aux données 100 % originale basée sur **PDO natif** (`pdo_mysql`), compatible **MySQL 8.0+** et **MariaDB 10.6+**, sans ORM tiers ni dépendance runtime.

---

## 1. Configuration (`config/database.php`)

Les paramètres de connexion proviennent du fichier `.env` :

```dotenv
DB_HOST=db
DB_PORT=3306
DB_DATABASE=prime_cms
DB_USERNAME=prime
DB_PASSWORD=secret
DB_PREFIX=pc_
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
```

Garanties appliquées à chaque connexion (`Prime\Database\Connection`) :

- **Connexion paresseuse** : aucune ouverture de socket tant qu'aucune requête n'est exécutée.
- **Encodage complet** : `utf8mb4` / `utf8mb4_unicode_ci` (`SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'`).
- **Attributs PDO stricts** : `ATTR_ERRMODE => ERRMODE_EXCEPTION`, `ATTR_DEFAULT_FETCH_MODE => FETCH_ASSOC`, `ATTR_EMULATE_PREPARES => false`, `ATTR_STRINGIFY_FETCHES => false`.
- **Mode SQL strict et fuseau UTC** : `SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'` et `SET SESSION time_zone = '+00:00'`.
- **Zéro fuite de secrets** : `ConnectionException` et `QueryException` masquent systématiquement le mot de passe et le DSN complet. En mode `APP_DEBUG=false`, les requêtes et bindings ne sont jamais exposés dans les messages d'exception.

---

## 2. `Connection` & transactions imbriquées

```php
use Prime\Database\Connection;

/** @var Connection $db */
$rows = $db->select('SELECT id, email FROM ' . $db->prefixTable('users') . ' WHERE status = ?', ['active']);
$user = $db->selectOne('SELECT * FROM ' . $db->prefixTable('users') . ' WHERE id = ?', [1]);
$count = (int) $db->selectValue('SELECT COUNT(*) FROM ' . $db->prefixTable('users'));

// Transactions imbriquées par SAVEPOINT
$db->transaction(function (Connection $db): void {
    $db->table('settings')->insert([...]);

    try {
        $db->transaction(function (Connection $db): void {
            // Exécuté dans SAVEPOINT prime_sp_2
            throw new RuntimeException('Annule uniquement ce bloc');
        });
    } catch (RuntimeException) {
        // La transaction externe poursuit normalement
    }
});
```

Chaque requête déclenche le hook `db.query` avec `(string $sql, array $bindings, float $durationMs)` et est journalisée en mémoire (`$db->queryLog()`) lorsque `APP_DEBUG=true`.

---

## 3. `QueryBuilder` & `Expression::raw()`

Toutes les valeurs passent obligatoirement par des **bindings préparés** ; tous les identifiants (tables, colonnes, alias) sont validés par `Prime\Database\Identifier` et entourés de backticks. Le préfixe configuré (`pc_`) est appliqué automatiquement.

```php
use Prime\Database\Query\Expression;

// Lecture, jointures, filtres, pagination
$page = $db->table('articles')
    ->select(['articles.id', 'articles.title', 'authors.name AS author_name'])
    ->join('authors', 'articles.author_id', '=', 'authors.id')
    ->where('articles.status', 'published')
    ->whereNotNull('articles.published_at')
    ->orderBy('articles.published_at', 'DESC')
    ->paginate(page: 1, perPage: 20);

// Agrégats et expressions brutes explicites
$stats = $db->table('articles')
    ->select(['status', Expression::raw('COUNT(*) AS total')])
    ->groupBy('status')
    ->having('total', '>=', 5)
    ->get();

// Écritures
$id = $db->table('users')->insertGetId([
    'email' => 'camille@example.fr',
    'password_hash' => $hash,
    'display_name' => 'Camille',
    'status' => 'active',
    'locale' => 'fr',
    'created_at' => gmdate('Y-m-d H:i:s'),
    'updated_at' => gmdate('Y-m-d H:i:s'),
]);

$db->table('articles')->where('id', 42)->increment('views');

// Protection anti-suppression globale : delete() sans where() lève une QueryException
// sauf appel explicite à ->all()
$db->table('tmp_tokens')->where('expires_at', '<', gmdate('Y-m-d H:i:s'))->delete();
$db->table('tmp_tokens')->all()->delete();
```

---

## 4. `Schema` & `Blueprint`

`Prime\Database\Schema\Schema` et `Blueprint` produisent du DDL InnoDB / `utf8mb4_unicode_ci` compatible MySQL 8.0+ et MariaDB 10.6+ :

```php
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

$schema->create('entries', static function (Blueprint $table): void {
    $table->id();
    $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
    $table->string('slug')->unique();              // VARCHAR(191) sûr en utf8mb4
    $table->string('title', 255);
    $table->longText('body')->nullable();
    $table->boolean('published')->default(false)->index();
    $table->json('meta')->nullable();
    $table->timestamps();                          // created_at & updated_at DATETIME UTC
});

$schema->table('entries', static function (Blueprint $table): void {
    $table->dateTime('published_at')->nullable()->after('published');
    $table->index(['published', 'published_at'], 'idx_entries_pub');
});
```

---

## 5. Migrations (`database/migrations/`)

- Nommage strict : `YYYY_MM_DD_HHMMSS_description_en_snake_case.php`.
- Chaque fichier retourne une classe anonyme étendant `Prime\Database\Migrations\Migration` avec `up(Schema $schema)` et `down(Schema $schema)`.
- Suivi dans `pc_migrations` (`id`, `migration` unique, `batch`, `ran_at` UTC).
- Verrouillage anti-concurrence via MySQL `GET_LOCK` / `RELEASE_LOCK`.
- Arrêt immédiat sur échec d'une migration en conservant l'historique exact des migrations précédentes réussies.
- Extensible par les plugins via le filtre `migrations.paths`.

### Tables initiales du noyau (Phase 3)

1. **`pc_settings`** (`2026_11_15_000001_create_settings_table.php`) : `id`, `name` (unique), `value` (`LONGTEXT` JSON), `autoload` (`TINYINT(1)` indexé), `created_at`, `updated_at`.
2. **`pc_users`** (`2026_11_15_000002_create_users_table.php`) : `id`, `email` (unique), `password_hash`, `display_name`, `status` (défaut `active`, indexé), `locale` (défaut `fr`), `last_login_at` (nullable), `created_at`, `updated_at`.

---

## 6. Service `Settings` & helper `setting()`

```php
use Prime\Settings\Settings;

// Enregistrement typé (JSON : string, int, float, bool, array, null)
$settings->set('site_name', 'Atelier Prime', autoload: true);
$settings->set('items_per_page', 20, autoload: true);
$settings->set('heavy_cache', ['...'], autoload: false);

// Lecture via le helper global (tous les réglages autoload=true sont chargés en 1 seule requête SQL)
$name = setting('site_name', 'Prime CMS');
```

Toute modification via `Settings::set()` déclenche l'événement `settings.updated`.

---

## 7. Commandes CLI (`bin/prime`)

```bash
php bin/prime db:check                          # Teste la connexion et affiche la version SQL
php bin/prime migrate                           # Exécute les migrations en attente
php bin/prime migrate:status                    # Tableau d'état de chaque migration
php bin/prime migrate:rollback --step=1         # Annule les N derniers lots
php bin/prime migrate:reset                     # Annule toutes les migrations exécutées
php bin/prime migrate:fresh                     # Recrée le schéma (refusé en production sans --force)
php bin/prime make:migration create_posts_table # Génère une migration horodatée
php bin/prime settings:get site_name            # Lit la valeur JSON d'un réglage
php bin/prime settings:set site_name '"Démo"'   # Enregistre un réglage typé JSON (--no-autoload optionnel)
```
