# Phase 10 — API REST (v0.10.0)

- **Branche** : `arena/a57092d4-prime-cms`.
  - Étapes 1 à 4 : livrées par l'agent, avec des tests de contrat écrits par la revue.
  - Étapes 5 à 7 : écrites par Claude, après l'échec de la première tentative de l'étape 5.
- **Version noyau** : `0.10.0` (`Prime\Kernel\Application::VERSION`).
- **Migrations du noyau** : 26 (`000026_create_api_tokens_table`).
- **Tests** : 818 PHPUnit hors base, 31 tests `database` (MySQL 8.0, MariaDB 10.6), 55 tests JS. Contrats de la revue : `ApiContractTest` (18), `ApiContractStep3Test` (13), `ApiContractStep4Test` (22), `ApiContractStep5Test` (18).

---

## 1. Livrables

| Étape | Périmètre | État |
|---|---|---|
| 1–2 | Jetons `pcat_<préfixe>_<secret>` dont seul le SHA-256 est stocké ; authentification par Bearer seul ; jeton dans l'URL refusé (400) ; erreurs JSON uniformes ; lecture anonyme limitée aux contenus publiés. | Terminé |
| 3 | Capacités effectives (compte ∩ jeton, sans le raccourci du rôle administrateur) ; pagination réelle ; erreurs traduites ; `last_used` limité à une écriture par minute ; session admin ; écran « Mon profil › Jetons d'API ». | Terminé |
| 4 | Réglages `api.*` (écran Sécurité) ; middlewares d'activation, CORS, limite de débit, lecture publique, cache HTTP ; commandes `api:token:create\|list\|revoke` ; tâche `api-tokens-prune` ; jetons sur la fiche utilisateur. | Terminé |
| 5 | `ApiRoute` déclaratif et `ApiRouteRegistry` (permission ou `public: true` obligatoire, conflits refusés) ; corps JSON (413, 415, 400 `invalid_json`, 422 `validation_failed` + `fields`) ; CSRF de session par `X-Prime-CSRF` ; écriture des contenus (création, `PATCH` + `If-Match`/412, verrou/409, corbeille, restauration, suppression définitive), filtres, tri, `fields` ; termes ; `PATCH /me` ; `Entry/Term/UserResource` + filtre `api.resource.*` ; événement `api.request` et filtre `api.response`. | Terminé |
| 6 | Médias (liste, lecture avec usages, envoi multipart via `UploadService`, métadonnées, suppression 409 si utilisé), comptes (`users.view`), réglages (publics, non secrets, secrets en écriture seule, `PATCH` sans écriture partielle), menus par emplacement. | Terminé |
| 7 | `PluginContext::api()` (routes `/api/{slug}/v1`, gestionnaire isolé → 500 opaque, schémas OpenAPI), `hello-prime` (`GET`/`POST /greetings`), **Outils › API REST**, `GET /api/v1/openapi.json` (3.1, filtré selon l'appelant), `docs/api.md`, section API de `docs/plugins.md`, version 0.10.0. | Terminé |

## 2. Corrections apportées en cours de phase

- **Limite de débit.** La requête était exécutée avant le refus 429 : une écriture au-delà de la limite était quand même enregistrée. Le refus intervient désormais avant le contrôleur (test `testAWriteOverTheRateLimitIsNotPerformed`).
- **Préfixe des jetons.** Il recopiait les 8 premiers caractères du secret, affichés en clair dans l'administration. Il est désormais tiré au hasard, indépendamment du secret.
- **Expiration.** Un jeton créé sans durée n'expirait jamais, même avec `api.token_max_days` réglé. Il reçoit désormais la durée maximale.
- **`EntryQuery::statuses()`.** Un statut inconnu était écarté en silence, et la requête devenait « tous les statuts », ce qui masquait la fuite de brouillons. Une exception est désormais levée. Le même bug existait dans le sélecteur de pages des réglages, sur `main`.
- **`/api/v1/site`** lisait `site.title`, qui n'existe pas, au lieu de `site.name`.
- **Traductions.** 33 clés lues dynamiquement (onglets Outils, état du système, statut des e-mails, pages de réglages) avaient été supprimées par erreur ; elles sont restaurées.
- **Événements et mot de passe.** Les événements `api.token.created` et `api.token.revoked`, ainsi que la révocation des jetons au changement de mot de passe (case cochée par défaut), manquaient : ils sont ajoutés.
- **Réglages `api.*`.**
  - Valeurs par défaut alignées sur la spécification : 120 et 60 requêtes par minute, corps de 16 à 65 536 Ko, `token_max_days` acceptant `0`.
  - Validation des origines CORS ajoutée.

## 3. Sécurité

- **Écritures.** Elles passent toutes par les services de l'administration (`EntryService`, `TermRepository`, `UploadService`, `SettingsFormProcessor`) : permissions, statuts autorisés, verrous et assainissement sont identiques.
- **Ressources invisibles.** Un brouillon d'autrui est un 404, en lecture comme en écriture. L'e-mail d'un auteur n'apparaît jamais dans un contenu.
- **Comptes et réglages.** Aucun hash, secret 2FA, code de récupération ou empreinte de jeton n'est sérialisé. Les secrets de réglage ne sont jamais renvoyés, seulement signalés comme « configurés ».
- **CSRF.** Le CSRF de formulaire ignore `/api/*`, où la règle est propre à l'API : les jetons sont exemptés, les sessions exigent `X-Prime-CSRF`.
- **Plugins.** Une exception dans un gestionnaire de plugin est journalisée et répond 500 sans détail. Un filtre de plugin qui échoue ne modifie pas la réponse.

## 4. Vérifications avec curl

Session réellement lancée le 2026-11-18, sur l'installation locale MariaDB 10.11 (`php -S … public/router.php`), avec un jeton administrateur et un jeton « lecture seule » créés par `bin/prime api:token:create`. Les jetons sont masqués.

```text
$ curl -s $API | jq -c .
{"data":{"api_version":"v1","authenticated":false}}

$ curl -s "$API/site" | jq -c ".data | {name, locale, timezone}"
{"name": "Prime Review", "locale": "fr", "timezone": "UTC"}

$ curl -s "$API/content/post?per_page=2&fields=title" -D - (en-têtes utiles)
HTTP/1.1 200 OK
X-Total-Count: 10
ETag: W/"4416fb7a665df09282fc9acbd61bc6bd4899baa3"
Cache-Control: public, max-age=0, must-revalidate
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 57
{"titles": ["Picker video", "Picker audio"], "meta": {"type": "post", "page": 1, "per_page": 2, "total": 10, "total_pages": 5}}

$ curl -s -o /dev/null -w "%{http_code}" -X POST $API/content/post -d "{}"   # anonyme
401

$ curl -s -X POST $API/content/post -H "Authorization: Bearer <lecture seule>" ...
{"error":{"code":"forbidden","message":"Accès interdit.","status":403}}

$ curl -s -X POST $API/content/post -H "Authorization: Bearer $TOKEN" -d "{\"title\":\"Créé par curl\",\"status\":\"draft\"}"
HTTP/1.1 201 Created
ETag: W/"34-7a3e2e166f04caab39bf82fef224bb658826009c"
Location: http://127.0.0.1:8090/api/v1/content/post/34
id=34

$ curl -s -X PATCH $API/content/post/$ID -H "If-Match: W/\"perime\"" ...
{"error":{"code":"precondition_failed","message":"La ressource a été modifiée depuis votre dernière lecture (If-Match).","status":412}}

$ curl -s -X PATCH $API/content/post/$ID -H "If-Match: $ETAG" -d "{\"title\":\"Titre corrigé\"}"
{"id": 34, "title": "Titre corrigé", "status": "draft"}

$ curl -s -X POST $API/content/post -H "Content-Type: text/plain" -d "x"
{"error":{"code":"unsupported_media_type","message":"Le corps de la requête doit être envoyé en JSON (Content-Type: application/json).","status":415}}

$ curl -s -X POST $API/content/post -d "{\"title\": "
{"error":{"code":"invalid_json","message":"Le corps de la requête n’est pas un JSON valide.","status":400}}

$ curl -s -X POST $API/content/post -d "{\"title\":\"\"}"
{"error":{"code":"validation_failed","message":"Certains champs sont invalides.","status":422,"fields":{"title":"Le titre est obligatoire."}}}

$ curl -s -o /dev/null -w "%{http_code}" -X DELETE $API/content/post/$ID   # corbeille
200
$ ... POST $API/content/post/$ID/restore
200
$ ... DELETE $API/content/post/$ID?force=1   # hors corbeille
409
$ ... DELETE (corbeille) puis DELETE ?force=1
204

$ curl -s "$API/content/post?access_token=$TOKEN"
{"error":{"code":"invalid_request","message":"Requête invalide.","status":400}}

$ curl -s -o /dev/null -w "%{http_code}" "$API/openapi.json"
3.1.0 13 chemins publics
3.1.0 21 chemins pour un administrateur

$ curl -s http://127.0.0.1:8091/api/hello-prime/v1/greetings | jq -c ".data | length"
0 messages
```

## 5. Points ouverts

- **OpenAPI.** La description ne détaille pas encore les filtres propres aux plugins, au-delà de ce qu'ils déclarent dans leurs options.
- **Médias.** Le remplacement d'un fichier (`replace`) n'est pas encore exposé par l'API ; il reste disponible dans la médiathèque.
