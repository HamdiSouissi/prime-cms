# Cache

## 1. Cache de données

`Prime\Cache\CacheManager::data()` renvoie un `CacheInterface` (`get`, `set`, `remember`,
`delete`, `flush`) sur le pilote `file` (`storage/cache/data`) ou `apcu`. Les extensions
utilisent un espace de noms isolé :

```php
$cache = $app->get(CacheManager::class)->namespace('shop');
$rates = $cache->remember('rates', 3600, fn () => $api->fetchRates());
```

Configuration : `CACHE_DRIVER`, `CACHE_TTL` (`config/cache.php`) ou l'écran
**Réglages › Performance**, qui écrit un fichier de configuration d'exécution appliqué dès la
requête suivante.

## 2. Cache de pages

`PageCache` stocke les pages publiques **anonymes** dans `storage/cache/pages/` :

- uniquement `GET`/`HEAD`, sans cookie de session ni « se souvenir de moi » ;
- uniquement les réponses 200 sans `Set-Cookie`, `Cache-Control: private/no-store` ni `noindex` ;
- seul le paramètre `?page=` est accepté dans l'URL ; admin, installeur, aperçus et recherche sont exclus ;
- en-têtes `X-Prime-Cache: HIT|MISS|BYPASS`, `ETag` et réponses `304 Not Modified`.

**Purge ciblée** : publier, modifier ou supprimer un contenu purge sa page, l'accueil, le blog,
ses archives et flux. Un changement de thème, de menu, de réglage ou d’option du Personnaliseur vide tout le cache de pages.
Le bouton « Vider le cache » de la barre supérieure et `php bin/prime cache:clear
[--pages|--data|--prune]` font de même manuellement.

## 3. Hooks

| Hook | Type | Usage |
|------|------|-------|
| `cache.page.cacheable` | filtre | Retourner `false` pour exclure une requête ou une réponse. |
| `cache.purged` | événement | Après une purge (chemins concernés). |
