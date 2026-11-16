# Rendu du site, Permaliens, Menus, Zones & SEO (Phase 8)

Le moteur public (`Prime\Site\*`) transforme les contenus Prime CMS en un site web complet servi par le thème actif, avec routage dynamique, redirections 301 automatiques, menus hiérarchiques, zones de blocs dynamiques et SEO technique natif.

---

## 1. Lecture & Permaliens

Administrables dans **Réglages › Lecture** (`/admin/settings/reading`) et **Réglages › Permaliens** (`/admin/settings/permalinks`) avec la permission `settings.manage`.

### Structures d'URL des articles (`PermalinkSettings`)

| Clé | Exemple d'URL |
|-----|---------------|
| `blog_slug` *(défaut)* | `/blog/mon-article` |
| `year_month_slug` | `/2026/11/mon-article` |
| `slug` | `/mon-article` |

### Routes publiques résolues (`UrlResolver`)

- Page d'accueil `/` : derniers articles (`front_mode = posts`) ou page statique (`front_mode = page` + page des articles configurable).
- Pages hiérarchiques : `/a-propos`, `/a-propos/equipe`.
- Archives CPT publics (`hasArchive = true`), taxonomies (`/categorie/{slug}`, `/etiquette/{slug}`), auteurs (`/auteur/{username}`), recherche (`/recherche?q=...`) et pagination (`/page/{n}`, retour `404` pour `n < 1` ou `n > last_page`).
- Les slugs réservés (`/admin`, `/install`, `/api`, `/assets`, `/uploads`, `/health`, `/blog`, `/categorie`, `/etiquette`, `/auteur`, `/recherche`, `/page`, `/sitemap.xml`, `/robots.txt`, etc.) sont protégés par `ReservedSlugValidator`.

### Redirections 301 automatiques (`pc_redirects`)

- Tout changement de slug d'un contenu publié émet `content.updated` et enregistre automatiquement une redirection `301` de l'ancienne URL vers la nouvelle via `RedirectRepository`.
- **Raccourcissement de chaîne** : si `A → B` existe et `B → C` est ajouté, `A` est mis à jour en `A → C` (zéro chaîne multi-sauts).
- **Prévention des boucles** : les auto-redirections (`A → A`) sont refusées ; un renommage retour (`A → B` puis `B → A`) supprime proprement l'ancienne règle inverse.
- **Administration** : ajout manuel (`301`/`302`), suppression et compteur de visites (`hits`, `last_hit_at`) dans **Réglages › Permaliens**.

---

## 2. Menus de navigation (`pc_menus`, `pc_menu_items`)

Administrables dans **Apparence › Menus** (`/admin/appearance/menus`, permission `themes.manage`) :

- Arborescence jusqu'à **3 niveaux** (`MAX_DEPTH = 3`), réordonnancement et indentation accessibles au clavier (`↑` / `↓` / `→` / `←`) et à la souris.
- Sources prises en charge : **Pages**, **Articles**, **Catégories / Termes**, **Archives CPT** et **Liens personnalisés** (`url` / `custom`).
- Sécurité URL (`MenuRepository::sanitizeUrl()`) : seuls les chemins relatifs (`/`, `#ancre`) et les schémas `http://`, `https://`, `mailto:`, `tel:` sont autorisés (`javascript:`, `data:` et `vbscript:` rejetés).
- En rendu public (`MenuRenderer`), les entrées non publiées ou supprimées sont automatiquement masquées, `aria-current="page"` est posé sur l'élément actif, et `rel="noopener noreferrer"` est ajouté automatiquement sur tout lien `target="_blank"`.

---

## 3. Zones de blocs & Blocs dynamiques (`pc_zones`, `pc_media_zone_usage`)

Administrables dans **Apparence › Zones** (`/admin/appearance/zones`, permission `themes.manage` ; l'ancien raccourci `/admin/appearance/widgets` redirige en `302` vers `/admin/appearance/zones`) :

- Chaque zone déclarée par le thème (`sidebar`, `footer_1`…`footer_3`) est stockée par couple `(theme, slug)` et éditée avec **Prime Editor**.
- Les images insérées dans une zone sont indexées automatiquement dans `pc_media_zone_usage` (`MediaUsageRepository::syncForZoneDocument()`), apparaissent dans le compteur d'utilisations de la Médiathèque et empêchent les suppressions accidentelles.
- **6 blocs dynamiques natifs** (`DynamicBlockRegistry`, évalués à l'affichage en direct) :
  1. `recent_posts` — Articles récents (nombre, date, extrait configurables)
  2. `categories` — Liste des catégories publiées avec compteurs
  3. `tags` — Nuage d'étiquettes
  4. `search` — Formulaire de recherche vers `/recherche`
  5. `menu` — Affichage d'un menu enregistré dans une zone
  6. `author_bio` — Carte biographique de l'auteur
- Les plugins peuvent enregistrer leurs propres blocs dynamiques via le filtre `site.dynamic_blocks` (exemple dans `hello-prime` : bloc `hello_testimonial`).

---

## 4. SEO natif (`SeoManager`, `SitemapGenerator`, `RobotsGenerator`)

Administrable globalement dans **Réglages › Référencement** (`/admin/settings/seo`) et par contenu dans le panneau **Référencement (SEO)** de l'éditeur de contenu (avec aperçu SERP live et compteurs de caractères) :

- **Balises `<head>`** : `<title>`, `<meta name="description">`, `<link rel="canonical">`, `rel="prev"` / `rel="next"` sur les archives paginées, `<meta name="robots">` (`noindex, follow` automatique sur `/recherche`, `404`, prévisualisations, et contenus marqués `noindex` ou si le site désactive l'indexation globale).
- **Open Graph & Twitter Cards** : `og:title`, `og:description`, `og:type`, `og:url`, `og:site_name`, `og:image`, `twitter:card` (`summary_large_image` si image présente).
- **Données structurées JSON-LD** : `WebSite` (avec `SearchAction` sur l'accueil), `Article` (sur les articles), `BreadcrumbList` (fil d'Ariane). Échappement JSON strict (`JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`) empêchant toute sortie de balise `</script>`.
- **Sitemaps XML** :
  - `/sitemap.xml` : index listant chaque sous-sitemap actif (types publics + taxonomies publiques).
  - `/sitemap-{type}-{page}.xml` : jusqu'à 1 000 URL par page, excluant les brouillons, privés, corbeille et contenus marqués `seo_noindex`.
- **`/robots.txt`** : généré dynamiquement (directive `Sitemap:`, blocage `/admin/`, `/install/`, `/storage/`, ou `Disallow: /` si l'indexation globale est désactivée). Un fichier physique `public/robots.txt` conserve la priorité au niveau du serveur web.

---

## 5. Prévisualisations sécurisées

- **Contenu (brouillon / planifié / privé)** : accessible via `/admin/content/{type}/{id}/preview` ou `/?preview=1&entry_id={id}` uniquement pour un utilisateur connecté ayant la permission d'éditer l'entrée (`EntryService::canEdit()`). Rendu dans le thème actif avec `X-Robots-Tag: noindex, nofollow` et `Cache-Control: no-store`. Les visiteurs anonymes reçoivent `404`.
- **Personnaliseur** : `/?customizer_preview=1` n'injecte `customizer-preview.js` que pour un utilisateur connecté possédant `themes.manage`.
