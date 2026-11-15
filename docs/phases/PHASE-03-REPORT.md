# Rapport de phase — Phase 3 : Base de données et installateur

- **Projet** : Prime CMS (`HamdiSouissi/prime-cms`)
- **Phase** : 3 / 15 — Base de données et installateur
- **Branche** : `arena/f78d27df-prime-cms`
- **Date** : 15 novembre 2026

---

## 1. Résumé des livrables (L1 – L12)

| Livrable | Statut | Détails |
|---|---|---|
| **L1 — `Connection` & `config/database.php`** | Livré | Connexion PDO paresseuse (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_PREFIX=pc_`, `utf8mb4` / `utf8mb4_unicode_ci`), attributs PDO stricts (`ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES=false`, `STRINGIFY_FETCHES=false`), `sql_mode` strict, `time_zone='+00:00'`, transactions imbriquées par `SAVEPOINT`, log mémoire en debug + hook `db.query`, exceptions `ConnectionException` / `QueryException` expurgées de tout secret. |
| **L2 — `QueryBuilder` & `Expression::raw()`** | Livré | Requêteur fluide (`select`, `distinct`, `from`, `join`/`leftJoin`/`rightJoin`, `where`/`orWhere`/`whereIn`/`whereNotIn`/`whereNull`/`whereNotNull`/`whereBetween`/`whereRaw` + groupes imbriqués, `groupBy`, `having`, `orderBy`, `limit`, `offset`, `get`, `first`, `value`, `pluck`, `exists`, `count`/`sum`/`avg`/`min`/`max`, `paginate`, `insert` simple & lot, `insertGetId`, `update`, `increment`/`decrement`, `delete` bloquant l'absence de `where` sans `->all()`). Validation stricte et échappement par backticks via `Prime\Database\Identifier`, préfixe automatique. |
| **L3 — `Schema` & `Blueprint`** | Livré | DDL complet (`create`, `table`, `drop`, `dropIfExists`, `hasTable`, `hasColumn`, `rename`), types de colonnes (`id`, `string(191)`, `char`, `text`/`mediumText`/`longText`, `integer`/`bigInteger`/`tinyInteger`/`unsignedBigInteger`, `boolean`, `decimal`, `json`, `date`, `dateTime`, `timestamp`, `timestamps` UTC `DATETIME`, `foreignId`), modificateurs (`nullable`, `default`, `unsigned`, `unique`, `index`, `after`, `comment`), contraintes FK (`constrained`, `cascadeOnDelete`, `nullOnDelete`, `restrictOnDelete`), opérations `ALTER` et inspecteur `toSql()`. Moteur `InnoDB`, `utf8mb4_unicode_ci`. |
| **L4 — Système de migrations** | Livré | Classe abstraite `Migration`, dépôt `MigrationRepository` (table `pc_migrations`), `Migrator` (`run`, `rollback`, `reset`, `fresh`, `status`), filtre `migrations.paths`, verrouillage distribué MySQL `GET_LOCK`/`RELEASE_LOCK`, arrêt sur échec sans effacer les migrations déjà réussies. |
| **L5 — Migrations initiales du noyau** | Livré | `database/migrations/2026_11_15_000001_create_settings_table.php` (`pc_settings`) et `database/migrations/2026_11_15_000002_create_users_table.php` (`pc_users`). |
| **L6 — Service `Settings` & `setting()`** | Livré | `Prime\Settings\Settings` (`get`, `set`, `has`, `delete`, `all`), typage JSON transparent, préchargement en 1 seule requête SQL des clés `autoload=true`, cache mémoire, événement `settings.updated`, `SettingsServiceProvider` et helper global `setting()`. |
| **L7 — `Session` & `Csrf` (+ `VerifyCsrfToken`)** | Livré | `Prime\Http\Session` paresseux (`prime_session`, `storage/sessions/`, `HttpOnly`, `SameSite=Lax`, `Secure` si HTTPS, mode mémoire isolé en CLI, `regenerate`, `flash`, `getFlash`) ; `Prime\Security\Csrf` (`random_bytes(32)`, `hash_equals`), middleware post-routage `VerifyCsrfToken` (HTTP 419 sur POST/PUT/PATCH/DELETE invalides, vue dédiée `errors/419.php`), `$this->csrfField()`/`$this->csrfToken()` et helpers `csrf_token()`/`csrf_field()`. |
| **L8 — Assistant d'installation web (`/install`)** | Livré | Détection double (`storage/installed.lock` + réglage `installed_at`) via `InstallChecker` ; garde `RedirectIfNotInstalled` (redirection `/install` avant installation sauf `/install*`, `/health` et fichiers statiques `public/` ; `404` définitif après installation sauf confirmation unique `/install/done`) ; service partagé `Installer`, `EnvWriter` (`APP_KEY` `base64:`+32B, `0640`, préservation commentaires/clés, repli lecture seule copiable), `RequirementsChecker`, `InstallerValidator`, `DatabaseTester` ; 7 écrans accessibles utilisant le design system Phase 2 (`install.css` compilé dans `admin.css`), module ES2022 `install.js` (jauge de robustesse, afficher/masquer mot de passe, validation au blur, focus 1re erreur, copie `.env`), zéro script inline, `data-prime-*` uniquement. |
| **L9 — Commandes CLI (`bin/prime`)** | Livré | 10 nouvelles commandes : `db:check`, `migrate`, `migrate:rollback [--step=N]`, `migrate:status`, `migrate:reset`, `migrate:fresh` (refusé en production sans `--force`, confirmation interactive sauf `--no-interaction`), `make:migration <name>`, `settings:get <name>`, `settings:set <name> <json>`, `install` (déléguant au même `Installer`). |
| **L10 — `.env.example` & Docker** | Livré | Variables `DB_*` activées dans `.env.example` et alignées sur `docker-compose.yml` ; `docker/php/entrypoint.sh` étendu pour rendre `storage/` inscriptible par `www-data` (`installed.lock`) ; `.php-cs-fixer.dist.php` étendu à `database/`. |
| **L11 — Tests & CI réelle MySQL/MariaDB** | Livré | Tests unitaires (`Database/*`, `SettingsTest`, `SessionTest`, `CsrfTest`, `Install/*`, `DatabaseCommandsTest`) + 9 tests d'intégration `#[Group('database')]` (`DatabaseIntegrationTest`, `InstallerIntegrationTest`) exécutés en CI avec `--group database --fail-on-skipped` sur **MySQL 8.0 (PHP 8.2 & 8.3)** et **MariaDB 10.6 (PHP 8.3)**, plus smoke test Docker Compose d'installation complète de bout en bout. |
| **L12 — Documentation** | Livré | Création de `docs/database.md`, `docs/install.md` et `docs/phases/PHASE-03-REPORT.md` ; mise à jour de `docs/hooks.md` et `README.md`. |

---

## 2. Décisions d'architecture et sécurité

1. **Détection d'installation fiable (`InstallCheckerInterface`)** : l'implémentation de production exige la présence conjointe de `storage/installed.lock` et du réglage `installed_at` en base, prévenant les états semi-installés. Les tests HTTP existants (`HttpKernelTest`, `AdminDashboardTest`) injectent un stub anonyme en conteneur sans aucune porte dérobée en production.
2. **CSRF post-routage (`route_middleware`)** : `VerifyCsrfToken` s'exécute dans `Http\Kernel::dispatchRoute()` après la résolution de route. Ainsi `POST /health` continue de renvoyer `405 Method Not Allowed` et une URL inconnue `404 Not Found`, tandis que toute soumission POST/PUT/PATCH/DELETE vers une route valide sans jeton CSRF renvoie `419` (page dédiée `errors/419.php` ou JSON).
3. **Échappement exact de `.env`** : correction de `Prime\Kernel\Env::parseDoubleQuoted()` pour décoder `\$` en `$`, garantissant un aller-retour identique à l'octet près pour tout mot de passe contenant `#`, `"`, `\` ou `$` écrit par `EnvWriter`.
4. **Zéro fuite de secrets** : les mots de passe DB et admin, `APP_KEY` et le DSN complet ne sont jamais imprimés dans les exceptions, logs, sorties CLI (`db:check`, `install`) ni dans le HTML/inputs cachés de l'assistant (stockage exclusivement en session PHP puis purge complète au terme de l'installation).
5. **Périmètre respecté** : aucune logique de connexion, déconnexion, gestion de sessions utilisateurs ou RBAC n'a été introduite (strictement réservé à la Phase 4).

---

## 3. Points de passage pour la Phase 4 (Authentification, utilisateurs, rôles)

- La table `pc_users` (`email` unique, `password_hash` Argon2id/bcrypt, `display_name`, `status`, `locale`, `last_login_at`, `timestamps`) et le premier compte administrateur sont déjà en place et prêts à être consommés par le système d'authentification.
- Les services `Prime\Http\Session` et `Prime\Security\Csrf` (avec `VerifyCsrfToken` et `$this->csrfField()`) sont opérationnels et directement réutilisables pour les écrans `/admin/login`, `/admin/logout` et les formulaires d'administration.
- `AdminPreviewGate` sera remplacé en Phase 4 par le middleware d'authentification basé sur `Session` et `pc_users`.
- Les tables de rôles/capacités (`pc_roles`, `pc_role_user`, etc.) seront ajoutées via de nouvelles migrations dans `database/migrations/` et l'attribution automatique du rôle administrateur au premier utilisateur créé pourra être rattachée au hook `install.completed` ou à une migration dédiée.
