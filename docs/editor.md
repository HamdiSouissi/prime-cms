# Prime Editor — Éditeur de blocs natif

**Prime Editor** est l'éditeur de contenu par blocs 100 % natif de Prime CMS : zéro dépendance externe, Vanilla ES2022 côté navigateur, validation et rendu HTML sécurisé côté serveur en PHP 8.2+.

---

## 1. Format de document JSON (v1)

Chaque contenu est stocké dans `pc_entries.content` sous forme de document JSON versionné :

```json
{
  "version": 1,
  "blocks": [
    {
      "id": "blk_a1b2c3d4",
      "type": "heading",
      "data": { "level": 2, "text": "Bienvenue sur Prime CMS" }
    },
    {
      "id": "blk_e5f6g7h8",
      "type": "paragraph",
      "data": { "text": "Écrivez avec <strong>clarté</strong> et <em>précision</em>." }
    }
  ]
}
```

À chaque enregistrement, le moteur serveur `Prime\Editor\Renderer` produit et persiste :
- `content` : le JSON normalisé et validé ;
- `content_html` : le rendu HTML prêt pour l'affichage public et l'aperçu ;
- `content_text` : le texte brut extrait pour l'index `FULLTEXT`, le comptage de mots et le temps de lecture.

---

## 2. Les 15 blocs natifs du noyau

| Type | Catégorie | Données principales | Rendu HTML |
|------|-----------|---------------------|------------|
| `paragraph` | `text` | `text` (HTML inline assaini) | `<p class="pc-block pc-block--paragraph">` |
| `heading` | `text` | `level` (`2`–`4`), `text` | `<h2..h4 class="pc-block pc-block--heading">` |
| `list` | `text` | `style` (`unordered`\|`ordered`), `items[]` | `<ul/ol class="pc-block pc-block--list">` |
| `quote` | `text` | `text`, `cite` | `<figure class="pc-block pc-block--quote"><blockquote>...` |
| `callout` | `text` | `variant` (`info`\|`success`\|`warning`\|`danger`), `title`, `text` | `<aside class="pc-block pc-block--callout ...">` |
| `code` | `code` | `language`, `code` (échappé strictement) | `<pre class="pc-block pc-block--code"><code>...` |
| `image` | `media` | `mediaId` (ou `url` sûre), `alt`, `decorative`, `caption`, `align` (`left`\|`center`\|`right`\|`wide`) | `<figure class="pc-block pc-block--image ...">` + `<picture>` WebP responsive (`media_image`) |
| `gallery` | `media` | `columns` (`2`\|`3`\|`4`), `items[{mediaId, url, alt, caption}]`, `caption` | `<figure class="pc-block pc-block--gallery pc-gallery--cols-N">` |
| `video` | `media` | `mediaId` (ou `url`), `posterMediaId`/`posterUrl`, `controls`, `muted`, `loop`, `caption` | `<figure class="pc-block pc-block--video"><video ...>` |
| `audio` | `media` | `mediaId` (ou `url`), `title`, `caption` | `<figure class="pc-block pc-block--audio"><audio controls ...>` |
| `file` | `media` | `mediaId` (ou `url`), `label`, `extension`, `sizeBytes`, `showSize` | `<div class="pc-block pc-block--file">` + bouton `download` |
| `embed` | `media` | `url` (YouTube / Vimeo uniquement), `caption` | `<figure>` + `<iframe src="https://www.youtube-nocookie.com/embed/..." sandbox="...">` |
| `table` | `layout` | `hasHeader` (`bool`), `rows[][]` | `<div class="pc-block pc-block--table"><table>...` |
| `separator` | `layout` | — | `<hr class="pc-block pc-block--separator">` |
| `html` | `advanced` | `html` | Rendu brut **uniquement** si l'auteur détient `content.unfiltered_html` ; sinon assaini via `HtmlSanitizer::basic()`. |

---

## 3. Raccourcis et ergonomie client

L'éditeur ES2022 (`public/assets/admin/js/editor/*.js`) fonctionne sous CSP stricte (zéro script inline, zéro `eval`) :

- **Menu slash `/`** : tapez `/` dans un bloc vide pour filtrer et insérer n'importe quel bloc (noyau ou plugin).
- **Raccourcis Markdown** : `# `, `## `, `### ` (titres), `- ` / `* ` (liste à puces), `1. ` (liste numérotée), `> ` (citation), `---` (séparateur), `` ``` `` (bloc de code).
- **Enregistrement clavier (`Ctrl/⌘+S`)** : sauvegarde le contenu en préservant son statut courant (`draft`, `published`, `scheduled`, etc.) sans basculer involontairement un article publié en brouillon.
- **Coller / Glisser-déposer une image** : coller une capture depuis le presse-papiers ou déposer une image dans l'éditeur la téléverse automatiquement vers `/admin/media/api/upload` et insère un bloc `image` lié par `mediaId`.
- **Barre de formatage flottante** : sélectionnez du texte pour appliquer gras (`Ctrl/⌘+B`), italique (`Ctrl/⌘+I`), souligné (`Ctrl/⌘+U`), code inline ou lien (`https://`, `http://`, `mailto:`, `/`).
- **Collage intelligent (`paste.js`)** : convertit proprement le texte/HTML collé en blocs structurés et transforme automatiquement une URL YouTube/Vimeo collée seule en bloc `embed`.
- **Historique complet (`history.js`)** : Annuler (`Ctrl/⌘+Z`) et Rétablir (`Ctrl/⌘+Shift+Z` / `Ctrl/⌘+Y`) jusqu'à 100 états.
- **Mode sans distraction**, **autosave automatique** (30 s), **compteur de mots & temps de lecture** et **réorganisation** de blocs (flèches clavier ou glisser-déposer).

---

## 4. Sécurité, `HtmlSanitizer` et CSP d'aperçu

### Assainissement serveur (`Prime\Security\HtmlSanitizer`)
Toutes les chaînes HTML riches passent par un analyseur `DOMDocument` basé sur une **liste blanche stricte** :
- Profil `inline` : `<strong>`, `<b>`, `<em>`, `<i>`, `<u>`, `<s>`, `<code>`, `<mark>`, `<br>`, `<a href>` (protocoles `http:`, `https:`, `mailto:`, `#`, `/` uniquement ; ajout automatique de `rel="noopener noreferrer"` sur les liens externes).
- Profil `basic` : ajoute les balises de structure sûres (`<p>`, `<ul>`, `<ol>`, `<li>`, `<blockquote>`, `<pre>`, `<h2>`–`<h6>`, `<table>`, `<figure>`, `<img>`, `<hr>`).
- Suppression systématique de `<script>`, `<style>`, `<iframe>`, `<object>`, `<svg>`, de tous les attributs `on*` et de toute URL `javascript:` / `vbscript:` / `data:`.

### Protection `content.unfiltered_html`
- Seul le rôle **Administrateur** (`*`) détient `content.unfiltered_html` par défaut.
- Si un éditeur ou auteur sans cette capacité modifie un contenu contenant déjà un bloc `html` créé par un administrateur, le bloc existant est **préservé à l'identique** tant qu'il n'est pas modifié ; toute modification ou création d'un bloc `html` sans la capacité est assainie automatiquement via `HtmlSanitizer::basic()`.

### CSP dédiée à l'aperçu (`/admin/content/{type}/{id}/preview`)
- Toutes les routes d'administration conservent la CSP stricte globale sans `frame-src`.
- Seule la route d'aperçu (`ContentController::preview()`) émet une CSP spécifique autorisant uniquement `frame-src https://www.youtube-nocookie.com https://player.vimeo.com` afin de prévisualiser les blocs `embed` sans élargir la surface d'exposition du back-office.

---

## 5. Créer un bloc personnalisé depuis un plugin

Un plugin enregistre un bloc en deux étapes : côté PHP (validation + rendu serveur) et côté JS (interface dans Prime Editor).

### Côté PHP (`src/Plugin.php`)

```php
use Prime\Editor\BlockDefinition;
use Prime\Security\HtmlSanitizer;

$context->blocks()->register(new BlockDefinition(
    type: 'mon-plugin/highlight',
    title: 'Encart',
    description: 'Met en valeur une information clé.',
    icon: 'sparkles',
    category: 'text',
    keywords: ['encart', 'highlight'],
    validator: static function (array $data, HtmlSanitizer $sanitizer): array {
        return [
            'badge' => trim(strip_tags((string) ($data['badge'] ?? ''))),
            'text' => $sanitizer->inline((string) ($data['text'] ?? '')),
        ];
    },
    renderer: static function (array $data): string {
        $badge = htmlspecialchars((string) ($data['badge'] ?? ''), ENT_QUOTES, 'UTF-8');
        $text = (string) ($data['text'] ?? '');

        return '<aside class="pc-block pc-block--highlight"><strong>' . $badge . '</strong><div>' . $text . '</div></aside>';
    },
    textExtractor: static fn (array $data): string => trim(($data['badge'] ?? '') . ' ' . strip_tags((string) ($data['text'] ?? ''))),
));

// Charger le module ES du bloc dans l'éditeur admin
$context->blocks()->enqueueScript($context->url('js/highlight-block.js'));
```

### Côté JavaScript (`assets/js/highlight-block.js`)

```js
window.Prime?.editor?.registerBlock('mon-plugin/highlight', {
    title: 'Encart',
    description: 'Met en valeur une information clé.',
    icon: 'sparkles',
    category: 'text',
    keywords: ['encart', 'highlight'],
    createDefaultData: () => ({ badge: 'À retenir', text: '' }),
    render(block, ctx) {
        const wrap = document.createElement('div');
        const input = document.createElement('input');
        input.type = 'text';
        input.value = String(block.data?.badge || '');
        input.addEventListener('input', () => {
            block.data.badge = input.value;
            ctx.onChange();
        });
        wrap.appendChild(input);
        return wrap;
    },
});
```

Voir l'implémentation complète dans `content/plugins/hello-prime/` (`hello-prime/highlight`).
