# Médiathèque Prime CMS

La **Médiathèque** de Prime CMS (introduite en Phase 7, v0.7.0) offre un pipeline complet de téléversement sécurisé, de traitement d'image natif via **GD**, d'organisation par dossiers hiérarchiques, de suivi automatique des utilisations et un sélecteur de média réutilisable (`Prime.media.open()`).

Le tout **sans aucune dépendance externe au runtime** (PHP 8.2+ natif + Vanilla ES2022).

---

## 1. Architecture et stockage

- **Stockage canonique hors `public/`** : `content/uploads/YYYY/MM/<slug>-<hex6>.<ext>`
- **Exposition web** :
  1. **Lien symbolique** `public/uploads → ../content/uploads` (créé automatiquement à l'installation et via `php bin/prime media:link`), servi directement par Apache/Nginx sans passer par PHP ;
  2. **Repli PHP sécurisé** `GET|HEAD /uploads/{s1}/{s2}/{s3}` (et 1–4 segments) géré par `Prime\Media\MediaServingController`, avec validation stricte anti-traversée, en-têtes `X-Content-Type-Options: nosniff`, `Content-Security-Policy: ... sandbox`, `Cache-Control: public, max-age=31536000, immutable`, `ETag`/`If-None-Match` (`304`), `HEAD`, `Range` (`206`/`416`) et `Content-Disposition: attachment` pour les fichiers non affichables inline (archives, bureautique).
     - En développement avec le serveur PHP intégré, lancez impérativement `php -S localhost:8080 -t public public/router.php` afin que les URL `/uploads/…` sans lien symbolique atteignent `public/index.php`.
- **Défense en profondeur** : `Prime\Media\UploadsGuard` dépose automatiquement `content/uploads/.htaccess` (`php_flag engine off`, `Options -Indexes`, blocage des fichiers cachés dont `/uploads/.htaccess`, refus absolu des scripts `.php/.phtml/.svg/.html`, `nosniff`, CSP sandbox). La configuration Nginx (`docs/nginx.conf.example`) et Apache (`public/.htaccess`, `docker/php/apache.conf`) bloquent également `/uploads/.htaccess` et tout fichier caché via le lien symbolique.

### Schéma SQL (migrations `000015`–`000018`)

| Table / Colonne | Rôle |
|-----------------|------|
| `pc_media_folders` | Arborescence hiérarchique (`parent_id` `ON DELETE SET NULL`, `slug` unique par parent). |
| `pc_media` | Métadonnées complètes (`disk_path` unique, `mime_type`, `kind`, `size_bytes`, `width`/`height`, `checksum` SHA-256, `alt`, `title`, `caption`, `description`, `focal_x`/`focal_y`, `variants` JSON, index `FULLTEXT` `ft_media_search`). |
| `pc_media_usage` | Références croisées `(media_id, entry_id, context)` (`thumbnail`, `block`, `field:<nom>`), `ON DELETE CASCADE`. |
| `pc_users.avatar_media_id` | Avatar utilisateur lié à `pc_media.id` (`ON DELETE SET NULL`). |

---

## 2. Pipeline de sécurité à l'upload (`UploadService` & `ImageProcessor`)

Chaque fichier soumis passe 8 barrières avant tout enregistrement :

1. **Limite de poids** : `min(MEDIA_MAX_UPLOAD_MB, upload_max_filesize, post_max_size)` ; fichiers vides (0 octet) rejetés.
2. **Nom de fichier & double extension** : rejet des séquences `..`, octets nuls, chemins, caractères de contrôle et doubles extensions exécutables (`shell.php.jpg`).
3. **Liste blanche stricte** (`config/media.php`, filtrable via `media.allowed_types`) :
   - Images : `jpg`, `jpeg`, `png`, `gif`, `webp` (et `avif` si supporté par GD) — **SVG banni par défaut** (vecteur XSS).
   - Documents : `pdf`, `txt`, `csv`, `doc`, `docx`, `xls`, `xlsx`, `ppt`, `pptx`, `odt`, `ods`.
   - Audio / Vidéo / Archives : `mp3`, `ogg`, `wav`, `m4a`, `mp4`, `webm`, `zip`.
4. **Détection MIME réelle** : `finfo_file()` (libmagic) + correspondance obligatoire avec l'extension déclarée.
5. **Garde polyglotte** : rejet des signatures PHP (`<?php`, `<?=`) dans les premiers 16 Ko de tout fichier non exécutable.
6. **Garde « pixel bomb »** : `getimagesize()` avant tout décodage GD ; rejet au-delà de `MEDIA_MAX_PIXELS` (40 Mpx par défaut) ou > 12 000 px de côté.
7. **Ré-encodage GD obligatoire des images** :
   - Auto-rotation EXIF (`Orientation` 3/6/8 via `ext-exif`) ;
   - Suppression totale des métadonnées EXIF/IPTC/GPS et de tout octet résiduel en fin de fichier ;
   - Préservation de la transparence alpha pour PNG/WebP/GIF ;
   - Génération atomique des variantes (`thumbnail` 150×150 recadrée autour du **point focal**, `medium` 480 px, `large` 960 px) + équivalents **WebP** parallèles.
8. **Détection des doublons SHA-256** : calcule `hash_file('sha256', ...)` et signale tout doublon existant sans bloquer l'utilisateur.

---

## 3. Configuration (`config/media.php` & `.env`)

```dotenv
MEDIA_MAX_UPLOAD_MB=20
MEDIA_JPEG_QUALITY=82
MEDIA_WEBP_QUALITY=80
MEDIA_GENERATE_WEBP=true
MEDIA_MAX_PIXELS=40000000
```

Les variantes par défaut sont configurables dans `config/media.php` (`sizes`) et extensibles par les plugins via le filtre `media.image_sizes`.

---

## 4. Contrôle d'accès (RBAC)

| Permission | Rôles par défaut | Droits accordés |
|------------|------------------|-----------------|
| `media.view` | Contributeur, Auteur, Éditeur, Administrateur | Accès à `/admin/media`, consultation, recherche, utilisation du sélecteur dans l'éditeur. |
| `media.upload` | Auteur, Éditeur, Administrateur | Téléversement, gestion des dossiers, édition/remplacement/suppression de **ses propres médias**. |
| `media.delete_any` | Éditeur, Administrateur | Édition, déplacement, remplacement, régénération et suppression de **tous les médias** (y compris actions groupées). |

---

## 5. Helpers de gabarits et rendu responsive

Disponibles en fonctions globales (`src/Support/helpers.php`) et sur `$this` dans les vues (`Prime\View\TemplateContext`) :

```php
// URL directe ou d'une variante (avec option format 'webp')
$url = media_url($mediaId, 'large');
$webpUrl = media_url($mediaId, 'medium', 'webp');

// Attribut srcset trié par largeur croissante
$srcset = media_srcset($mediaId);

// Balise complète <picture> (source WebP + fallback <img>, srcset, sizes, width/height, loading="lazy", object-position focal)
echo media_image($mediaId, [
    'variant' => 'large',
    'sizes' => '(max-width: 768px) 100vw, 800px',
    'class' => 'hero-image',
]);

// Dans une vue PHP Prime :
echo $this->raw($this->mediaImage($entry->featuredMediaId(), ['variant' => 'large']));
```

---

## 6. Sélecteur de média réutilisable (`Prime.media.open`)

Côté administration, une **unique** API publique `Prime.media.open(...)` est exposée pour les blocs de Prime Editor, les champs personnalisés `media`, l'image à la une, les avatars et les plugins :

```js
Prime.media.open({
  multiple: false,          // true pour sélection multiple (ex. galerie)
  kinds: ['image'],         // filtre optionnel : image, document, audio, video, archive
  selected: [42],           // identifiants déjà sélectionnés (optionnel)
  title: 'Choisir une image',
  onSelect: (selection) => {
    // Objet média unique si multiple: false ; tableau Media[] si multiple: true
    const media = Array.isArray(selection) ? selection[0] : selection;
    console.log(media.id, media.url, media.thumbnail_url, media.alt);
  },
});
```

Déclaration progressive dans un formulaire HTML via `data-prime-media-field` (voir `resources/views/admin/content/edit.php` et `resources/views/admin/content/partials/field-group.php`).

---

## 7. Commandes CLI (`bin/prime`)

```bash
# Régénérer toutes les variantes d'images (miniatures + WebP)
php bin/prime media:regenerate
php bin/prime media:regenerate --id=42 --variant=thumbnail --force

# Créer ou vérifier le lien symbolique public/uploads et content/uploads/.htaccess
php bin/prime media:link

# Détecter puis nettoyer les fichiers orphelins de content/uploads/
php bin/prime media:cleanup --dry-run
php bin/prime media:cleanup
```
