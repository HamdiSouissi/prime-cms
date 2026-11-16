<?php

declare(strict_types=1);

/**
 * Prime CMS — Demo post fixture « Tous les blocs » containing every Prime Editor block (C4).
 *
 * Usage (CLI):
 *   php database/seeds/tous-les-blocs.php
 *
 * Usage (programmatic):
 *   $builder = require __DIR__ . '/database/seeds/tous-les-blocs.php';
 *   $doc = $builder(['imageUrl' => ..., 'galleryUrls' => [...], ...]);
 */

/**
 * Build the complete Prime Editor document showcasing every core block plus a dynamic block.
 *
 * @param array{
 *     imageUrl?: string,
 *     imageMediaId?: int,
 *     galleryItems?: list<array{url: string, alt: string, caption: string, mediaId?: int}>,
 *     videoUrl?: string,
 *     posterUrl?: string,
 *     audioUrl?: string,
 *     fileUrl?: string
 * } $assets
 * @return array{version: int, blocks: list<array{id: string, type: string, data: array<string, mixed>}>}
 */
$buildAllBlocksDocument = static function (array $assets = []): array {
    $imageUrl = $assets['imageUrl'] ?? '/assets/themes/aurora/screenshot.webp';
    $galleryItems = $assets['galleryItems'] ?? [
        ['url' => $imageUrl, 'alt' => 'Atelier typographique lumineux', 'caption' => 'Composition éditoriale'],
        ['url' => $imageUrl, 'alt' => 'Cahier de maquettes Prime CMS', 'caption' => 'Grille responsive'],
        ['url' => $imageUrl, 'alt' => 'Palette chaude Aurora', 'caption' => 'Nuances et contrastes'],
        ['url' => $imageUrl, 'alt' => 'Détail de mise en page mobile', 'caption' => 'Adaptation 375px'],
    ];
    $videoUrl = $assets['videoUrl'] ?? '/uploads/demo/aurora-demo.mp4';
    $posterUrl = $assets['posterUrl'] ?? $imageUrl;
    $audioUrl = $assets['audioUrl'] ?? '/uploads/demo/aurora-podcast.wav';
    $fileUrl = $assets['fileUrl'] ?? '/uploads/demo/guide-aurora.pdf';

    $imageData = [
        'url' => $imageUrl,
        'alt' => 'Présentation du thème éditorial Aurora sur grand écran',
        'decorative' => false,
        'caption' => 'Bloc Image — largeur éditoriale avec légende centrée.',
        'alignment' => 'normal',
    ];
    if (isset($assets['imageMediaId']) && $assets['imageMediaId'] > 0) {
        $imageData['mediaId'] = $assets['imageMediaId'];
    }

    return [
        'version' => 1,
        'blocks' => [
            [
                'id' => 'demo_p_intro',
                'type' => 'paragraph',
                'data' => [
                    'text' => 'Cet article de démonstration réunit <strong>l’ensemble des blocs de Prime Editor</strong> afin de valider le rythme typographique, les médias responsives et l’accessibilité du thème <em>Aurora</em> en desktop (1440px) comme sur mobile (375px).',
                ],
            ],
            [
                'id' => 'demo_h2_typo',
                'type' => 'heading',
                'data' => ['level' => 2, 'text' => 'Titres, paragraphes et listes', 'anchor' => 'titres-et-listes'],
            ],
            [
                'id' => 'demo_p_typo',
                'type' => 'paragraph',
                'data' => [
                    'text' => 'Aurora privilégie une mesure de lecture confortable (~68 caractères), un interlignage généreux et un contraste conforme WCAG AA. Le code en ligne comme <code>ThemeContext::script()</code> reste parfaitement lisible.',
                ],
            ],
            [
                'id' => 'demo_h3_lists',
                'type' => 'heading',
                'data' => ['level' => 3, 'text' => 'Listes à puces et numérotées', 'anchor' => 'listes'],
            ],
            [
                'id' => 'demo_ul',
                'type' => 'list',
                'data' => [
                    'style' => 'unordered',
                    'items' => [
                        [
                            'text' => 'Architecture <strong>PHP 8.3 strict</strong> sans dépendance externe',
                            'children' => [
                                ['text' => 'Chargement isolé des thèmes avec bascule de sécurité', 'children' => []],
                                ['text' => 'Options publiées en CSS statique versionné', 'children' => []],
                            ],
                        ],
                        ['text' => 'Navigation accessible au clavier et menu mobile fluide', 'children' => []],
                        ['text' => 'SEO natif : Open Graph, JSON-LD, sitemap XML avec images', 'children' => []],
                    ],
                ],
            ],
            [
                'id' => 'demo_ol',
                'type' => 'list',
                'data' => [
                    'style' => 'ordered',
                    'items' => [
                        ['text' => 'Installer ou activer Aurora depuis Apparence › Thèmes.', 'children' => []],
                        ['text' => 'Ajuster la couleur d’accent et la typographie dans le personnalisateur.', 'children' => []],
                        ['text' => 'Composer la barre latérale avec les blocs dynamiques.', 'children' => []],
                    ],
                ],
            ],
            [
                'id' => 'demo_h4_quote',
                'type' => 'heading',
                'data' => ['level' => 4, 'text' => 'Citation et encarts d’attention', 'anchor' => 'citation-encarts'],
            ],
            [
                'id' => 'demo_quote',
                'type' => 'quote',
                'data' => [
                    'text' => 'Un bon design éditorial s’efface devant le texte tout en offrant une structure claire à chaque type de média.',
                    'citation' => 'Équipe design Prime CMS',
                ],
            ],
            [
                'id' => 'demo_callout_info',
                'type' => 'callout',
                'data' => [
                    'variant' => 'info',
                    'title' => 'Astuce éditoriale',
                    'text' => 'Tapez <code>/</code> dans Prime Editor pour insérer rapidement n’importe quel bloc, y compris les blocs dynamiques.',
                ],
            ],
            [
                'id' => 'demo_callout_warn',
                'type' => 'callout',
                'data' => [
                    'variant' => 'warning',
                    'title' => 'Point de vigilance responsive',
                    'text' => 'Vérifiez toujours les tableaux larges et les galeries sous 640px afin d’éviter tout débordement horizontal.',
                ],
            ],
            [
                'id' => 'demo_sep_1',
                'type' => 'separator',
                'data' => [],
            ],
            [
                'id' => 'demo_h2_media',
                'type' => 'heading',
                'data' => ['level' => 2, 'text' => 'Image, galerie, vidéo, audio et fichier', 'anchor' => 'medias'],
            ],
            [
                'id' => 'demo_image',
                'type' => 'image',
                'data' => $imageData,
            ],
            [
                'id' => 'demo_h3_gallery',
                'type' => 'heading',
                'data' => ['level' => 3, 'text' => 'Galerie 3 colonnes (2 colonnes sous 640px)', 'anchor' => 'galerie'],
            ],
            [
                'id' => 'demo_gallery_3',
                'type' => 'gallery',
                'data' => [
                    'columns' => 3,
                    'caption' => 'Galerie 3 colonnes : 3 vignettes par ligne sur desktop, 2 colonnes sur mobile.',
                    'items' => array_slice($galleryItems, 0, 3),
                ],
            ],
            [
                'id' => 'demo_gallery_2',
                'type' => 'gallery',
                'data' => [
                    'columns' => 2,
                    'caption' => 'Galerie 2 colonnes pour la comparaison visuelle.',
                    'items' => array_slice($galleryItems, 0, 2),
                ],
            ],
            [
                'id' => 'demo_video',
                'type' => 'video',
                'data' => [
                    'url' => $videoUrl,
                    'mimeType' => 'video/mp4',
                    'posterUrl' => $posterUrl,
                    'caption' => 'Bloc Vidéo natif en largeur 100 % avec coins arrondis.',
                    'controls' => true,
                    'muted' => true,
                    'loop' => false,
                ],
            ],
            [
                'id' => 'demo_audio',
                'type' => 'audio',
                'data' => [
                    'url' => $audioUrl,
                    'title' => 'Épisode 01 — Concevoir un thème rapide avec Aurora',
                    'caption' => 'Lecteur audio accessible pleine largeur.',
                ],
            ],
            [
                'id' => 'demo_file',
                'type' => 'file',
                'data' => [
                    'url' => $fileUrl,
                    'label' => 'Guide de démarrage Aurora (PDF)',
                    'filename' => 'guide-aurora.pdf',
                    'extension' => 'pdf',
                    'sizeBytes' => 42600,
                ],
            ],
            [
                'id' => 'demo_h2_data',
                'type' => 'heading',
                'data' => ['level' => 2, 'text' => 'Tableau, code, intégration et HTML', 'anchor' => 'donnees-et-code'],
            ],
            [
                'id' => 'demo_table',
                'type' => 'table',
                'data' => [
                    'hasHeader' => true,
                    'rows' => [
                        ['Bloc', 'Rendu', 'Responsive 375px', 'Accessibilité', 'Poids CSS'],
                        ['Galerie', 'CSS Grid sur .pc-gallery__grid', '2 colonnes < 640px', 'alt + figcaption', 'Minimal'],
                        ['Tableau', 'En-tête distinct + bordures', 'Défilement horizontal', 'thead / th / tbody', 'Minimal'],
                        ['Vidéo & Audio', 'Largeur 100 % arrondie', 'Fluide sans débordement', 'controls + preload', 'Minimal'],
                        ['Bloc dynamique', 'Rendu serveur live', 'Pile verticale', 'section + aria-label', 'Minimal'],
                    ],
                ],
            ],
            [
                'id' => 'demo_code',
                'type' => 'code',
                'data' => [
                    'language' => 'php',
                    'code' => "<?php\n\n\$context->style('aurora-main', 'css/aurora.css');\n\$context->script('aurora-main', 'js/aurora.js', [], ['defer' => true]);\n",
                ],
            ],
            [
                'id' => 'demo_embed',
                'type' => 'embed',
                'data' => [
                    'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                    'title' => 'Présentation vidéo intégrée (YouTube nocookie)',
                ],
            ],
            [
                'id' => 'demo_html',
                'type' => 'html',
                'data' => [
                    'html' => '<div class="pc-callout"><p><strong>Bloc HTML personnalisé</strong> : permet d’intégrer un balisage éditorial contrôlé et assaini.</p></div>',
                ],
            ],
            [
                'id' => 'demo_h2_dyn',
                'type' => 'heading',
                'data' => ['level' => 2, 'text' => 'Bloc dynamique dans un article', 'anchor' => 'bloc-dynamique'],
            ],
            [
                'id' => 'demo_dynamic',
                'type' => 'dynamic',
                'data' => [
                    'name' => 'latest_entries',
                    'attrs' => [
                        'title' => 'À lire également',
                        'type' => 'post',
                        'limit' => 3,
                        'show_image' => true,
                        'show_excerpt' => true,
                    ],
                ],
            ],
        ],
    ];
};

// When executed directly via CLI on an installed Prime CMS instance, seed or update the post.
if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $root = dirname(__DIR__, 2);
    require $root . '/bootstrap/autoload.php';
    /** @var \Prime\Kernel\Application $app */
    $app = require $root . '/bootstrap/app.php';
    $app->boot();

    /** @var \Prime\Content\EntryRepository $entries */
    $entries = $app->get(\Prime\Content\EntryRepository::class);
    /** @var \Prime\Content\EntryService $service */
    $service = $app->get(\Prime\Content\EntryService::class);
    /** @var \Prime\Auth\UserRepository $users */
    $users = $app->get(\Prime\Auth\UserRepository::class);

    $admin = $users->all()[0] ?? null;
    if ($admin === null) {
        fwrite(STDERR, "Aucun utilisateur trouvé en base : installez d'abord Prime CMS.\n");
        exit(1);
    }

    $doc = $buildAllBlocksDocument();
    $existing = $entries->findBySlug('post', 'tous-les-blocs', parentId: 0);
    $payload = [
        'title' => 'Tous les blocs',
        'slug' => 'tous-les-blocs',
        'excerpt' => 'Article vitrine rassemblant l’ensemble des blocs de Prime Editor dans le thème Aurora.',
        'content' => $doc,
        'status' => \Prime\Content\Entry::STATUS_PUBLISHED,
        'published_at' => gmdate('Y-m-d H:i:s'),
    ];

    if ($existing !== null) {
        $entry = $service->update($existing->id, $payload, $admin);
        fwrite(STDOUT, sprintf("Article mis à jour : #%d (%s)\n", $entry->id, $entry->url()));
    } else {
        $entry = $service->create('post', $payload, $admin);
        fwrite(STDOUT, sprintf("Article créé : #%d (%s)\n", $entry->id, $entry->url()));
    }
}

return $buildAllBlocksDocument;
