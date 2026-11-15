# Guide d'installation — Prime CMS

Prime CMS s'installe en **moins de 2 minutes** via son assistant web (`/install`) ou en une seule commande non interactive (`php bin/prime install`).

---

## 1. Détection de l'état installé

Prime CMS considère qu'une instance est **installée** si et seulement si **deux conditions** sont réunies simultanément (`Prime\Install\InstallChecker`) :

1. Le fichier `storage/installed.lock` existe (horodatage UTC + version installée).
2. Le réglage `installed_at` est présent dans la table `pc_settings`.

Comportement du middleware `RedirectIfNotInstalled` :

- **Avant installation** : toute requête autre que `/install*`, `/health` et les fichiers statiques existants dans `public/` redirige en `302` vers `/install`.
- **Après installation** : toutes les routes `/install*` (GET et POST) retournent **`404 Not Found`** de manière définitive (à l'exception de la page de confirmation `/install/done` affichée une seule fois immédiatement après l'étape 6, puis immédiatement verrouillée en `404`).

---

## 2. Assistant d'installation web (`/install`)

L'assistant guide l'utilisateur en **7 étapes** protégées par jeton **CSRF** (`VerifyCsrfToken`), avec état stocké uniquement en session (jamais dans les URL ni en clair dans le DOM) et purgé dès la fin de l'installation :

1. **Bienvenue (`/install`)** : choix de la langue de l'assistant (`fr`, `en`, `it`).
2. **Prérequis (`/install/requirements`)** : contrôle automatique de PHP ≥ 8.2, des extensions requises (`pdo_mysql`, `mbstring`, `json`, `gd`, `fileinfo`, `openssl`, `zip`), de l'extension recommandée (`intl`, avertissement non bloquant) et des droits d'écriture sur `storage/`, `storage/{cache,logs,sessions,tmp}` et `content/uploads`. Affiche une instruction de correction précise par échec et un bouton « Revérifier ».
3. **Base de données (`/install/database`)** : hôte, port, nom de base, utilisateur, mot de passe (avec bouton afficher/masquer) et préfixe validé (`/^[a-z][a-z0-9_]{0,15}_$/`). Propose un bouton « Tester la connexion », la création optionnelle de la base si l'utilisateur dispose du privilège, et refuse d'écraser des tables existantes portant le même préfixe.
4. **Site (`/install/site`)** : nom du site, URL publique (préremplie depuis la requête courante), langue du site et fuseau horaire (défaut `Europe/Paris`).
5. **Administrateur (`/install/admin`)** : nom affiché, e-mail, mot de passe et confirmation avec jauge de robustesse en temps réel (minimum 12 caractères, rejet des mots de passe communs ou contenant le nom du site / l'e-mail).
6. **Installation (`/install/run`)** : récapitulatif complet **sans aucun mot de passe affiché**, puis exécution séquentielle via `Prime\Install\Installer` :
   - génération d'`APP_KEY` (`base64:` + 32 octets aléatoires `random_bytes(32)`) et écriture du fichier `.env` (`APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `DB_*`, permissions `0640`, commentaires et clés externes préservés) ;
   - exécution des migrations du noyau (`pc_settings`, `pc_users`) ;
   - création du compte administrateur avec hachage `PASSWORD_ARGON2ID` (repli `PASSWORD_BCRYPT`) ;
   - enregistrement des réglages initiaux (`site_name`, `site_url`, `site_locale`, `timezone`, `installed_at`, `prime_version`) ;
   - écriture finale de `storage/installed.lock` (uniquement si toutes les étapes précédentes ont réussi) et émission du hook `install.completed`.
7. **Terminé (`/install/done`)** : écran de confirmation à usage unique avec liens vers le site public (`/`) et le tableau de bord (`/admin`).

### Repli lorsque `.env` n'est pas inscriptible

Si le serveur web ne possède pas les droits d'écriture à la racine du projet, l'étape 6 **n'échoue pas brutalement** : elle affiche le contenu exact du fichier `.env` dans un bloc copiable en un clic, puis permet de poursuivre l'installation dès que le fichier a été créé manuellement sur le serveur (pensez à appliquer `chmod 640 .env`).

---

## 3. Installation non interactive en CLI (`bin/prime install`)

Idéale pour Docker, CI/CD ou le provisionnement automatisé — utilise **strictement le même service** `Prime\Install\Installer` que l'assistant web :

```bash
php bin/prime install \
  --db-host=db \
  --db-port=3306 \
  --db-name=prime_cms \
  --db-user=prime \
  --db-password=secret \
  --db-prefix=pc_ \
  --site-name="Mon site Prime CMS" \
  --site-url=https://monsite.fr \
  --site-locale=fr \
  --timezone=Europe/Paris \
  --admin-name="Camille Laurent" \
  --admin-email=admin@monsite.fr \
  --admin-password="Une-Phrase-Secrete-Solide!2026"
```

Options supplémentaires :

- `--create-db` : tente `CREATE DATABASE IF NOT EXISTS` en `utf8mb4_unicode_ci` avant les migrations.
- `--skip-env` : ne modifie pas le fichier `.env` (utile lorsque toute la configuration est injectée par variables d'environnement).
- `--app-env=local --app-debug` : surcharge `APP_ENV` / `APP_DEBUG` dans `.env` (par défaut `production` / `false`).

---

## 4. Dépannage

- **Erreur de droits sur `storage/`** : exécuter `chmod -R 775 storage content/uploads` et vérifier le propriétaire (`www-data` sous Apache/Docker).
- **Erreur `419` lors d'une soumission** : vérifier que `storage/sessions` est accessible en écriture et que les cookies sont autorisés par le navigateur.
- **Réinstaller en environnement local** : supprimer `storage/installed.lock` puis exécuter `php bin/prime migrate:reset` (ou `migrate:fresh`) avant de rouvrir `/install`.
