<?php

declare(strict_types=1);

namespace Prime\Demo;

use Prime\Auth\User;
use Prime\Auth\UserRepository;
use Prime\Cache\CacheManager;
use Prime\Content\EntryRepository;
use Prime\Content\EntryService;
use Prime\Content\TermRepository;
use Prime\Database\Connection;
use Prime\Kernel\Application;
use Prime\Media\MediaRepository;
use Prime\Media\UploadService;
use Prime\Settings\Settings;
use Prime\Site\Menu\MenuRepository;
use Prime\Site\Zone\ZoneRepository;
use RuntimeException;
use Throwable;

/**
 * Demonstration content (`demo:install` / `demo:remove`, option of the installer).
 *
 * Everything created is listed in the `demo.manifest` setting — entries, terms, media, menus,
 * the zones and reading settings it replaced — so that removal deletes exactly that and puts
 * the previous values back. Images are drawn with GD: abstract compositions, no photographs.
 *
 * The counts can be raised (`--posts=1000 --media=300 --terms=50`) to build the dataset the
 * performance measurements use.
 */
final class DemoContent
{
    public const MANIFEST = 'demo.manifest';

    private const CATEGORIES = ['Actualités', 'Guides', 'Design', 'Technique', 'Communauté', 'Coulisses', 'Tutoriels', 'Études de cas', 'Sécurité', 'Performance'];

    private const TAGS = ['php', 'mysql', 'accessibilité', 'seo', 'thèmes', 'plugins', 'api', 'éditeur', 'médias', 'cache', 'traduction', 'sécurité', 'performances', 'menus', 'zones', 'réglages'];

    private const TITLES = [
        'Bienvenue sur votre nouveau site',
        'Cinq conseils pour écrire des titres clairs',
        'Organiser ses contenus avec les catégories',
        'Choisir des images qui chargent vite',
        'Préparer une page d’accueil efficace',
        'Rendre son site accessible à tous',
        'Comprendre les permaliens',
        'Les menus et les zones du thème Aurora',
        'Publier à heure fixe avec la planification',
        'Garder un œil sur l’état du système',
    ];

    /** Earthy, desaturated pairs: the images stay calm next to the interface. */
    private const PALETTES = [
        [[201, 68, 31], [250, 233, 222]],
        [[38, 110, 104], [222, 241, 236]],
        [[52, 92, 160], [226, 234, 248]],
        [[120, 84, 160], [238, 230, 248]],
        [[176, 120, 40], [250, 240, 220]],
        [[60, 66, 84], [232, 234, 240]],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly EntryService $entries,
        private readonly EntryRepository $entryRepository,
        private readonly TermRepository $terms,
        private readonly MediaRepository $media,
        private readonly UploadService $uploads,
        private readonly MenuRepository $menus,
        private readonly ZoneRepository $zones,
        private readonly Settings $settings,
        private readonly UserRepository $users,
        private readonly ?CacheManager $cache = null,
    ) {
    }

    public static function fromApplication(Application $app): self
    {
        return new self(
            db: $app->get(Connection::class),
            entries: $app->get(EntryService::class),
            entryRepository: $app->get(EntryRepository::class),
            terms: $app->get(TermRepository::class),
            media: $app->get(MediaRepository::class),
            uploads: $app->get(UploadService::class),
            menus: $app->get(MenuRepository::class),
            zones: $app->get(ZoneRepository::class),
            settings: $app->get(Settings::class),
            users: $app->get(UserRepository::class),
            cache: $app->container()->has(CacheManager::class) ? $app->get(CacheManager::class) : null,
        );
    }

    public function isInstalled(): bool
    {
        return is_array($this->settings->get(self::MANIFEST));
    }

    /**
     * @return array{entries: int, terms: int, media: int, menus: int}
     */
    public function install(int $posts = 10, int $mediaCount = 6, int $termCount = 12, ?callable $progress = null): array
    {
        if ($this->isInstalled()) {
            throw new RuntimeException('Le contenu de démonstration est déjà installé : lancez d’abord demo:remove.');
        }
        if (!function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('L’extension GD est nécessaire pour générer les images de démonstration.');
        }

        $actor = $this->administrator();
        $manifest = ['entries' => [], 'terms' => [], 'media' => [], 'menus' => [], 'zones' => [], 'settings' => [], 'locations' => []];
        // Saved first: an interruption still leaves a manifest that demo:remove can follow.
        $this->settings->set(self::MANIFEST, $manifest, false);

        try {
            $this->installContent($actor, max(1, $posts), max(1, $mediaCount), max(2, $termCount), $manifest, $progress);
        } finally {
            $this->settings->set(self::MANIFEST, $manifest, false);
            $this->cache?->clearPages();
        }

        return [
            'entries' => count($manifest['entries']),
            'terms' => count($manifest['terms']),
            'media' => count($manifest['media']),
            'menus' => count($manifest['menus']),
        ];
    }

    /**
     * @return array{entries: int, terms: int, media: int, menus: int}
     */
    public function remove(): array
    {
        $manifest = $this->settings->get(self::MANIFEST);
        if (!is_array($manifest)) {
            return ['entries' => 0, 'terms' => 0, 'media' => 0, 'menus' => 0];
        }

        $counts = ['entries' => 0, 'terms' => 0, 'media' => 0, 'menus' => 0];

        foreach (self::ids($manifest['menus'] ?? []) as $id) {
            $this->menus->delete($id);
            ++$counts['menus'];
        }
        $locations = is_array($manifest['locations'] ?? null) ? $manifest['locations'] : [];
        foreach ($locations as $location => $menuId) {
            $menu = is_numeric($menuId) ? $this->menus->find((int) $menuId) : null;
            if ($menu !== null) {
                $this->menus->update((int) $menuId, $menu['name'], (string) $location);
            }
        }
        foreach (self::ids($manifest['entries'] ?? []) as $id) {
            $counts['entries'] += $this->entryRepository->deletePermanent($id) > 0 ? 1 : 0;
        }
        foreach (self::ids($manifest['terms'] ?? []) as $id) {
            if ($this->terms->find($id) !== null) {
                $this->terms->delete($id);
                ++$counts['terms'];
            }
        }
        foreach (self::ids($manifest['media'] ?? []) as $id) {
            $item = $this->media->find($id);
            if ($item !== null) {
                $this->uploads->delete($item);
                ++$counts['media'];
            }
        }

        $zones = is_array($manifest['zones'] ?? null) ? $manifest['zones'] : [];
        foreach ($zones as $slug => $previous) {
            $this->zones->save((string) $slug, is_array($previous) ? $previous : ['version' => 1, 'blocks' => []], true);
        }
        $settings = is_array($manifest['settings'] ?? null) ? $manifest['settings'] : [];
        foreach ($settings as $name => $previous) {
            $this->settings->set((string) $name, $previous);
        }

        $this->settings->delete(self::MANIFEST);
        $this->cache?->clearPages();

        return $counts;
    }

    /**
     * @param array{entries: list<int>, terms: list<int>, media: list<int>, menus: list<int>, zones: array<string, mixed>, settings: array<string, mixed>, locations?: array<string, int>} $manifest
     */
    private function installContent(User $actor, int $posts, int $mediaCount, int $termCount, array &$manifest, ?callable $progress): void
    {
        // 1. Images.
        $mediaIds = [];
        for ($i = 0; $i < $mediaCount; ++$i) {
            $mediaIds[] = $this->generateImage($i, $actor);
            $manifest['media'][] = end($mediaIds);
            if ($progress !== null && ($i + 1) % 25 === 0) {
                $progress(sprintf('%d image(s)', $i + 1));
            }
        }

        // 2. Categories and tags.
        $categoryCount = max(1, intdiv($termCount, 4));
        $categories = [];
        $tags = [];
        for ($i = 0; $i < $termCount; ++$i) {
            $isCategory = $i < $categoryCount;
            $pool = $isCategory ? self::CATEGORIES : self::TAGS;
            $index = $isCategory ? $i : $i - $categoryCount;
            $name = $pool[$index % count($pool)] . ($index >= count($pool) ? ' ' . (intdiv($index, count($pool)) + 1) : '');
            $term = $this->terms->create($isCategory ? 'category' : 'tag', $name, null, $isCategory ? 'Articles de la rubrique ' . $name . '.' : null);
            $manifest['terms'][] = $term->id;
            if ($isCategory) {
                $categories[] = $term->id;
            } else {
                $tags[] = $term->id;
            }
        }

        // 3. Pages.
        $home = $this->createEntry('page', 'Accueil', [
            self::heading('Un site prêt à accueillir vos contenus'),
            self::paragraph('Cette page d’accueil est un exemple : modifiez-la ou supprimez tout le contenu de démonstration avec <code>php bin/prime demo:remove</code>.'),
            self::listBlock(['Des articles classés par catégories et étiquettes', 'Des menus et des zones déjà remplis', 'Des images générées, libres de droits']),
        ], $actor, $manifest, [], 0, $mediaIds[0]);
        $about = $this->createEntry('page', 'À propos', [
            self::paragraph('Nous racontons ici qui nous sommes, ce que nous faisons et pourquoi nous le faisons.'),
            self::quote('Un bon site se lit d’abord, il se regarde ensuite.', 'L’équipe éditoriale'),
        ], $actor, $manifest);
        $contact = $this->createEntry('page', 'Contact', [
            self::paragraph('Écrivez-nous à <a href="mailto:bonjour@example.com">bonjour@example.com</a> : nous répondons sous deux jours ouvrés.'),
        ], $actor, $manifest);

        // 4. Posts.
        for ($i = 0; $i < $posts; ++$i) {
            $title = self::TITLES[$i % count(self::TITLES)] . ($i >= count(self::TITLES) ? ' (' . (intdiv($i, count(self::TITLES)) + 1) . ')' : '');
            $mediaId = $mediaIds[$i % count($mediaIds)];
            $termIds = ['category' => [$categories[$i % count($categories)]]];
            if ($tags !== []) {
                $termIds['tag'] = array_values(array_unique([$tags[$i % count($tags)], $tags[($i * 7 + 3) % count($tags)]]));
            }
            $this->createEntry('post', $title, [
                self::paragraph('Cet article de démonstration montre la mise en page des textes, des intertitres, des listes et des images.'),
                self::heading('Ce qu’il faut retenir', 3),
                self::listBlock(['Un intertitre tous les trois ou quatre paragraphes', 'Des phrases courtes', 'Une image décrite pour les lecteurs d’écran']),
                self::image($mediaId, 'Illustration abstraite de l’article'),
                self::paragraph('Vous pouvez modifier ou supprimer cet article librement : il sera de toute façon retiré par <code>demo:remove</code>.'),
            ], $actor, $manifest, $termIds, $i, $mediaId);
            if ($progress !== null && ($i + 1) % 100 === 0) {
                $progress(sprintf('%d article(s)', $i + 1));
            }
        }

        // 5. Menus: the demo menus take the theme locations; the menus that held them get them back on removal.
        $primary = $this->createMenu('Navigation (démo)', 'primary', $manifest);
        $this->menus->replaceItems($primary, [
            ['type' => MenuRepository::TYPE_ENTRY, 'target_id' => $home, 'label' => 'Accueil'],
            ['type' => MenuRepository::TYPE_ARCHIVE, 'target_id' => 0, 'url' => 'post', 'label' => 'Blog'],
            ['type' => MenuRepository::TYPE_ENTRY, 'target_id' => $about, 'label' => 'À propos'],
            ['type' => MenuRepository::TYPE_ENTRY, 'target_id' => $contact, 'label' => 'Contact'],
        ]);
        $footer = $this->createMenu('Pied de page (démo)', 'footer', $manifest);
        $this->menus->replaceItems($footer, [
            ['type' => MenuRepository::TYPE_ENTRY, 'target_id' => $about, 'label' => 'À propos'],
            ['type' => MenuRepository::TYPE_ENTRY, 'target_id' => $contact, 'label' => 'Contact'],
        ]);

        // 6. Zones (previous content kept for demo:remove).
        $this->replaceZone('sidebar', [
            ['id' => 'demo_latest', 'type' => 'dynamic', 'data' => ['name' => 'latest_entries', 'attrs' => ['title' => 'Derniers articles', 'type' => 'post', 'limit' => 5]]],
            ['id' => 'demo_terms', 'type' => 'dynamic', 'data' => ['name' => 'term_list', 'attrs' => ['title' => 'Catégories', 'taxonomy' => 'category']]],
        ], $manifest);
        $this->replaceZone('footer_1', [self::paragraph('Site de démonstration propulsé par Prime CMS.')], $manifest);

        // 7. Reading settings: the « Accueil » page becomes the front page.
        foreach (['reading.front_mode' => 'page', 'reading.front_page_id' => $home] as $name => $value) {
            $manifest['settings'][$name] = $this->settings->get($name);
            $this->settings->set($name, $value);
        }
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param array{entries: list<int>, terms: list<int>, media: list<int>, menus: list<int>, zones: array<string, mixed>, settings: array<string, mixed>, locations?: array<string, int>} $manifest
     * @param array<string, list<int>> $terms
     */
    private function createEntry(string $type, string $title, array $blocks, User $actor, array &$manifest, array $terms = [], int $age = 0, ?int $featured = null): int
    {
        $input = [
            'title' => $title,
            'status' => 'published',
            'content' => (string) json_encode(['version' => 1, 'blocks' => $blocks], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'published_at' => gmdate('Y-m-d H:i:s', time() - ($age + 1) * 3600 * 9),
            'terms' => $terms,
        ];
        if ($featured !== null) {
            $input['featured_media_id'] = $featured;
        }
        if ($type === 'post') {
            $input['excerpt'] = 'Un article de démonstration pour découvrir la mise en page du thème.';
        }

        $entry = $this->entries->create($type, $input, $actor);
        $manifest['entries'][] = $entry->id;

        return $entry->id;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param array{entries: list<int>, terms: list<int>, media: list<int>, menus: list<int>, zones: array<string, mixed>, settings: array<string, mixed>, locations?: array<string, int>} $manifest
     */
    private function replaceZone(string $slug, array $blocks, array &$manifest): void
    {
        try {
            $manifest['zones'][$slug] = $this->zones->document($slug);
            $this->zones->save($slug, ['version' => 1, 'blocks' => $blocks], true);
        } catch (Throwable) {
            unset($manifest['zones'][$slug]);
        }
    }

    /**
     * @param array{entries: list<int>, terms: list<int>, media: list<int>, menus: list<int>, zones: array<string, mixed>, settings: array<string, mixed>, locations?: array<string, int>} $manifest
     */
    private function createMenu(string $name, string $location, array &$manifest): int
    {
        $current = $this->menus->findByLocation($location);
        if ($current !== null) {
            $manifest['locations'][$location] = $current['id'];
            $this->menus->update($current['id'], $current['name'], null);
        }
        $id = $this->menus->create($name, $location);
        $manifest['menus'][] = $id;

        return $id;
    }

    private function generateImage(int $index, User $actor): int
    {
        [$ink, $paper] = self::PALETTES[$index % count(self::PALETTES)];
        $width = 1200;
        $height = 675;
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new RuntimeException('GD n’a pas pu créer l’image.');
        }

        // Soft vertical gradient from the paper colour.
        for ($y = 0; $y < $height; ++$y) {
            $t = $y / $height;
            $color = (int) imagecolorallocate($image, self::channel($paper[0] - 18 * $t), self::channel($paper[1] - 18 * $t), self::channel($paper[2] - 18 * $t));
            imageline($image, 0, $y, $width, $y, $color);
        }

        // A few overlapping shapes in the ink colour, at decreasing opacity.
        mt_srand($index * 7919 + 17);
        for ($s = 0; $s < 5; ++$s) {
            $alpha = 40 + $s * 15;
            $color = (int) imagecolorallocatealpha($image, $ink[0], $ink[1], $ink[2], min(120, $alpha));
            $size = mt_rand(180, 520);
            if ($s % 2 === 0) {
                imagefilledellipse($image, mt_rand(0, $width), mt_rand(0, $height), $size, $size, $color);
            } else {
                $x = mt_rand(-100, $width - 100);
                $y = mt_rand(-100, $height - 100);
                imagefilledrectangle($image, $x, $y, $x + $size, $y + (int) ($size * 0.6), $color);
            }
        }
        mt_srand();

        $tmp = tempnam(sys_get_temp_dir(), 'prime_demo_');
        if ($tmp === false) {
            throw new RuntimeException('Impossible de créer un fichier temporaire.');
        }
        imagejpeg($image, $tmp, 82);
        imagedestroy($image);

        try {
            $result = $this->uploads->upload([
                'name' => sprintf('demo-composition-%03d.jpg', $index + 1),
                'tmp_name' => $tmp,
                'size' => (int) filesize($tmp),
                'error' => UPLOAD_ERR_OK,
                'type' => 'image/jpeg',
            ], $actor, null, [
                'title' => sprintf('Composition %d', $index + 1),
                'alt' => 'Composition abstraite de formes colorées',
            ]);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }

        return $result['media']->id;
    }

    /**
     * @return int<0, 255>
     */
    private static function channel(float $value): int
    {
        return max(0, min(255, (int) round($value)));
    }

    private function administrator(): User
    {
        $row = $this->db->table('users')
            ->select(['users.id'])
            ->join('user_roles', 'user_roles.user_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('roles.slug', '=', 'administrator')
            ->where('users.status', '=', 'active')
            ->orderBy('users.id', 'ASC')
            ->first();
        $user = is_array($row) && is_numeric($row['id'] ?? null) ? $this->users->find((int) $row['id']) : null;
        if ($user === null) {
            throw new RuntimeException('Aucun administrateur actif : créez-en un avant d’installer la démonstration.');
        }

        return $user;
    }

    /**
     * @return list<int>
     */
    private static function ids(mixed $list): array
    {
        return is_array($list) ? array_values(array_map('intval', array_filter($list, 'is_numeric'))) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function paragraph(string $html): array
    {
        return ['id' => 'b_' . bin2hex(random_bytes(4)), 'type' => 'paragraph', 'data' => ['text' => $html]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function heading(string $text, int $level = 2): array
    {
        return ['id' => 'b_' . bin2hex(random_bytes(4)), 'type' => 'heading', 'data' => ['text' => $text, 'level' => $level]];
    }

    /**
     * @param list<string> $items
     * @return array<string, mixed>
     */
    private static function listBlock(array $items): array
    {
        return ['id' => 'b_' . bin2hex(random_bytes(4)), 'type' => 'list', 'data' => ['style' => 'unordered', 'items' => $items]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function quote(string $text, string $citation): array
    {
        return ['id' => 'b_' . bin2hex(random_bytes(4)), 'type' => 'quote', 'data' => ['text' => $text, 'citation' => $citation]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function image(int $mediaId, string $alt): array
    {
        return ['id' => 'b_' . bin2hex(random_bytes(4)), 'type' => 'image', 'data' => ['mediaId' => $mediaId, 'alt' => $alt, 'caption' => '']];
    }
}
