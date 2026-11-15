# Rapport de phase — Phase 4 : Authentification, utilisateurs, rôles

- **Projet** : Prime CMS (`HamdiSouissi/prime-cms`)
- **Phase** : 4 / 15 — Authentification, utilisateurs, rôles
- **Branche** : `arena/8a25419c-prime-cms`
- **Date** : 15 novembre 2026

---

## 1. Résumé des livrables (L0 – L14)

| Livrable | Statut | Détails |
|---|---|---|
| **L0 — Finitions assistant d'installation** | Livré | 4 ajustements de finition issus de la revue Phase 3 sur l'assistant `/install` + recompilation de `admin.css`. |
| **L1 — Migrations sécurité & RBAC** | Livré | `Blueprint::unsignedInteger()`, `Schema::connection()`, 4 nouvelles migrations (`000003` colonnes auth/2FA sur `pc_users`, `000004` tables `pc_roles`/`pc_role_capabilities`/`pc_user_roles` avec FK `ON DELETE CASCADE`, `000005` tables `pc_remember_tokens`/`pc_password_resets`/`pc_rate_limits`, `000006` pré-remplissage des 4 rôles système) ; attribution automatique du rôle `administrator` au premier compte dans `Installer`. |
| **L2 — Primitives sécurité, validation & messagerie** | Livré | `config/auth.php`, `config/mail.php`, `Encrypter` (AES-256-GCM authentifié), `RateLimiter` (persistant en base), `PasswordPolicy` (12–128 car., dictionnaire local `resources/security/common-passwords.txt` de 1 157 mots de passe, contexte), `Totp` (RFC 6238 natif SHA-1/6 chiffres/30 s + `otpauth://`), `Validator` (zéro dépendance, messages français), couche `Prime\Mail\*` (`Message`, `MailerInterface`, `LogMailer`, `NativeMailer`, `MailServiceProvider`, templates `resources/views/mail/*`). |
| **HTTP — Cookies, 403, 429 & alias paramétrés** | Livré | API cookies immuable sur `Response` (`withCookie`, `withoutCookie`, `cookie`), `Request::isSecure()`, `ForbiddenException` (403 + `errors/403.php`), `TooManyRequestsException` (429 + `Retry-After`), support des middlewares paramétrés (`can:<capability>`) dans `Http\Kernel`. |
| **L3–L6 — Moteur `Prime\Auth\*`** | Livré | `Capabilities` (22 permissions en 6 domaines + filtre `auth.capabilities`), modèles `Role`/`User`, dépôts `RoleRepository`/`UserRepository`, garde d'invariants `UserGuard`, `TwoFactorService` (AES-256-GCM, anti-rejeu `two_factor_last_step`, 8 codes de récupération hachés), `PasswordResetService` (SHA-256, 60 min / 48 h invitation), service `Auth` (temps constant, rehachage transparent, timeouts 2 h/12 h, `session_version`, rotation/détection de vol `remember_tokens`, 7 hooks sécurité), middlewares `Authenticate`, `Authorize`, `NoStoreHeaders` et `AuthServiceProvider` paresseux. |
| **L7 — Remplacement d'`AdminPreviewGate` & shell RBAC** | Livré | Suppression de `AdminPreviewGate` ; ajout de `AdminMenu::filterByCapability()` ; `AdminContext` alimenté par l'utilisateur réel, les permissions filtrées (sidebar, menu « Créer », palette de commandes) et les toasts flash (`#prime-flash-toasts`) ; `DashboardController` filtrant tuiles/raccourcis et verrouillant `/admin/styleguide` sur `can:*` + `APP_ENV=local` ; déconnexion par `POST /admin/logout` avec CSRF. |
| **L8 — Pages d'authentification** | Livré | Écran scindé fidèle au design system (`resources/assets/admin/css/components/auth.css`, module ES2022 `public/assets/admin/js/auth.js`), 6 vues (`layout`, `login`, `two-factor`, `forgot-password`, `reset-password`, `reset-expired`) et `AuthController`. Zéro script inline, `Cache-Control: no-store`. |
| **L9 — Gestion des utilisateurs (`/admin/users`)** | Livré | `UserController` + 3 vues (`index`, `create`, `edit`) : pagination, recherche, filtres rôle/statut, tri, actions groupées, création directe ou par lien d'invitation 48 h, édition profil/rôles/mot de passe et panneau d'actions sécurité (reset mot de passe, déconnexion globale, reset 2FA, suppression). |
| **L10 — Gestion des rôles (`/admin/roles`)** | Livré | `RoleController` + 2 vues (`index`, `form`) : CRUD complet des rôles personnalisés, matrice de permissions groupées avec « Tout cocher / décocher », génération automatique du slug, protection en lecture seule du rôle `administrator`, interdiction de suppression des rôles système ou encore assignés. |
| **L11 — Profil & sécurité (`/admin/profile`)** | Livré | `ProfileController` + vue à onglets (`Informations` & `Sécurité`) : édition profil (vérification mot de passe sur changement d'e-mail), changement de mot de passe (révocation des autres appareils), enrôlement/régénération/désactivation 2FA TOTP avec copie et téléchargement `.txt` des codes de récupération, déconnexion de tous les autres appareils. |
| **L12 — 8 commandes CLI (`bin/prime`)** | Livré | `role:list`, `user:list`, `user:create`, `user:password`, `user:role`, `user:disable`, `user:enable`, `user:2fa:reset` — toutes respectant `PasswordPolicy` et les invariants `UserGuard`. |
| **L13 — Tests unitaires, intégration, MySQL/MariaDB & Docker** | Livré | 181 tests au total (unitaires sécurité/validation/mail/auth + intégration HTTP complète `AdminDashboardTest`, `AuthFlowTest`, `UsersAndRolesCrudTest` + 11 tests `#[Group('database')]` incluant `AuthDatabaseIntegrationTest` exécutés sur MySQL 8.0 & MariaDB 10.6) ; smoke test Docker Compose mis à jour pour tester la vraie connexion CSRF + cookie jar sur `/admin`, `/admin/users`, `/admin/roles`, `/admin/profile`, `/admin/styleguide` et la CLI. |
| **L14 — Documentation** | Livré | Création de `docs/security.md`, `docs/users-and-roles.md`, `docs/phases/PHASE-04-REPORT.md` ; mise à jour de `docs/hooks.md` et `README.md`. |

---

## 2. Décisions d'architecture et sécurité clés

1. **Isolation stricte des routes publiques et paresse du conteneur** : `AuthServiceProvider` maintient un indicateur `$authInstantiated` afin que le filtre global `http.response` (émission des cookies en attente) n'instancie jamais `Auth`, `Encrypter` ou `Connection` sur des routes publiques comme `/health` ou `/install`.
2. **Détection de vol sur les cookies « Rester connecté »** : la séparation `selector` (24 hex indexé) + `validator` (64 hex haché SHA-256) avec rotation à chaque utilisation permet de détecter instantanément un rejeu de cookie dérobé et de révoquer l'ensemble des sessions persistantes de l'utilisateur.
3. **Invariants centralisés dans `UserGuard`** : les règles critiques (protection du dernier administrateur actif, auto-protection contre l'auto-suppression/auto-désactivation, prévention de l'escalade de privilèges) sont centralisées dans un service unique consommé à la fois par les contrôleurs HTTP et par les commandes CLI.
4. **Anti-rejeu TOTP sans bibliothèque tierce** : le pas de 30 secondes validé lors de la confirmation ou d'un défi 2FA est enregistré dans `pc_users.two_factor_last_step`, empêchant la réutilisation d'un même code TOTP pendant la fenêtre de validité.
5. **Routes `{module}` explicitement bornées** : restreindre la route générique des modules en attente aux slugs connus (`pages|articles|media|appearance|plugins|settings`) empêche toute interception accidentelle de routes `POST`-only comme `GET /admin/logout` (qui retourne bien `405 Method Not Allowed`).

---

## 3. Points de passage pour la Phase 5 (Système de plugins)

- Les plugins disposent désormais de points d'extension complets pour s'intégrer proprement dans l'administration sécurisée :
  - Déclarer leurs permissions via le filtre **`auth.capabilities`**.
  - Protéger leurs routes via les middlewares **`auth`** et **`can:<capability>`** (et `no-store` pour les écrans sensibles).
  - Annoter leurs entrées de menu `MenuItem(..., capability: '...')` dans **`admin.menu`** afin que la barre latérale et la palette de commandes se filtrent automatiquement selon le rôle connecté.
  - Écouter les événements de cycle de vie sécurité (**`auth.login`**, **`auth.logout`**, **`auth.failed`**, **`auth.lockout`**, **`auth.password_reset`**, **`auth.two_factor_enabled`**, **`auth.two_factor_disabled`**).
- L'entrée `/admin/plugins` (`plugins.manage`) et son écran d'attente sont prêts à accueillir le gestionnaire de plugins de la Phase 5.
