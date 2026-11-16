# Système de thèmes & thème Aurora (Phase 8)

Le moteur de thèmes de Prime CMS (`Prime\Themes\*`) sépare strictement les thèmes du noyau dans `content/themes/{slug}/`, applique un contrat déclaratif via `theme.json`, isole les erreurs fatales avec bascule automatique sur le thème officiel **Aurora**, et publie uniquement des assets statiques autorisés dans `public/assets/themes/{slug}/`.

---

## 1. Anatomie d'un thème

```text
content/themes/mon-theme/
├── theme.json                  # Manifeste obligatoire (validé par ThemeManifestValidator)
├── screenshot.webp             # Aperçu 1200×900 (ou .png/.jpg, ≤ 2 Mo)
├── src/
│   └── Theme.php               # Classe d'entrée optionnelle (autoload PSR-4, boot(ThemeContext))
├── assets/                     # Seul dossier copié dans public/assets/themes/mon-theme/
│   ├── css/style.css
│   └── js/theme.js
└── templates/
    ├── layouts/base.php        # Squelette HTML principal
    ├── front.php               # Page d'accueil
    ├── blog.php                # Liste des articles (mode page d'accueil statique)
    ├── entry/
    │   ├── default.php
    │   ├── post.php
    │   └── page.php
    ├── page/
    │   └── full-width.php      # Modèle de page déclaré dans theme.json
    ├── archive/default.php
    ├── term/default.php
    ├── author.php
    ├── search.php
    ├── 404.php
    └── partials/
        ├── header.php
        ├── footer.php
        ├── card.php
        ├── breadcrumbs.php
        └── pagination.php
```

> **Sécurité** : aucun fichier PHP de `content/themes/` n'est accessible directement en HTTP. Seuls les fichiers statiques aux extensions autorisées (`css`, `js`, `png`, `jpg`, `jpeg`, `webp`, `gif`, `avif`, `svg`, `ico`, `woff`, `woff2`, `ttf`, `otf`, `eot`, `map`) dans `assets/` ainsi que `screenshot.*` sont publiés dans `public/assets/themes/{slug}/`.

---

## 2. Manifeste `theme.json`

Champs validés par `Prime\Themes\ThemeManifestValidator` :

| Champ | Requis | Description |
|-------|--------|-------------|
| `slug` | Oui | Identifiant unique (`^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$`), identique au nom du dossier. |
| `name` | Oui | Nom affiché dans l'administration. |
| `version` | Oui | Version SemVer (`1.0.0`). |
| `description` | Non | Description courte. |
| `author` | Non | Chaîne ou objet `{ "name": "...", "url": "https://..." }`. |
| `requires` | Non | Contraintes SemVer `{ "prime": ">=0.8.0", "php": ">=8.2" }`. |
| `parent` | Non | Slug d'un thème parent (héritage **limité à 1 niveau**). |
| `namespace` | Non | Namespace PSR-4 pour `src/` (préfixe `Prime\` interdit). |
| `main` | Non | Classe d'entrée FQCN (défaut : `{Namespace}\Theme`). |
| `menus` | Non | Emplacements de menus (`slug => Libellé`). |
| `zones` | Non | Zones de blocs (`slug => { label, description }`). |
| `templates` | Non | Modèles par type de contenu (`page => { full-width: "Pleine largeur" }`). |
| `image_sizes` | Non | Formats GD supplémentaires enregistrés via le filtre `media.image_sizes`. |
| `options` | Non | Déclaration des sections et champs du Personnaliseur (`color`, `select`, `font`, `range`, `toggle`, `text`, `textarea`, `media`). |

---

## 3. Héritage parent / enfant (1 niveau)

Un thème enfant déclare `"parent": "aurora"` dans son `theme.json` :

- Les templates et partials sont résolus d'abord dans l'enfant, puis dans le parent (`TemplateResolver`).
- Les emplacements de menus, zones, modèles et options du parent sont hérités et fusionnés avec ceux de l'enfant.
- Au démarrage (`ThemeManager::bootActive()`), le parent est démarré **avant** l'enfant.
- Un thème enfant ne peut pas être parent d'un troisième thème (validation stricte).

---

## 4. Point d'entrée `Theme::boot(ThemeContext $context)`

```php
<?php

declare(strict_types=1);

namespace MonTheme;

use Prime\Themes\ThemeContext;

final class Theme
{
    public function boot(ThemeContext $theme): void
    {
        $theme->enqueueStyle('mon-theme', $theme->assetUrl('css/style.css'));
        $theme->enqueueScript('mon-theme', $theme->assetUrl('js/theme.js'), defer: true);

        $theme->hooks()->filter('site.seo_title', static fn (string $t): string => $t);
    }
}
```

### API disponible dans les templates (`SiteView` & `EntryView`)

Chaque template reçoit `$site` (`Prime\Site\View\SiteView`) et, selon le contexte, `$entry` (`EntryView`), `$entries` (`list<EntryView>`), `$term`, `$taxonomy`, `$author`, `$contentType`, `$context`.

- **Échappement par défaut** : `$site->name()`, `$site->tagline()`, `$site->option()`, `$entry->title()`, `$entry->excerpt()`, `$entry->meta()`, `$entry->author()['name']` retournent des chaînes déjà échappées pour HTML. Les variantes brutes portent explicitement le préfixe `raw*` (`rawName()`, `rawOption()`, `rawTitle()`).
- **Méthodes HTML sûres** : `$site->head()`, `$site->footer()`, `$site->menu('primary', [...])`, `$site->zone('sidebar')`, `$site->logo()`, `$site->partial('card', ['item' => $item])`, `$site->pagination()`, `$entry->content()`, `$entry->featuredImage('card', [...])`.
- **Requêtes sûres** : `$site->query(['type' => 'post', 'limit' => 3])` et `$entry->related(3)` préchargent auteurs et médias en lot (zéro N+1).

---

## 5. Hiérarchie des templates (`TemplateResolver`)

Pour chaque contexte (`PageContext`), le résolveur parcourt les candidats du plus spécifique au plus générique (thème enfant → thème parent → templates enregistrés par les plugins via `site.plugin_template_paths`) :

1. **Page d'accueil** (`front`) : `front.php` → `page/{template}.php` (si page statique avec modèle) → `entry/page.php` → `blog.php` → `index.php`
2. **Page du blog** (`blog`) : `blog.php` → `archive/post.php` → `archive/default.php` → `index.php`
3. **Contenu unique** (`entry`) : `{type}/{template}.php` (si modèle assigné) → `entry/{type}-{slug}.php` → `entry/{type}.php` → `entry/default.php` → `index.php`
4. **Archive de type** (`archive`) : `archive/{type}.php` → `archive/default.php` → `index.php`
5. **Taxonomie / Terme** (`term`) : `term/{taxonomy}-{slug}.php` → `term/{taxonomy}.php` → `term/default.php` → `archive/default.php` → `index.php`
6. **Auteur** (`author`) : `author-{slug}.php` → `author.php` → `archive/default.php` → `index.php`
7. **Recherche** (`search`) : `search.php` → `archive/default.php` → `index.php`
8. **404** (`not_found`) : `404.php` → `index.php`

Filtres disponibles : `site.template_candidates` (liste des candidats) et `site.template` (chemin résolu, contrôlé contre toute traversée de répertoire).

---

## 6. Options de thème & Personnaliseur (`/admin/appearance/customizer`)

- Les options déclarées dans `theme.json` sont validées par `ThemeOptions::validate()`, enregistrées dans `pc_settings` (`theme_options.{slug}`), et compilées en un fichier CSS statique versionné `public/assets/themes/{slug}/options-{hash}.css` contenant exclusivement des propriétés personnalisées CSS (`:root { --aurora-accent: #C9441F; ... }`).
- **Aucun `<style>` inline** n'est émis sur le site public : la CSP stricte (`style-src 'self'`) reste intacte.
- L'aperçu en direct du Personnaliseur charge `/?customizer_preview=1` dans une `<iframe>` (uniquement pour un utilisateur autorisé `themes.manage`), met à jour instantanément les variables CSS via `postMessage` (`customizer-preview.js`) avec vérification stricte d'origine (`event.origin === window.location.origin`), et n'écrit en base qu'au clic sur **Publier**.

---

## 7. Thème officiel par défaut : **Aurora**

Situé dans `content/themes/aurora/` (namespace `Aurora`, classe `Aurora\Theme`) :

- **Identité éditoriale** : polices locales Inter & Plus Jakarta Sans (zéro requête Google Fonts), palette papier chaud + accent corail WCAG AA (`#C9441F`), mode sombre optionnel, feuille d'impression `@media print`.
- **Déclarations** : 2 emplacements de menus (`primary`, `footer`), 4 zones de blocs (`sidebar`, `footer_1` à `footer_3`), 2 modèles de pages (`full-width`, `landing`), 2 formats d'images (`hero` 1600×900, `card` 800×500), 13 options réparties sur 5 sections (`--aurora-*`).
- **Budget performance** : `aurora.css` = 23,4 Ko (≤ 40 Ko), `aurora.js` = 2,0 Ko (≤ 8 Ko), 0 script inline, 0 style inline.

---

## 8. Isolation des erreurs, mode sans échec & CLI

- Si le thème actif lève une erreur fatale ou une exception au démarrage ou au rendu, `FatalLoadGuard` intercepte l'échec, bascule automatiquement sur **Aurora**, journalise l'incident et affiche une bannière d'alerte dans l'administration.
- `PRIME_SAFE_MODE=true` dans `.env` force Aurora sans exécuter le thème tiers actif (récupération d'urgence).
- Le thème `aurora` ne peut jamais être supprimé ; un thème parent ne peut pas être supprimé tant qu'un thème enfant l'utilise.

### Commandes CLI

```bash
php bin/prime themes:list              # Liste les thèmes détectés, statut, version et compatibilité
php bin/prime themes:activate <slug>   # Valide, publie les assets et active le thème
php bin/prime themes:publish [slug]    # Republie les assets statiques et le CSS d'options
```
