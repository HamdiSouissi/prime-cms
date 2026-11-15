# Utilisateurs, rôles et permissions (RBAC) — Prime CMS

Prime CMS implémente un contrôle d'accès basé sur les rôles et les permissions granulaires (**RBAC**). Un utilisateur peut cumuler **plusieurs rôles** ; ses permissions effectives correspondent à l'union de l'ensemble des permissions de ses rôles.

---

## 1. Les 4 rôles système préinstallés

Créés automatiquement par la migration `2026_11_15_000006_seed_default_roles.php` :

| Rôle | Slug | Système | Permissions |
|---|---|---|---|
| **Administrateur** | `administrator` | Oui (lecture seule) | Wildcard `*` — toutes les permissions présentes et futures |
| **Éditeur** | `editor` | Oui | Tableau de bord, gestion complète de tous les contenus (y compris publication et suppression) et gestion complète de la médiathèque |
| **Auteur** | `author` | Oui | Tableau de bord, création/édition/publication/suppression de ses propres contenus, téléversement et suppression de ses propres médias |
| **Contributeur** | `contributor` | Oui | Tableau de bord, consultation, création et édition de ses propres brouillons (sans publication ni téléversement de médias) |

> Les rôles système ne peuvent pas être supprimés. Le rôle **Administrateur** est en lecture seule afin d'empêcher tout retrait accidentel de privilèges.

---

## 2. Catalogue complet des permissions (`Prime\Auth\Capabilities`)

| Domaine | Clé de permission | Libellé |
|---|---|---|
| **Tableau de bord** | `dashboard.view` | Accéder au tableau de bord |
| **Contenus** | `content.view` | Voir les contenus |
| | `content.create` | Créer des contenus |
| | `content.edit_own` | Modifier ses propres contenus |
| | `content.edit_any` | Modifier tous les contenus |
| | `content.publish` | Publier des contenus |
| | `content.delete_own` | Supprimer ses propres contenus |
| | `content.delete_any` | Supprimer tous les contenus |
| **Médias** | `media.view` | Consulter la médiathèque |
| | `media.upload` | Téléverser des médias |
| | `media.delete_own` | Supprimer ses propres médias |
| | `media.delete_any` | Supprimer tous les médias |
| **Apparence** | `appearance.manage` | Gérer les thèmes, menus et widgets |
| **Extensions** | `plugins.manage` | Installer, activer et configurer les plugins |
| **Utilisateurs & rôles** | `users.view` | Voir la liste des utilisateurs |
| | `users.create` | Créer des utilisateurs |
| | `users.edit` | Modifier des utilisateurs |
| | `users.delete` | Supprimer des utilisateurs |
| | `roles.manage` | Gérer les rôles et permissions |
| **Réglages & système** | `settings.manage` | Modifier les réglages généraux du site |
| | `system.logs` | Consulter les journaux système |
| | `system.maintenance` | Activer le mode maintenance et les outils système |

### Déclarer des permissions depuis un plugin

Un plugin peut déclarer de nouvelles permissions via le filtre `auth.capabilities` :

```php
$hooks->filter('auth.capabilities', static function (array $groups): array {
    $groups['Boutique'] = [
        'shop.orders.view' => 'Consulter les commandes',
        'shop.orders.refund' => 'Rembourser une commande',
    ];

    return $groups;
});
```

Les permissions ainsi déclarées apparaissent automatiquement dans la matrice de l'écran `/admin/roles/create` et `/admin/roles/{id}/edit`, et sont utilisables avec le middleware de route `can:shop.orders.view` ou `$auth->can('shop.orders.view')`.

---

## 3. Protection des routes et filtrage de l'interface

- **Middleware `auth`** (`Prime\Auth\Middleware\Authenticate`) : vérifie la session ou le cookie « Rester connecté », applique `NoStoreHeaders` (`Cache-Control: no-store`), redirige les invités vers `/admin/login` (en mémorisant l'URL souhaitée sécurisée) ou renvoie `401 JSON` pour les requêtes `Accept: application/json`.
- **Middleware `can:<capability>`** (`Prime\Auth\Middleware\Authorize`) : vérifie `$auth->can($capability)` et renvoie `403 Forbidden` (page `errors/403.php` ou JSON) en cas de refus.
- **Filtrage dynamique** : la barre latérale (`AdminMenu::filterByCapability()`), le menu « Créer », la palette de commandes (`Ctrl/⌘ + K`) et les raccourcis du tableau de bord masquent automatiquement toute entrée dont l'utilisateur courant ne détient pas la permission.
- **Styleguide (`/admin/styleguide`)** : doublement protégé par `can:*` (administrateur uniquement) **et** `APP_ENV=local` (`404` hors environnement local).

---

## 4. Invariants de sécurité (`Prime\Auth\UserGuard`)

Appliqués de manière identique dans les contrôleurs web **et** dans la CLI :
1. **Dernier administrateur actif** : impossible de supprimer, désactiver ou retirer le rôle `administrator` au dernier compte administrateur actif du site.
2. **Auto-protection** : un utilisateur connecté ne peut pas supprimer son propre compte, se désactiver, ni se retirer son propre rôle `administrator`.
3. **Anti-escalade de privilèges** : un utilisateur disposant des permissions `users.create`/`users.edit`/`users.delete` sans être lui-même administrateur (`*`) ne peut ni attribuer le rôle `administrator`, ni modifier/désactiver/supprimer/réinitialiser la sécurité d'un compte administrateur.
4. **Intégrité des rôles** : un rôle personnalisé assigné à au moins un utilisateur ne peut pas être supprimé tant que les utilisateurs concernés n'ont pas été réassignés.

---

## 5. Écrans d'administration

- **`/admin/users`** : liste paginée, recherche plein texte (nom / e-mail), filtres par rôle et statut, tri par colonne, badges rôles/statut/2FA, date relative de dernière connexion, actions unitaires et actions groupées (activer / désactiver / supprimer).
- **`/admin/users/create`** : création avec mot de passe immédiat **ou** envoi d'un lien d'invitation par e-mail valable 48 heures.
- **`/admin/users/{id}/edit`** : édition profil/rôles/mot de passe, panneau sécurité (envoi de lien de réinitialisation, déconnexion de tous les appareils, réinitialisation 2FA, suppression).
- **`/admin/roles`** : liste des rôles avec compteurs de permissions et d'utilisateurs ; création et édition avec matrice de permissions groupées par domaine et bouton « Tout cocher / Tout décocher ».
- **`/admin/profile`** : accessible à tout utilisateur connecté, composé de deux onglets (`Informations` et `Sécurité`) : nom, e-mail (confirmation par mot de passe actuel), langue, changement de mot de passe (déconnecte les autres appareils), activation/gestion complète de la 2FA TOTP avec copie/téléchargement `.txt` des codes de récupération, et déconnexion de tous les autres appareils.

---

## 6. Commandes CLI (`bin/prime`)

```bash
# Lister les rôles, permissions et nombre d'utilisateurs
php bin/prime role:list

# Lister les utilisateurs (filtres optionnels --role, --status, --search)
php bin/prime user:list
php bin/prime user:list --role=administrator --status=active

# Créer un utilisateur (mot de passe généré automatiquement si --password est omis)
php bin/prime user:create --email=alice@exemple.fr --name="Alice Martin" --role=editor
php bin/prime user:create --email=bob@exemple.fr --name="Bob Durand" --role=author,contributor --password="MonMotDePasse#2026"

# Réinitialiser le mot de passe et révoquer toutes les sessions actives
php bin/prime user:password alice@exemple.fr --generate
php bin/prime user:password alice@exemple.fr --password="NouveauMotDePasse#2026"

# Modifier les rôles (remplacement par défaut, ou --add / --remove)
php bin/prime user:role alice@exemple.fr administrator --add
php bin/prime user:role alice@exemple.fr contributor --remove
php bin/prime user:role alice@exemple.fr editor

# Désactiver / réactiver un compte (respecte la protection du dernier administrateur)
php bin/prime user:disable alice@exemple.fr
php bin/prime user:enable alice@exemple.fr

# Réinitialisation d'urgence de la 2FA
php bin/prime user:2fa:reset alice@exemple.fr
```
