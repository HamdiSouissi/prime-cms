# API REST de Prime CMS

L'API REST donne accès aux contenus, aux termes, aux médias, aux comptes, aux réglages et aux menus d'un site Prime CMS. Elle est servie sous `/api/v1`. Chaque plugin peut ajouter ses propres routes sous `/api/{plugin}/v1`.

La description machine (OpenAPI 3.1) de tout ce qui est accessible à l'appelant est publiée sur `GET /api/v1/openapi.json`.

## Sommaire

1. [Authentification](#1-authentification)
2. [Format des réponses](#2-format-des-réponses)
3. [Erreurs](#3-erreurs)
4. [Pagination, filtres et champs](#4-pagination-filtres-et-champs)
5. [Concurrence et cache](#5-concurrence-et-cache)
6. [Limites, CORS et réglages](#6-limites-cors-et-réglages)
7. [Ressources](#7-ressources)
8. [Routes des plugins](#8-routes-des-plugins)
9. [Configuration du serveur web](#9-configuration-du-serveur-web)
10. [Administration](#10-administration)

## 1. Authentification

Il existe trois façons d'appeler l'API.

| Mode | Comment | Ce qui est autorisé |
|---|---|---|
| Anonyme | Aucun en-tête | Lecture des contenus publiés, des termes publics, des menus, du site et des réglages publics (si `api.public_read` est activé). |
| Jeton | `Authorization: Bearer pcat_xxxxxxxx_…` | Les capacités du compte **∩** celles du jeton. |
| Session | Cookie de l'administration | Les capacités du compte. Toute écriture exige l'en-tête `X-Prime-CSRF` (le jeton CSRF de la page). |

**Créer un jeton :**
- Dans **Mon profil › Jetons d'API**. Le jeton n'est affiché qu'une seule fois.
- En ligne de commande :

  ```bash
  php bin/prime api:token:create redaction@exemple.fr --name="Site vitrine" --abilities=content.view,content.create --days=90
  php bin/prime api:token:list --user=redaction@exemple.fr
  php bin/prime api:token:revoke 12
  ```

**Durée de vie :** sans durée précisée, un jeton vit le maximum autorisé (`api.token_max_days`, 365 jours par défaut). La valeur `0` lève la limite.

**Ce qui est stocké :** seule l'empreinte SHA-256 du jeton est conservée, jamais le jeton lui-même.

**Ce qui est refusé :**
- un jeton passé dans l'URL (`?access_token=`) est refusé avec une erreur 400 ;
- un jeton invalide, révoqué ou expiré, ou appartenant à un compte désactivé, est refusé avec une erreur 401.

**Révocation :** les jetons d'un compte peuvent être révoqués depuis :
- son profil ;
- sa fiche dans **Utilisateurs** ;
- **Outils › API REST** ;
- le changement de mot de passe (case cochée par défaut).

## 2. Format des réponses

Toutes les réponses sont en JSON.

```json
{
  "data": [ { "id": 12, "title": "Bonjour", "_links": { "self": "https://exemple.fr/api/v1/content/post/12" } } ],
  "meta": { "page": 1, "per_page": 20, "total": 42, "total_pages": 3 },
  "links": { "self": "…", "first": "…", "prev": null, "next": "…?page=2", "last": "…?page=3" }
}
```

- Une ressource porte des liens `_links` :
  - `self` et `collection` ;
  - `author` et `terms` pour un contenu ;
  - `edit_url` seulement si l'appelant peut modifier la ressource.
- Les dates sont en UTC, au format `AAAA-MM-JJ HH:MM:SS`.
- L'adresse e-mail d'un auteur n'est jamais exposée dans les contenus.

## 3. Erreurs

Toutes les erreurs partagent la même forme. Le message est rédigé dans la langue du compte appelant, à défaut dans celle du site.

```json
{ "error": { "code": "validation_failed", "message": "Certains champs sont invalides.", "status": 422, "fields": { "title": "Le titre est obligatoire." } } }
```

| Statut | Code | Cas |
|---|---|---|
| 400 | `invalid_request`, `invalid_status`, `invalid_json` | Paramètre ou corps invalide |
| 401 | `unauthenticated` | Authentification requise ou jeton refusé |
| 403 | `forbidden`, `csrf_mismatch` | Capacité manquante ; en session, `X-Prime-CSRF` absent |
| 404 | `not_found` | Ressource absente, **ou présente mais invisible pour vous** (un brouillon d'autrui) |
| 405 | `method_not_allowed` | Méthode non prise en charge |
| 409 | `conflict` | Contenu verrouillé par un autre utilisateur, média utilisé, suppression hors corbeille |
| 412 | `precondition_failed` | `If-Match` périmé |
| 413 | `payload_too_large` | Corps plus gros que `api.max_body_kb` |
| 415 | `unsupported_media_type` | Corps qui n'est pas en JSON (ou pas en multipart pour un envoi de fichier) |
| 422 | `validation_failed` | Champs invalides (`fields`) |
| 429 | `rate_limited` | Limite atteinte (voir `Retry-After`) |
| 500 | `server_error` | Erreur interne, sans détail |

## 4. Pagination, filtres et champs

Les listes de contenus acceptent les paramètres suivants :

| Paramètre | Effet |
|---|---|
| `page`, `per_page` | Pagination (`per_page` de 1 à 100, 20 par défaut). Le total est aussi renvoyé dans l'en-tête `X-Total-Count`. |
| `status` | `published` par défaut. Les autres statuts exigent `content.edit_any` ; un statut inconnu donne une erreur 400. |
| `search` | Recherche plein texte. |
| `author` | Identifiant de l'auteur. |
| `term` | Format `taxonomie:slug`, par exemple `category:actualites`. |
| `after`, `before` | Date ou date-heure UTC. |
| `orderby`, `order` | `published_at`, `updated_at`, `created_at`, `title` ou `menu_order` ; `asc` ou `desc`. |
| `fields` | Membres à renvoyer, séparés par des virgules (`id` et `_links` sont toujours inclus). |

## 5. Concurrence et cache

- **ETag :** chaque contenu porte un `ETag` (`W/"12-…"`).
- **Mise à jour sans écrasement :** envoyez `If-Match: <ETag lu>` avec votre `PATCH`. Si quelqu'un a modifié le contenu entre-temps, la réponse est 412 et rien n'est écrit.
- **Verrou d'édition :** si un autre utilisateur est en train de modifier le contenu dans l'administration, la réponse est 409.
- **Cache :**
  - une réponse authentifiée porte `Cache-Control: private, no-store` ;
  - une lecture anonyme est revalidable : envoyez `If-None-Match` pour recevoir 304 sans corps.

## 6. Limites, CORS et réglages

Ces réglages se trouvent dans **Réglages › Sécurité › API REST**.

| Réglage | Défaut | Rôle |
|---|---|---|
| `api.enabled` | activé | Désactivée, l'API répond 404 partout. |
| `api.public_read` | activé | Désactivée, la lecture anonyme des contenus répond 401. |
| `api.rate_limit_authenticated` | 120 | Requêtes par minute et par jeton (ou par session). |
| `api.rate_limit_anonymous` | 60 | Requêtes par minute et par adresse IP. |
| `api.cors_origins` | vide | Une origine par ligne, par exemple `https://app.exemple.fr`. Sans origine, aucun en-tête CORS n'est envoyé. |
| `api.max_body_kb` | 1024 | Taille maximale d'un corps de requête. |
| `api.token_max_days` | 365 | Durée de vie maximale d'un jeton (`0` : sans limite). |

**Limites de débit :**
- Chaque réponse porte `X-RateLimit-Limit` et `X-RateLimit-Remaining`.
- Une requête au-delà de la limite est refusée **avant** d'être exécutée : une écriture refusée n'a jamais lieu.

**CORS :**
- L'origine doit correspondre exactement à une ligne du réglage. `*` n'est jamais utilisé.
- Les requêtes de pré-vérification (`OPTIONS`) d'une origine autorisée reçoivent 204, avec `Authorization`, `Content-Type`, `If-Match` et `X-Prime-CSRF` autorisés.

## 7. Ressources

| Méthode et chemin | Accès | Rôle |
|---|---|---|
| `GET /api/v1` | public | Point d'entrée |
| `GET /api/v1/openapi.json` | public | Description OpenAPI de ce qui vous est accessible |
| `GET /api/v1/site` | public | Nom, slogan, langue, fuseau horaire, URL |
| `GET /api/v1/types` | public | Types de contenu visibles |
| `GET`, `PATCH /api/v1/me` | authentifié | Votre compte et les capacités de votre jeton ; changer le nom affiché ou la langue |
| `GET /api/v1/content`, `/content/{type}` | public | Listes de contenus |
| `GET /api/v1/content/{type}/{id ou slug}` | public | Un contenu |
| `POST /api/v1/content/{type}` | `content.create` | Créer un contenu (201 + `Location`) |
| `PATCH /api/v1/content/{type}/{id}` | `content.edit_own` | Modifier un contenu (`If-Match` conseillé) |
| `DELETE /api/v1/content/{type}/{id}` | `content.delete_own` | Mettre à la corbeille ; avec `?force=1`, supprimer définitivement un contenu déjà dans la corbeille (204) |
| `POST /api/v1/content/{type}/{id}/restore` | `content.delete_own` | Restaurer un contenu depuis la corbeille |
| `GET /api/v1/terms`, `/terms/{taxonomie}`, `/terms/{taxonomie}/{id ou slug}` | public | Taxonomies et termes |
| `POST`, `PATCH`, `DELETE /api/v1/terms/{taxonomie}…` | capacité de la taxonomie | Gérer les termes |
| `GET /api/v1/media`, `/media/{id}` | `media.view` | Médiathèque (filtres `kind`, `folder`, `search`) |
| `POST /api/v1/media` | `media.upload` | Envoyer un fichier en `multipart/form-data`, champ `file` |
| `PATCH /api/v1/media/{id}` | `media.upload` | Titre, texte alternatif, légende, point focal, dossier |
| `DELETE /api/v1/media/{id}` | `media.upload` | 409 si le média est utilisé, sauf `?force=1` avec `media.delete_any` |
| `GET /api/v1/users`, `/users/{id}` | `users.view` | Comptes (jamais de hash, de secret 2FA ni de jeton) |
| `GET /api/v1/settings` | public | Réglages publics ; tous les réglages non secrets avec `settings.manage` |
| `PATCH /api/v1/settings` | `settings.manage` | Validation identique aux écrans ; rien n'est écrit si un champ est invalide |
| `GET /api/v1/menus/{emplacement}` | public | Menu d'un emplacement du thème, avec les entrées non publiées masquées |

**Écritures :** elles passent par les mêmes services que l'administration. Les permissions, les statuts autorisés, les verrous et l'assainissement du contenu sont donc identiques. Le contenu d'un article est un document Prime Editor (`{"blocks": [...]}`) ; `content_html` est en lecture seule.

### Exemples avec `curl`

```bash
API=https://exemple.fr/api/v1
TOKEN=pcat_xxxxxxxx_…

# Lire les derniers articles publiés
curl "$API/content/post?per_page=5&fields=title,url"

# Créer un brouillon
curl -X POST "$API/content/post" -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" -d '{"title":"Depuis l’API","status":"draft"}'

# Modifier sans écraser une autre modification
ETAG=$(curl -sI "$API/content/post/12" -H "Authorization: Bearer $TOKEN" | awk -F': ' '/^ETag/{print $2}' | tr -d '\r')
curl -X PATCH "$API/content/post/12" -H "Authorization: Bearer $TOKEN" -H "If-Match: $ETAG" \
     -H "Content-Type: application/json" -d '{"title":"Titre corrigé"}'

# Corbeille, restauration, suppression définitive
curl -X DELETE "$API/content/post/12" -H "Authorization: Bearer $TOKEN"
curl -X POST   "$API/content/post/12/restore" -H "Authorization: Bearer $TOKEN"
curl -X DELETE "$API/content/post/12?force=1" -H "Authorization: Bearer $TOKEN"

# Créer une catégorie
curl -X POST "$API/terms/category" -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" -d '{"name":"Voyages"}'

# Envoyer une image
curl -X POST "$API/media" -H "Authorization: Bearer $TOKEN" -F "file=@photo.jpg" -F "alt=Plage au matin"

# Changer un réglage
curl -X PATCH "$API/settings" -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" -d '{"api.rate_limit_anonymous":30}'

# Lire le menu principal
curl "$API/menus/primary"
```

## 8. Routes des plugins

Un plugin déclare ses routes avec `PluginContext::api()`. Elles sont servies sous `/api/{slug}/v1`, avec les mêmes middlewares, le même format d'erreur et la même description OpenAPI que celles du noyau. Le détail est dans [`plugins.md`](plugins.md#api-rest-des-plugins).

## 9. Configuration du serveur web

Certains serveurs ne transmettent pas l'en-tête `Authorization` à PHP.

- **Apache** (`.htaccess` ou hôte virtuel) :

  ```apache
  SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
  # ou, avec mod_rewrite :
  RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
  ```

- **Nginx + PHP-FPM** :

  ```nginx
  location ~ \.php$ {
      fastcgi_param HTTP_AUTHORIZATION $http_authorization;
      # …
  }
  ```

Servez toujours l'API en HTTPS : un jeton circule en clair dans l'en-tête.

## 10. Administration

**Outils › API REST** (capacité `settings.manage`) montre :
- l'état de l'API, les limites et les origines autorisées ;
- le nombre de routes déclarées ;
- **tous** les jetons actifs, tous comptes confondus, avec leur révocation après confirmation ;
- des liens vers les réglages, la description OpenAPI et ce guide.

**Nettoyage :** la tâche planifiée `api-tokens-prune` supprime chaque jour les jetons révoqués ou expirés depuis plus de 30 jours.

**Événements pour les plugins :**
- `api.token.created` et `api.token.revoked` ;
- `api.request`, après chaque appel (méthode, chemin, statut, durée, compte, jeton) ;
- le filtre `api.response`, qui peut ajuster une réponse ; un résultat invalide est ignoré.
