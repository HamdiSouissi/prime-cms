# Sécurité et authentification — Prime CMS

Ce document décrit les mécanismes de sécurité intégrés au noyau de **Prime CMS** (Phase 4 — Authentification, utilisateurs, rôles). L'ensemble repose exclusivement sur les primitives natives de PHP 8.2+ (`password_hash`, `openssl`, `hash_hmac`, `random_bytes`, `hash_equals`), sans aucune dépendance externe au runtime.

---

## 1. Mots de passe et politique de robustesse

### Service centralisé de hachage (`Prime\Security\Hasher`)
Tous les appels à `password_hash()`, `password_verify()` et `password_needs_rehash()` de l'application (`Auth`, `UserRepository`, `TwoFactorService`, `Installer`, `ProfileController`, réinitialisation de mot de passe et CLI) sont strictement centralisés dans `Prime\Security\Hasher`, enregistré comme singleton par `AuthServiceProvider` :
- **Algorithme et coûts configurables** (`config/auth.php` : clés `hash_driver`, `argon2` et `bcrypt`) :
  - Par défaut (`hash_driver: 'argon2id'`), utilise **Argon2id** (`PASSWORD_ARGON2ID`, `memory_cost: 65536` Kio, `time_cost: 4`, `threads: 1`) si disponible dans le binaire PHP, sinon bascule automatiquement sur **bcrypt** (`PASSWORD_BCRYPT`, `cost: 12`).
- **API unique** : `make(string $plain): string`, `verify(string $plain, string $hash): bool`, `needsRehash(string $hash): bool` et `dummyVerify(string $plain): void`.
- **Rehachage transparent** : à chaque connexion réussie, `Prime\Auth\Auth::attempt()` vérifie `$hasher->needsRehash($storedHash)` et met silencieusement le hash à jour en base si l'algorithme ou les options recommandées ont évolué.
- **Protection contre l'énumération de comptes par canal temporel (timing attack)** :
  - Lorsqu'une adresse e-mail est inconnue, `Auth::attempt()` appelle `$hasher->dummyVerify($password)` qui vérifie le mot de passe contre un hash factice généré avec **exactement le même algorithme et les mêmes paramètres** que `make()` (vérifié en tests via `password_get_info()`).
  - Ce hash factice est généré paresseusement une seule fois, mémoïsé pour la requête et mis en cache de façon atomique dans `storage/cache/auth-dummy-hash-<empreinte>.txt` ; l'empreinte SHA-256 des paramètres invalide automatiquement le cache dès qu'un algorithme ou un coût est modifié dans `config/auth.php`.
  - Pour un compte **désactivé**, la vérification complète du hash stocké est exécutée avant le rejet afin que la durée de réponse soit identique à celle d'un compte inexistant ou d'un mauvais mot de passe.
  - Les tests automatisés mesurent la médiane de 5 tentatives entrelacées (e-mail inexistant, e-mail existant avec mauvais mot de passe, compte désactivé) et imposent un ratio compris entre `0,6` et `1,6`.

### Politique de mots de passe (`Prime\Security\PasswordPolicy`)
Appliquée uniformément lors de l'installation (`/install` et CLI), de la création/édition d'utilisateur, du changement de mot de passe sur `/admin/profile`, de la réinitialisation par lien et de `bin/prime user:password` :
- **12 caractères minimum** (longueur UTF-8) et **128 caractères maximum** (protection contre les attaques DoS sur bcrypt/Argon2).
- Rejet des mots de passe communs via le dictionnaire local embarqué `resources/security/common-passwords.txt` (1 157 entrées courantes).
- Rejet des mots de passe contenant la partie locale de l'adresse e-mail, le nom affiché ou le nom du site (insensible à la casse).
- Exigence de diversité minimale (au moins 2 classes de caractères ou une phrase secrète longue ≥ 16 caractères).

---

## 2. Chiffrement applicatif (`Prime\Security\Encrypter`)

Les secrets stockés en base (tels que les secrets TOTP 2FA dans `pc_users.two_factor_secret`) sont chiffrés via **AES-256-GCM** authentifié :
- Clé maîtresse : `APP_KEY` au format `base64:<32 octets aléatoires>` générée lors de l'installation.
- **Nonce unique de 12 octets** (`random_bytes(12)`) à chaque chiffrement.
- **Tag d'authentification de 16 octets** vérifié avant déchiffrement (rejet immédiat en cas d'altération du payload ou de mauvaise clé via `EncryptionException`).
- Format stocké : `v1:<base64(nonce || tag || ciphertext)>`.

---

## 3. Sessions durcies et révocation instantanée

- **Régénération d'identifiant** : `Session::regenerate(true)` est systématiquement appelé après connexion réussie, validation 2FA et déconnexion (protection contre la fixation de session).
- **Double expiration** (configurable dans `config/auth.php`) :
  - **Inactivité** : 2 heures (`7200 s`) sans requête authentifiée.
  - **Durée absolue** : 12 heures (`43200 s`) après l'authentification initiale, quelle que soit l'activité.
- **Version de session (`pc_users.session_version`)** : chaque session stocke la version courante de l'utilisateur. Toute action sensible (changement de mot de passe, réinitialisation par e-mail, désactivation de compte, réinitialisation 2FA, « Déconnecter tous mes autres appareils ») incrémente `session_version`, invalidant instantanément toutes les autres sessions actives.
- **En-têtes anti-cache** : le middleware `NoStoreHeaders` (`no-store`) ajoute `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` et `Pragma: no-cache` sur toutes les pages d'authentification et d'administration afin qu'aucune donnée authentifiée ne subsiste dans le cache navigateur ou un proxy intermédiaire.

---

## 4. Cookie « Rester connecté » (`remember_tokens`)

- Durée par défaut : **30 jours**, cookie `HttpOnly`, `SameSite=Lax`, `Secure` activé automatiquement en HTTPS (`Request::isSecure()`).
- Structure **sélecteur + validateur** (`<selector 24 hex>:<validator 64 hex>`):
  - Seul `selector` est indexé en base (`pc_remember_tokens`).
  - `validator` est haché par **SHA-256** et comparé en temps constant (`hash_equals`).
- **Rotation systématique** : à chaque ré-authentification automatique, le jeton utilisé est remplacé par un nouveau couple sélecteur/validateur.
- **Détection de vol** : si un `selector` connu est présenté avec un `validator` invalide (signe probable de rejeu d'un cookie dérobé), **tous** les jetons « Rester connecté » de cet utilisateur sont immédiatement révoqués, sa session est détruite et un avertissement est journalisé.

---

## 5. Limitation de débit (`Prime\Security\RateLimiter`)

Compteur persistant en base de données (`pc_rate_limits`, fenêtre glissante) sans dépendance Redis :
- **Connexion (`POST /admin/login`)** : 5 échecs par couple `(email + IP)` et 20 échecs par `IP` sur 15 minutes → réponse **HTTP 429 Too Many Requests** avec en-tête `Retry-After` et déclenchement du hook `auth.lockout`.
- **Défi 2FA (`POST /admin/login/two-factor`)** : 5 tentatives par utilisateur et par IP sur 15 minutes.
- **Mot de passe oublié (`POST /admin/forgot-password`)** : 3 demandes par adresse e-mail et 10 par IP par heure.

---

## 6. Authentification à deux facteurs (2FA — TOTP RFC 6238)

- Implémentation native **TOTP SHA-1, 6 chiffres, pas de 30 secondes** (`Prime\Security\Totp`) avec tolérance ±1 pas (`±30 s`) pour compenser la dérive d'horloge.
- Secret Base32 (160 bits) généré côté serveur, affiché avec lien standard `otpauth://totp/...` (zéro appel réseau externe pour QR code), chiffré en AES-256-GCM en base uniquement après confirmation d'un premier code valide.
- **Protection anti-rejeu** : le numéro de pas horaire validé est persisté dans `pc_users.two_factor_last_step` ; tout code provenant d'un pas inférieur ou égal est systématiquement refusé.
- **8 codes de récupération à usage unique** (`xxxx-xxxx`, 40 bits d'entropie chacun) : affichés une seule fois à l'activation/régénération, stockés sous forme de hashs bcrypt/Argon2id individuels, détruits dès utilisation.

### Procédure de récupération d'urgence (perte d'appareil et de codes)

Un administrateur système disposant d'un accès serveur peut réinitialiser la 2FA d'un utilisateur verrouillé via la CLI :

```bash
php bin/prime user:2fa:reset utilisateur@exemple.fr
```

Cette commande efface le secret TOTP et les codes de récupération, incrémente `session_version`, révoque les cookies « Rester connecté » et émet l'événement `auth.two_factor_disabled`.

---

## 7. Réinitialisation de mot de passe et invitations

- **Anti-énumération** : `POST /admin/forgot-password` retourne toujours exactement le même message neutre (`200 OK`), que l'adresse existe ou non.
- **Jetons à usage unique** : 32 octets aléatoires (64 caractères hexadécimaux) stockés sous forme de hash **SHA-256** dans `pc_password_resets`, valables **60 minutes** (ou **48 heures** pour un lien d'invitation créé par un administrateur).
- Toute réinitialisation réussie marque le jeton comme utilisé, invalide les autres jetons en attente, met à jour `password_changed_at`, incrémente `session_version`, révoque les jetons « Rester connecté » et envoie un e-mail de notification de sécurité.
- **Messagerie (`Prime\Mail\*`)** : pilote `log` par défaut (écrit les e-mails complets dans `storage/logs/mail-YYYY-MM-DD.log` en développement), pilote `native` (`mail()`) pour les serveurs mutualisés ; sanitisation stricte anti-injection CRLF sur les en-têtes `To`, `Subject` et `From`.

---

## 8. Protection contre les redirections ouvertes et CSRF

- **Redirection après connexion (`Authenticate::isSafeAdminRedirect()`)** : seules les URL internes relatives commençant strictement par `/admin` ou `/admin/` sont acceptées ; toute URL contenant un schéma (`http://`), double barre (`//`), antislash (`\`), séquence `..`, caractère de contrôle ou boucle vers `/admin/login`/`/admin/logout` est rejetée au profit de `/admin`.
- **Déconnexion sécurisée** : uniquement via `POST /admin/logout` protégé par jeton CSRF (`_csrf_token`). Un appel `GET /admin/logout` retourne `405 Method Not Allowed`.
- **CSP stricte** : zéro `<script>` inline exécutable ni attribut `on*=` sur l'ensemble des écrans d'authentification et d'administration (`script-src 'self'`).

---

## 9. Sécurité du moteur de thèmes et du site public (Phase 8)

- **Content-Security-Policy publique dédiée (`Prime\Themes\PublicCsp`)** : `default-src 'self'; script-src 'self'; style-src 'self'; style-src-attr 'unsafe-inline'; img-src 'self' data:; font-src 'self'; media-src 'self'; connect-src 'self'; frame-src https://www.youtube-nocookie.com https://player.vimeo.com; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'` (extensible via le filtre `site.csp`). Les options de thème sont compilées dans un fichier CSS statique versionné (`options-{hash}.css`) : aucun `<style>` inline n'est injecté.
- **Isolation des templates & échappement par défaut** : `TemplateResolver::isSafeRelativeTemplate()` bloque toute traversée de répertoire (`../`, octets nuls, chemins absolus) ; `SiteView` et `EntryView` échappent systématiquement les données textuelles en HTML.
- **Installation de thèmes par ZIP (`ThemeInstaller`)** : validation stricte du type MIME ZIP, taille max (20 Mo), nombre de fichiers max (2 000), taille décompressée max (100 Mo), ratio anti-ZIP-bomb (≤ 100:1), refus des symlinks et traversées ZipSlip, validation complète de `theme.json` avant écriture atomique.
- **Isolation des erreurs fatales & mode sans échec** : `FatalLoadGuard` intercepte toute erreur fatale ou exception du thème actif et bascule automatiquement sur Aurora ; `PRIME_SAFE_MODE=true` force Aurora immédiatement.
- **Prévisualisations & Personnaliseur** : les brouillons/privés retournent `404` aux anonymes ; `/?preview=1` exige `EntryService::canEdit()`, émet `X-Robots-Tag: noindex, nofollow` et `Cache-Control: no-store` ; `/?customizer_preview=1` exige `themes.manage` et vérifie `event.origin === window.location.origin` côté client.
- **Menus & redirections** : `MenuRepository::sanitizeUrl()` rejette `javascript:`, `data:` et `vbscript:` ; `RedirectRepository` refuse les boucles et raccourcit automatiquement les chaînes.

