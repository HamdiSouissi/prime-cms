<?php

declare(strict_types=1);

/**
 * Performance measurements of the phase 12 report (L1).
 *
 *     php bin/prime demo:install --posts=1000 --media=300 --terms=50
 *     php tests/perf/measure.php [--runs=15] [--json]
 *
 * Every page is handled in-process by the HTTP kernel against the configured database, so the
 * time is the server time (no network, no web server). For each URL: one warm-up request, then
 * `--runs` requests; the median is reported, with the SQL query count of one request.
 * Public pages are measured twice: page cache off, then page cache on.
 */

use Prime\Auth\Auth;
use Prime\Auth\UserRepository;
use Prime\Cache\PageCache;
use Prime\Database\Connection;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Kernel\Application;

require dirname(__DIR__, 2) . '/bootstrap/autoload.php';

$options = getopt('', ['runs::', 'json']);
$runs = max(3, (int) ($options['runs'] ?? 15));

$app = new Application(dirname(__DIR__, 2));
$app->config()->set('app.debug', false);
$app->boot();

$db = $app->get(Connection::class);
$kernel = $app->get(Kernel::class);
$pageCache = $app->get(PageCache::class);

$post = $db->table('entries')->where('type', '=', 'post')->where('status', '=', 'published')->orderBy('id', 'DESC')->first();
$term = $db->table('terms')->where('taxonomy', '=', 'category')->orderBy('count', 'DESC')->first();
$postSlug = is_array($post) ? (string) $post['slug'] : '';
$termSlug = is_array($term) ? (string) $term['slug'] : '';

$counts = [
    'entries' => $db->table('entries')->count(),
    'media' => $db->table('media')->count(),
    'terms' => $db->table('terms')->count(),
];

/**
 * @return array{ms: float, queries: int, status: int}
 */
$measure = static function (string $uri) use ($kernel, $db, $runs): array {
    $kernel->handle(Request::create('GET', $uri, headers: ['Accept' => 'text/html']));

    $db->enableQueryLog(true);
    $db->clearQueryLog();
    $response = $kernel->handle(Request::create('GET', $uri, headers: ['Accept' => 'text/html']));
    $queries = count($db->queryLog());
    $db->enableQueryLog(false);

    $times = [];
    for ($i = 0; $i < $runs; ++$i) {
        $start = hrtime(true);
        $kernel->handle(Request::create('GET', $uri, headers: ['Accept' => 'text/html']));
        $times[] = (hrtime(true) - $start) / 1e6;
    }
    sort($times);

    return ['ms' => round($times[intdiv(count($times), 2)], 1), 'queries' => $queries, 'status' => $response->status()];
};

$public = [
    'Accueil' => '/',
    'Article' => '/blog/' . $postSlug,
    'Archive (catégorie)' => '/categorie/' . $termSlug,
    'Page 404' => '/page-introuvable-perf',
    'Blog' => '/blog',
];

$rows = [];
foreach ($public as $label => $uri) {
    $pageCache->setEnabled(false);
    $cold = $measure($uri);
    $pageCache->setEnabled(true);
    $warm = $measure($uri);
    $rows[] = ['page' => $label, 'uri' => $uri, 'status' => $cold['status'], 'no_cache_ms' => $cold['ms'], 'cache_ms' => $warm['ms'], 'queries' => $cold['queries']];
}
$pageCache->setEnabled(false);
$rows[] = ['page' => 'API /api/v1/content/post', 'uri' => '/api/v1/content/post'] + (static function () use ($measure): array {
    $m = $measure('/api/v1/content/post');

    return ['status' => $m['status'], 'no_cache_ms' => $m['ms'], 'cache_ms' => null, 'queries' => $m['queries']];
})();

// Admin pages: logged in as the first administrator.
$adminId = $db->table('user_roles')
    ->join('roles', 'roles.id', '=', 'user_roles.role_id')
    ->where('roles.slug', '=', 'administrator')
    ->value('user_roles.user_id');
$admin = is_numeric($adminId) ? $app->get(UserRepository::class)->find((int) $adminId) : null;
if ($admin !== null) {
    $app->get(Auth::class)->login($admin);
    foreach (['Tableau de bord' => '/admin', 'Liste des contenus' => '/admin/content/post', 'Médiathèque' => '/admin/media'] as $label => $uri) {
        $m = $measure($uri);
        $rows[] = ['page' => $label, 'uri' => $uri, 'status' => $m['status'], 'no_cache_ms' => $m['ms'], 'cache_ms' => null, 'queries' => $m['queries']];
    }
}

if (isset($options['json'])) {
    echo json_encode(['dataset' => $counts, 'runs' => $runs, 'php' => PHP_VERSION, 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}

printf("Jeu de données : %d contenus, %d médias, %d termes — médiane de %d requêtes, PHP %s\n\n", $counts['entries'], $counts['media'], $counts['terms'], $runs, PHP_VERSION);
echo "| Page | Statut | Sans cache (ms) | Avec cache (ms) | Requêtes SQL |\n|---|---|---|---|---|\n";
foreach ($rows as $row) {
    printf("| %s | %d | %s | %s | %d |\n", $row['page'], $row['status'], $row['no_cache_ms'], $row['cache_ms'] ?? '—', $row['queries']);
}
