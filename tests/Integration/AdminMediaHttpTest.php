<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Prime\Auth\Auth;
use Prime\Auth\UserRepository;
use Prime\Console\ConsoleApplication;
use Prime\Console\ConsoleOutput;
use Prime\Content\Fields\Field;
use Prime\Content\Fields\FieldGroup;
use Prime\Content\Fields\FieldRegistry;
use Prime\Database\Connection;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Http\Session;
use Prime\Install\InstallCheckerInterface;
use Prime\Kernel\Application;
use Prime\Media\ImageProcessor;
use Prime\Media\MediaConfig;
use Prime\Media\MediaFolderRepository;
use Prime\Media\MediaRepository;
use Prime\Media\MediaServingController;
use Prime\Media\MediaUsageRepository;
use Prime\Media\UploadService;
use Prime\Media\UploadsGuard;
use Prime\Plugins\AssetPublisher;
use Prime\Security\Csrf;
use Prime\Tests\Support\MediaFixtures;
use Prime\Tests\Support\MysqlEmulatingPdo;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Comprehensive Phase 7 HTTP integration test suite covering:
 * - Full RBAC matrix across all 5 roles on all 12 JSON endpoints + CSRF (419)
 * - Rejection of every malicious upload with zero files written to disk
 * - Folder CRUD (create, rename, move, cycle guard, delete with reparenting)
 * - Focal-point crop regeneration & file replacement preserving ID and metadata
 * - Media deletion removing original, all variants/WebP, and cleaning usages
 * - Fallback `/uploads/` serving (200, 304, HEAD, 206, 416, attachment, traversals/dotfiles)
 * - User avatar lifecycle, custom `media` fields (single & multiple), blocks/KPI, and CLI
 */
final class AdminMediaHttpTest extends TestCase
{
    private string $tmpDir = '';

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            AssetPublisher::deleteDirectory($this->tmpDir);
        }
        $this->tmpDir = '';
    }

    public function testAuthenticationAndRbacMatrixAcrossAllTwelveJsonEndpointsAndCsrf(): void
    {
        // 1. Unauthenticated -> /admin/media redirects to /admin/login; JSON API returns 401
        $guestApp = $this->createApp(authenticateAs: null);
        /** @var Kernel $guestKernel */
        $guestKernel = $guestApp->get(Kernel::class);
        $this->assertSame(302, $guestKernel->handle(Request::create('GET', '/admin/media'))->status());
        $this->assertSame(401, $guestKernel->handle(Request::create('GET', '/admin/media/api/items', headers: ['Accept' => 'application/json']))->status());
        $this->assertSame(401, $guestKernel->handle(Request::create('GET', '/admin/media/api/folders', headers: ['Accept' => 'application/json']))->status());

        // Seed 1 admin media & 1 folder so every endpoint can be exercised against real IDs
        $app = $this->createApp(authenticateAs: 'admin');
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        $adminCsrf = $this->csrfToken($app);

        $seedFolderRes = $kernel->handle(Request::create(
            'POST',
            '/admin/media/api/folders',
            body: ['name' => 'Dossier Seed'],
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $adminCsrf],
        ));
        $this->assertSame(201, $seedFolderRes->status());
        $seedFolderId = (int) json_decode($seedFolderRes->body(), true, 512, JSON_THROW_ON_ERROR)['folder']['id'];

        $seedJpg = MediaFixtures::createJpeg($this->tmpDir . '/tmp/seed-admin.jpg', 300, 200);
        $adminUploadRes = $kernel->handle(Request::create(
            'POST',
            '/admin/media/api/upload',
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $adminCsrf],
            files: ['file' => MediaFixtures::uploadedFile($seedJpg, 'admin-asset.jpg', 'image/jpeg')],
        ));
        $this->assertSame(201, $adminUploadRes->status());
        $adminMediaId = (int) json_decode($adminUploadRes->body(), true, 512, JSON_THROW_ON_ERROR)['item']['id'];

        // 2. CSRF protection (419) on EVERY mutating POST endpoint when token is missing
        $mutatingRequests = [
            Request::create('POST', '/admin/media/api/upload', headers: ['Accept' => 'application/json'], files: ['file' => MediaFixtures::uploadedFile($seedJpg, 'nocsrf.jpg', 'image/jpeg')]),
            Request::create('POST', "/admin/media/api/items/{$adminMediaId}", body: ['title' => 'NoCSRF'], headers: ['Accept' => 'application/json']),
            Request::create('POST', "/admin/media/api/items/{$adminMediaId}/regenerate", headers: ['Accept' => 'application/json']),
            Request::create('POST', "/admin/media/api/items/{$adminMediaId}/replace", headers: ['Accept' => 'application/json'], files: ['file' => MediaFixtures::uploadedFile($seedJpg, 'nocsrf2.jpg', 'image/jpeg')]),
            Request::create('POST', "/admin/media/api/items/{$adminMediaId}/delete", headers: ['Accept' => 'application/json']),
            Request::create('POST', '/admin/media/api/bulk', body: ['action' => 'delete', 'ids' => [$adminMediaId]], headers: ['Accept' => 'application/json']),
            Request::create('POST', '/admin/media/api/folders', body: ['name' => 'NoCSRF'], headers: ['Accept' => 'application/json']),
            Request::create('POST', "/admin/media/api/folders/{$seedFolderId}", body: ['name' => 'NoCSRF'], headers: ['Accept' => 'application/json']),
            Request::create('POST', "/admin/media/api/folders/{$seedFolderId}/delete", headers: ['Accept' => 'application/json']),
        ];
        foreach ($mutatingRequests as $req) {
            $this->assertSame(419, $kernel->handle($req)->status(), 'Expected 419 without CSRF on ' . $req->path());
        }

        // 3. Subscriber (no media.view) -> 403 on HTML page AND all 12 JSON endpoints
        $this->loginAs($app, 5);
        $subCsrf = $this->csrfToken($app);
        $this->assertSame(403, $kernel->handle(Request::create('GET', '/admin/media'))->status());
        $this->assertSame(403, $kernel->handle(Request::create('GET', '/admin/media/api/items', headers: ['Accept' => 'application/json']))->status());
        $this->assertSame(403, $kernel->handle(Request::create('GET', "/admin/media/api/items/{$adminMediaId}", headers: ['Accept' => 'application/json']))->status());
        $this->assertSame(403, $kernel->handle(Request::create('GET', '/admin/media/api/folders', headers: ['Accept' => 'application/json']))->status());
        $this->assertSame(403, $kernel->handle(Request::create('POST', '/admin/media/api/upload', headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $subCsrf], files: ['file' => MediaFixtures::uploadedFile($seedJpg, 'sub.jpg', 'image/jpeg')]))->status());
        $this->assertSame(403, $kernel->handle(Request::create('POST', "/admin/media/api/items/{$adminMediaId}", body: ['title' => 'Sub'], headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $subCsrf]))->status());
        $this->assertSame(403, $kernel->handle(Request::create('POST', "/admin/media/api/items/{$adminMediaId}/regenerate", headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $subCsrf]))->status());
        $this->assertSame(403, $kernel->handle(Request::create('POST', "/admin/media/api/items/{$adminMediaId}/replace", headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $subCsrf], files: ['file' => MediaFixtures::uploadedFile($seedJpg, 'sub2.jpg', 'image/jpeg')]))->status());
        $this->assertSame(403, $kernel->handle(Request::create('POST', "/admin/media/api/items/{$adminMediaId}/delete", headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $subCsrf]))->status());
        $this->assertSame(403, $kernel->handle(Request::create('POST', '/admin/media/api/bulk', body: ['action' => 'move', 'ids' => [$adminMediaId], 'folder_id' => null], headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $subCsrf]))->status());
        $this->assertSame(403, $kernel->handle(Request::create('POST', '/admin/media/api/folders', body: ['name' => 'Sub'], headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $subCsrf]))->status());
        $this->assertSame(403, $kernel->handle(Request::create('POST', "/admin/media/api/folders/{$seedFolderId}", body: ['name' => 'Sub'], headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $subCsrf]))->status());
        $this->assertSame(403, $kernel->handle(Request::create('POST', "/admin/media/api/folders/{$seedFolderId}/delete", headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $subCsrf]))->status());

        // 4. Contributor (media.view ONLY — NO media.upload) -> 200 on 3 GET endpoints, 403 on ALL 9 POST endpoints
        $this->loginAs($app, 2);
        $contribCsrf = $this->csrfToken($app);
        $page = $kernel->handle(Request::create('GET', '/admin/media'));
        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('Médiathèque', $page->body());
        $this->assertStringNotContainsString('pc-media-upload-btn', $page->body());
        $this->assertSame(200, $kernel->handle(Request::create('GET', '/admin/media/api/items', headers: ['Accept' => 'application/json']))->status());
        $this->assertSame(200, $kernel->handle(Request::create('GET', "/admin/media/api/items/{$adminMediaId}", headers: ['Accept' => 'application/json']))->status());
        $this->assertSame(200, $kernel->handle(Request::create('GET', '/admin/media/api/folders', headers: ['Accept' => 'application/json']))->status());

        $this->assertSame(403, $kernel->handle(Request::create('POST', '/admin/media/api/upload', headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $contribCsrf], files: ['file' => MediaFixtures::uploadedFile($seedJpg, 'c.jpg', 'image/jpeg')]))->status());
        $this->assertSame(403, $kernel->handle(Request::create('POST', "/admin/media/api/items/{$adminMediaId}", body: ['title' => 'C'], headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $contribCsrf]))->status());
        $this->assertSame(403, $kernel->handle(Request::create('POST', "/admin/media/api/items/{$adminMediaId}/regenerate", headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $contribCsrf]))->status());
        $this->assertSame(403, $kernel->handle(Request::create('POST', "/admin/media/api/items/{$adminMediaId}/replace", headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $contribCsrf], files: ['file' => MediaFixtures::uploadedFile($seedJpg, 'c2.jpg', 'image/jpeg')]))->status());
        $this->assertSame(403, $kernel->handle(Request::create('POST', "/admin/media/api/items/{$adminMediaId}/delete", headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $contribCsrf]))->status());
        $this->assertSame(403, $kernel->handle(Request::create('POST', '/admin/media/api/bulk', body: ['action' => 'move', 'ids' => [$adminMediaId], 'folder_id' => null], headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $contribCsrf]))->status());
        $this->assertSame(403, $kernel->handle(Request::create('POST', '/admin/media/api/folders', body: ['name' => 'C'], headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $contribCsrf]))->status());
        $this->assertSame(403, $kernel->handle(Request::create('POST', "/admin/media/api/folders/{$seedFolderId}", body: ['name' => 'C'], headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $contribCsrf]))->status());
        $this->assertSame(403, $kernel->handle(Request::create('POST', "/admin/media/api/folders/{$seedFolderId}/delete", headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $contribCsrf]))->status());

        // 5. Author (media.view + media.upload, NO media.delete_any) -> full CRUD on OWN media, 403 on ALL foreign media mutations
        $this->loginAs($app, 4);
        $authorCsrf = $this->csrfToken($app);

        $authorJpg = MediaFixtures::createJpeg($this->tmpDir . '/tmp/author-own.jpg', 240, 180);
        $authorUpload = $kernel->handle(Request::create(
            'POST',
            '/admin/media/api/upload',
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $authorCsrf],
            files: ['file' => MediaFixtures::uploadedFile($authorJpg, 'author-own.jpg', 'image/jpeg')],
        ));
        $this->assertSame(201, $authorUpload->status());
        $authorMediaId = (int) json_decode($authorUpload->body(), true, 512, JSON_THROW_ON_ERROR)['item']['id'];

        // Foreign media mutations MUST all be 403 for Author
        $this->assertSame(403, $kernel->handle(Request::create('POST', "/admin/media/api/items/{$adminMediaId}", body: ['title' => 'Piraté'], headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $authorCsrf]))->status());
        $this->assertSame(403, $kernel->handle(Request::create('POST', "/admin/media/api/items/{$adminMediaId}/regenerate", headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $authorCsrf]))->status());
        $this->assertSame(403, $kernel->handle(Request::create('POST', "/admin/media/api/items/{$adminMediaId}/replace", headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $authorCsrf], files: ['file' => MediaFixtures::uploadedFile($authorJpg, 'hijack.jpg', 'image/jpeg')]))->status());
        $this->assertSame(403, $kernel->handle(Request::create('POST', "/admin/media/api/items/{$adminMediaId}/delete", headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $authorCsrf]))->status());
        $this->assertSame(403, $kernel->handle(Request::create('POST', '/admin/media/api/bulk', body: ['action' => 'move', 'ids' => [$adminMediaId], 'folder_id' => $seedFolderId], headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $authorCsrf]))->status());
        $this->assertSame(403, $kernel->handle(Request::create('POST', '/admin/media/api/bulk', body: ['action' => 'delete', 'ids' => [$authorMediaId, $adminMediaId]], headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $authorCsrf]))->status());

        // Own media mutations MUST succeed for Author
        $this->assertSame(200, $kernel->handle(Request::create('POST', "/admin/media/api/items/{$authorMediaId}", body: ['title' => 'Titre Auteur', 'alt' => 'Alt Auteur'], headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $authorCsrf]))->status());
        $this->assertSame(200, $kernel->handle(Request::create('POST', "/admin/media/api/items/{$authorMediaId}/regenerate", headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $authorCsrf]))->status());
        $authorReplaceJpg = MediaFixtures::createJpeg($this->tmpDir . '/tmp/author-replace.jpg', 260, 190);
        $this->assertSame(200, $kernel->handle(Request::create('POST', "/admin/media/api/items/{$authorMediaId}/replace", headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $authorCsrf], files: ['file' => MediaFixtures::uploadedFile($authorReplaceJpg, 'author-v2.jpg', 'image/jpeg')]))->status());
        $this->assertSame(200, $kernel->handle(Request::create('POST', '/admin/media/api/bulk', body: ['action' => 'move', 'ids' => [$authorMediaId], 'folder_id' => $seedFolderId], headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $authorCsrf]))->status());
        $this->assertSame(200, $kernel->handle(Request::create('POST', "/admin/media/api/items/{$authorMediaId}/delete", headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $authorCsrf]))->status());

        // 6. Editor (media.delete_any) CAN edit, regenerate, replace, and delete Admin's media
        $this->loginAs($app, 3);
        $editorCsrf = $this->csrfToken($app);
        $this->assertSame(200, $kernel->handle(Request::create('POST', "/admin/media/api/items/{$adminMediaId}", body: ['title' => 'Modifié par Éditeur'], headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $editorCsrf]))->status());
        $this->assertSame(200, $kernel->handle(Request::create('POST', "/admin/media/api/items/{$adminMediaId}/regenerate", headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $editorCsrf]))->status());
        $editorReplaceJpg = MediaFixtures::createJpeg($this->tmpDir . '/tmp/editor-replace.jpg', 320, 220);
        $this->assertSame(200, $kernel->handle(Request::create('POST', "/admin/media/api/items/{$adminMediaId}/replace", headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $editorCsrf], files: ['file' => MediaFixtures::uploadedFile($editorReplaceJpg, 'editor-v2.jpg', 'image/jpeg')]))->status());
        $this->assertSame(200, $kernel->handle(Request::create('POST', "/admin/media/api/items/{$adminMediaId}/delete", headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $editorCsrf]))->status());
    }

    public function testMaliciousUploadsAreAllRejectedWithZeroFilesWrittenToDisk(): void
    {
        $app = $this->createApp(authenticateAs: 'admin');
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        $csrf = $this->csrfToken($app);

        $polyglot = MediaFixtures::createPolyglotJpegWithPhp($this->tmpDir . '/tmp/polyglot.jpg');

        $phpAsJpg = $this->tmpDir . '/tmp/shell-as-jpg.jpg';
        file_put_contents($phpAsJpg, "<?php system(\$_GET['cmd']); ?>\n");

        $doublePhpJpg = MediaFixtures::createJpeg($this->tmpDir . '/tmp/real1.jpg', 120, 120);
        $doubleHtmlPng = MediaFixtures::createTransparentPng($this->tmpDir . '/tmp/real2.png', 120, 120);

        $fakePdf = $this->tmpDir . '/tmp/fake.pdf';
        file_put_contents($fakePdf, '<html><body><script>alert(1)</script></body></html>');

        $svgXss = $this->tmpDir . '/tmp/xss.svg';
        file_put_contents($svgXss, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $emptyFile = $this->tmpDir . '/tmp/empty.jpg';
        file_put_contents($emptyFile, '');

        $pixelBomb = MediaFixtures::createPixelBombPng($this->tmpDir . '/tmp/bomb.png', 10000, 10000);
        $traversalJpg = MediaFixtures::createJpeg($this->tmpDir . '/tmp/trav.jpg', 100, 100);

        $cases = [
            'polyglot JPEG+PHP' => [$polyglot, 'polyglot.jpg', 'image/jpeg'],
            '.php renamed to .jpg' => [$phpAsJpg, 'innocent.jpg', 'image/jpeg'],
            'double ext photo.php.jpg' => [$doublePhpJpg, 'photo.php.jpg', 'image/jpeg'],
            'double ext script.html.png' => [$doubleHtmlPng, 'script.html.png', 'image/png'],
            'fake PDF' => [$fakePdf, 'document.pdf', 'application/pdf'],
            'SVG with script' => [$svgXss, 'icon.svg', 'image/svg+xml'],
            'empty file (0 bytes)' => [$emptyFile, 'empty.jpg', 'image/jpeg'],
            '100 Mpx PNG pixel bomb' => [$pixelBomb, 'bomb.png', 'image/png'],
            'path traversal filename' => [$traversalJpg, '../../evil.jpg', 'image/jpeg'],
        ];

        /** @var MediaRepository $mediaRepo */
        $mediaRepo = $app->get(MediaRepository::class);

        foreach ($cases as $label => [$tmpPath, $clientName, $clientMime]) {
            $res = $kernel->handle(Request::create(
                'POST',
                '/admin/media/api/upload',
                headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
                files: ['file' => MediaFixtures::uploadedFile($tmpPath, $clientName, $clientMime)],
            ));

            $this->assertSame(422, $res->status(), "Malicious upload [{$label}] must be rejected with HTTP 422.");
            $this->assertSame([], $this->listUploadedFiles(), "Malicious upload [{$label}] must write ZERO files to content/uploads/.");
            $this->assertSame(0, $mediaRepo->countAll(), "Malicious upload [{$label}] must insert ZERO rows into pc_media.");
        }
    }

    public function testFoldersCreateRenameMoveCycleGuardAndDeleteReparenting(): void
    {
        $app = $this->createApp(authenticateAs: 'admin');
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        $csrf = $this->csrfToken($app);

        // Create root -> child -> grandchild
        $f1 = json_decode($kernel->handle(Request::create(
            'POST',
            '/admin/media/api/folders',
            body: ['name' => 'Campagne 2026'],
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
        ))->body(), true, 512, JSON_THROW_ON_ERROR)['folder'];
        $f1Id = (int) $f1['id'];

        $f2 = json_decode($kernel->handle(Request::create(
            'POST',
            '/admin/media/api/folders',
            body: ['name' => 'Bannières', 'parent_id' => $f1Id],
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
        ))->body(), true, 512, JSON_THROW_ON_ERROR)['folder'];
        $f2Id = (int) $f2['id'];

        $f3 = json_decode($kernel->handle(Request::create(
            'POST',
            '/admin/media/api/folders',
            body: ['name' => 'Formats sociaux', 'parent_id' => $f2Id],
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
        ))->body(), true, 512, JSON_THROW_ON_ERROR)['folder'];
        $f3Id = (int) $f3['id'];

        // Rename f2 cleanly
        $renameRes = $kernel->handle(Request::create(
            'POST',
            "/admin/media/api/folders/{$f2Id}",
            body: ['name' => 'Visuels Héro', 'parent_id' => $f1Id],
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
        ));
        $this->assertSame(200, $renameRes->status());
        $renamed = json_decode($renameRes->body(), true, 512, JSON_THROW_ON_ERROR)['folder'];
        $this->assertSame('Visuels Héro', $renamed['name']);

        // Circular parent move (f1 into its descendant f3) MUST fail with 422
        $cycleRes = $kernel->handle(Request::create(
            'POST',
            "/admin/media/api/folders/{$f1Id}",
            body: ['name' => 'Campagne 2026', 'parent_id' => $f3Id],
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
        ));
        $this->assertSame(422, $cycleRes->status());

        // Move f3 directly under root (null) and back under f2
        $moveRoot = $kernel->handle(Request::create(
            'POST',
            "/admin/media/api/folders/{$f3Id}",
            body: ['name' => 'Formats sociaux', 'parent_id' => null],
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
        ));
        $this->assertSame(200, $moveRoot->status());
        $this->assertNull(json_decode($moveRoot->body(), true, 512, JSON_THROW_ON_ERROR)['folder']['parent_id']);

        $moveBack = $kernel->handle(Request::create(
            'POST',
            "/admin/media/api/folders/{$f3Id}",
            body: ['name' => 'Formats sociaux', 'parent_id' => $f2Id],
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
        ));
        $this->assertSame(200, $moveBack->status());

        // Upload 1 image into f2 and 1 PDF into root
        $jpg = MediaFixtures::createJpeg($this->tmpDir . '/tmp/promo.jpg', 400, 300);
        $pdf = MediaFixtures::createPdf($this->tmpDir . '/tmp/guide.pdf');

        $m1 = json_decode($kernel->handle(Request::create(
            'POST',
            '/admin/media/api/upload',
            body: ['folder_id' => (string) $f2Id],
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
            files: ['file' => MediaFixtures::uploadedFile($jpg, 'promo-ete.jpg', 'image/jpeg')],
        ))->body(), true, 512, JSON_THROW_ON_ERROR)['item'];

        $m2 = json_decode($kernel->handle(Request::create(
            'POST',
            '/admin/media/api/upload',
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
            files: ['file' => MediaFixtures::uploadedFile($pdf, 'guide-officiel.pdf', 'application/pdf')],
        ))->body(), true, 512, JSON_THROW_ON_ERROR)['item'];

        // Filters: kind=document, folder=f2, unclassified=1
        $docList = json_decode($kernel->handle(Request::create(
            'GET',
            '/admin/media/api/items',
            query: ['kind' => 'document'],
            headers: ['Accept' => 'application/json'],
        ))->body(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $docList['total']);
        $this->assertSame($m2['id'], $docList['items'][0]['id']);

        $folderList = json_decode($kernel->handle(Request::create(
            'GET',
            '/admin/media/api/items',
            query: ['folder' => (string) $f2Id],
            headers: ['Accept' => 'application/json'],
        ))->body(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $folderList['total']);
        $this->assertSame($m1['id'], $folderList['items'][0]['id']);

        // Deleting f2 reparents BOTH its media m1 AND its child folder f3 up to f1!
        $delChild = $kernel->handle(Request::create(
            'POST',
            "/admin/media/api/folders/{$f2Id}/delete",
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
        ));
        $this->assertSame(200, $delChild->status());

        /** @var MediaRepository $mediaRepo */
        $mediaRepo = $app->get(MediaRepository::class);
        /** @var MediaFolderRepository $folderRepo */
        $folderRepo = $app->get(MediaFolderRepository::class);

        $reloadedM1 = $mediaRepo->find((int) $m1['id']);
        $reloadedF3 = $folderRepo->find($f3Id);
        $this->assertNotNull($reloadedM1);
        $this->assertNotNull($reloadedF3);
        $this->assertSame($f1Id, $reloadedM1->folderId);
        $this->assertSame($f1Id, $reloadedF3->parentId);

        // Deleting root f1 reparents m1 and f3 to root (null)
        $delRoot = $kernel->handle(Request::create(
            'POST',
            "/admin/media/api/folders/{$f1Id}/delete",
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
        ));
        $this->assertSame(200, $delRoot->status());
        $this->assertNull($mediaRepo->find((int) $m1['id'])?->folderId);
        $this->assertNull($folderRepo->find($f3Id)?->parentId);
    }

    public function testFocalPointChangeRegeneratesCroppedThumbnailAndReplacePreservesIdAndMetadata(): void
    {
        $app = $this->createApp(authenticateAs: 'admin');
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        $csrf = $this->csrfToken($app);
        /** @var MediaConfig $config */
        $config = $app->get(MediaConfig::class);
        /** @var MediaRepository $mediaRepo */
        $mediaRepo = $app->get(MediaRepository::class);

        // Create wide 800×400 asymmetric JPEG (left half RED, right half BLUE)
        $wideSrc = MediaFixtures::createJpeg($this->tmpDir . '/tmp/asymmetric.jpg', 800, 400);
        $uploadRes = $kernel->handle(Request::create(
            'POST',
            '/admin/media/api/upload',
            body: ['title' => 'Bannière bicolore', 'alt' => 'Rouge à gauche, bleu à droite'],
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
            files: ['file' => MediaFixtures::uploadedFile($wideSrc, 'bicolore.jpg', 'image/jpeg')],
        ));
        $this->assertSame(201, $uploadRes->status());
        $item = json_decode($uploadRes->body(), true, 512, JSON_THROW_ON_ERROR)['item'];
        $mediaId = (int) $item['id'];

        // Move focal point to LEFT (0.1, 0.5) -> thumbnail center pixel must be RED dominant
        $leftRes = $kernel->handle(Request::create(
            'POST',
            "/admin/media/api/items/{$mediaId}",
            body: ['focal_x' => 0.1, 'focal_y' => 0.5, 'title' => 'Bannière bicolore', 'alt' => 'Alt conservé'],
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
        ));
        $this->assertSame(200, $leftRes->status());
        $leftMedia = $mediaRepo->find($mediaId);
        $this->assertNotNull($leftMedia);
        $this->assertEqualsWithDelta(0.1, $leftMedia->focalX, 0.01);

        $thumbRel = $leftMedia->variants['thumbnail']['path'] ?? '';
        $this->assertNotSame('', $thumbRel);
        $thumbAbs = $config->uploadsAbsolutePath() . '/' . $thumbRel;
        $leftRgb = $this->sampleCenterRgb($thumbAbs);
        $this->assertGreaterThan($leftRgb['b'] + 50, $leftRgb['r'], 'Focal point at left (0.1) must crop red region.');
        $leftHash = md5_file($thumbAbs);

        // Move focal point to RIGHT (0.9, 0.5) -> regenerated thumbnail center pixel must become BLUE dominant
        $rightRes = $kernel->handle(Request::create(
            'POST',
            "/admin/media/api/items/{$mediaId}",
            body: ['focal_x' => 0.9, 'focal_y' => 0.5],
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
        ));
        $this->assertSame(200, $rightRes->status());
        $rightMedia = $mediaRepo->find($mediaId);
        $this->assertNotNull($rightMedia);
        $this->assertEqualsWithDelta(0.9, $rightMedia->focalX, 0.01);

        $rightThumbAbs = $config->uploadsAbsolutePath() . '/' . ($rightMedia->variants['thumbnail']['path'] ?? '');
        $rightRgb = $this->sampleCenterRgb($rightThumbAbs);
        $this->assertGreaterThan($rightRgb['r'] + 50, $rightRgb['b'], 'Focal point at right (0.9) must crop blue region.');
        $this->assertNotSame($leftHash, md5_file($rightThumbAbs));

        // Explicit /regenerate endpoint succeeds cleanly
        $regenRes = $kernel->handle(Request::create(
            'POST',
            "/admin/media/api/items/{$mediaId}/regenerate",
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
        ));
        $this->assertSame(200, $regenRes->status());

        // Replace file via POST /replace -> preserves ID, title, alt, updates dimensions & cleans old files
        $oldPaths = $rightMedia->allRelativePaths();
        $replacementSrc = MediaFixtures::createJpeg($this->tmpDir . '/tmp/replacement.jpg', 1000, 700);
        $replaceRes = $kernel->handle(Request::create(
            'POST',
            "/admin/media/api/items/{$mediaId}/replace",
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
            files: ['file' => MediaFixtures::uploadedFile($replacementSrc, 'bicolore-v2.jpg', 'image/jpeg')],
        ));
        $this->assertSame(200, $replaceRes->status());

        $replaced = $mediaRepo->find($mediaId);
        $this->assertNotNull($replaced);
        $this->assertSame($mediaId, $replaced->id);
        $this->assertSame('Bannière bicolore', $replaced->title);
        $this->assertSame('Alt conservé', $replaced->alt);
        $this->assertSame(1000, $replaced->width);
        $this->assertSame(700, $replaced->height);
        $this->assertNotSame($rightMedia->checksum, $replaced->checksum);

        $newPaths = $replaced->allRelativePaths();
        foreach ($newPaths as $rel) {
            $this->assertFileExists($config->uploadsAbsolutePath() . '/' . $rel);
        }
        foreach ($oldPaths as $oldRel) {
            if (!in_array($oldRel, $newPaths, true)) {
                $this->assertFileDoesNotExist($config->uploadsAbsolutePath() . '/' . $oldRel);
            }
        }
    }

    public function testDeleteMediaRemovesAllDiskFilesVariantsAndCleansUsages(): void
    {
        $app = $this->createApp(authenticateAs: 'admin');
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        $csrf = $this->csrfToken($app);
        /** @var MediaConfig $config */
        $config = $app->get(MediaConfig::class);
        /** @var MediaRepository $mediaRepo */
        $mediaRepo = $app->get(MediaRepository::class);
        /** @var MediaUsageRepository $usageRepo */
        $usageRepo = $app->get(MediaUsageRepository::class);
        /** @var UserRepository $userRepo */
        $userRepo = $app->get(UserRepository::class);

        $src = MediaFixtures::createJpeg($this->tmpDir . '/tmp/to-delete.jpg', 1200, 800);
        $uploaded = json_decode($kernel->handle(Request::create(
            'POST',
            '/admin/media/api/upload',
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
            files: ['file' => MediaFixtures::uploadedFile($src, 'to-delete.jpg', 'image/jpeg')],
        ))->body(), true, 512, JSON_THROW_ON_ERROR)['item'];
        $mediaId = (int) $uploaded['id'];

        $media = $mediaRepo->find($mediaId);
        $this->assertNotNull($media);
        $allPaths = $media->allRelativePaths();
        $this->assertGreaterThanOrEqual(4, count($allPaths), 'Original + thumbnail + medium + large (+ WebP) must exist.');
        foreach ($allPaths as $rel) {
            $this->assertFileExists($config->uploadsAbsolutePath() . '/' . $rel);
        }

        // Reference media in BOTH an entry (featured + block) AND user avatar
        $createPost = $kernel->handle(Request::create('POST', '/admin/content/post', body: [
            '_csrf_token' => $csrf,
            'title' => 'Article lié au média',
            'slug' => 'article-lie-au-media',
            'status' => 'published',
            'featured_media_id' => (string) $mediaId,
            'content_json' => json_encode([
                'version' => 1,
                'blocks' => [
                    ['id' => 'b1', 'type' => 'image', 'data' => ['mediaId' => $mediaId, 'alt' => 'Image']],
                ],
            ], JSON_THROW_ON_ERROR),
        ]));
        $this->assertSame(302, $createPost->status());

        $kernel->handle(Request::create('POST', '/admin/profile/info', body: [
            '_csrf_token' => $csrf,
            'display_name' => 'Camille Admin',
            'email' => 'admin@prime.test',
            'locale' => 'fr',
            'avatar_media_id' => (string) $mediaId,
        ]));

        $this->assertCount(3, $usageRepo->findByMedia($mediaId)); // thumbnail + block + avatar

        // Delete media -> ALL disk files + DB row + usages + avatar FK must be cleared!
        $delRes = $kernel->handle(Request::create(
            'POST',
            "/admin/media/api/items/{$mediaId}/delete",
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
        ));
        $this->assertSame(200, $delRes->status());
        $this->assertNull($mediaRepo->find($mediaId));
        $this->assertSame([], $usageRepo->findByMedia($mediaId));
        $this->assertNull($userRepo->find(1)?->avatarMediaId);

        foreach ($allPaths as $rel) {
            $this->assertFileDoesNotExist($config->uploadsAbsolutePath() . '/' . $rel);
        }
        $this->assertSame([], $this->listUploadedFiles());
    }

    public function testFallbackMediaServingStatusesHeadersRange304HeadAndSecurityTraversalRejection(): void
    {
        $app = $this->createApp(authenticateAs: 'admin');
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        $csrf = $this->csrfToken($app);

        $jpgSrc = MediaFixtures::createJpeg($this->tmpDir . '/tmp/serve-me.jpg', 600, 400);
        $pdfSrc = MediaFixtures::createPdf($this->tmpDir . '/tmp/manual.pdf');
        $zipSrc = MediaFixtures::createZip($this->tmpDir . '/tmp/archive.zip');

        $jpg = json_decode($kernel->handle(Request::create(
            'POST',
            '/admin/media/api/upload',
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
            files: ['file' => MediaFixtures::uploadedFile($jpgSrc, 'serve-me.jpg', 'image/jpeg')],
        ))->body(), true, 512, JSON_THROW_ON_ERROR)['item'];

        $pdf = json_decode($kernel->handle(Request::create(
            'POST',
            '/admin/media/api/upload',
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
            files: ['file' => MediaFixtures::uploadedFile($pdfSrc, 'manual.pdf', 'application/pdf')],
        ))->body(), true, 512, JSON_THROW_ON_ERROR)['item'];

        $zip = json_decode($kernel->handle(Request::create(
            'POST',
            '/admin/media/api/upload',
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
            files: ['file' => MediaFixtures::uploadedFile($zipSrc, 'archive.zip', 'application/zip')],
        ))->body(), true, 512, JSON_THROW_ON_ERROR)['item'];

        // 1. 200 OK with security & immutable cache headers
        $res200 = $kernel->handle(Request::create('GET', (string) $jpg['url']));
        $this->assertSame(200, $res200->status());
        $this->assertSame('image/jpeg', $res200->header('Content-Type'));
        $this->assertSame('nosniff', $res200->header('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $res200->header('Content-Security-Policy'));
        $this->assertStringContainsString('immutable', (string) $res200->header('Cache-Control'));
        $etag = (string) $res200->header('ETag');
        $this->assertNotSame('', $etag);

        // 2. 304 Not Modified on matching ETag
        $res304 = $kernel->handle(Request::create('GET', (string) $jpg['url'], headers: ['If-None-Match' => $etag]));
        $this->assertSame(304, $res304->status());
        $this->assertSame('', $res304->body());

        // 3. HEAD returns headers with empty body
        $resHead = $kernel->handle(Request::create('HEAD', (string) $jpg['url']));
        $this->assertSame(200, $resHead->status());
        $this->assertSame('', $resHead->body());
        $this->assertGreaterThan(0, (int) $resHead->header('Content-Length'));

        // 4. Byte-range 206 Partial Content & 416 Range Not Satisfiable
        $res206 = $kernel->handle(Request::create('GET', (string) $jpg['url'], headers: ['Range' => 'bytes=0-63']));
        $this->assertSame(206, $res206->status());
        $this->assertSame(64, strlen($res206->body()));
        $this->assertStringStartsWith('bytes 0-63/', (string) $res206->header('Content-Range'));

        $res416 = $kernel->handle(Request::create('GET', (string) $jpg['url'], headers: ['Range' => 'bytes=99999999-']));
        $this->assertSame(416, $res416->status());

        // 5. PDF served inline; ZIP served with Content-Disposition: attachment
        $resPdf = $kernel->handle(Request::create('GET', (string) $pdf['url']));
        $this->assertSame(200, $resPdf->status());
        $this->assertSame('application/pdf', $resPdf->header('Content-Type'));
        $this->assertNull($resPdf->header('Content-Disposition'));

        $resZip = $kernel->handle(Request::create('GET', (string) $zip['url']));
        $this->assertSame(200, $resZip->status());
        $this->assertStringStartsWith('attachment;', (string) $resZip->header('Content-Disposition'));

        // 6. Traversals, hidden dotfiles (.htaccess, .env), and scripts MUST be rejected (403/404)
        $blockedPaths = [
            '/uploads/.htaccess',
            '/uploads/2026/11/.env',
            '/uploads/..%2F..%2F.env',
            '/uploads/2026/11/shell.php',
            '/uploads/2026/11/does-not-exist.jpg',
        ];
        foreach ($blockedPaths as $badPath) {
            $res = $kernel->handle(Request::create('GET', $badPath));
            $this->assertContains($res->status(), [403, 404], "Blocked path [{$badPath}] must return 403 or 404.");
        }
    }

    public function testUserAvatarLifecycleProfileTopbarUsersListAndUsages(): void
    {
        $app = $this->createApp(authenticateAs: 'admin');
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        $csrf = $this->csrfToken($app);

        $avatarSrc = MediaFixtures::createTransparentPng($this->tmpDir . '/tmp/avatar.png', 300, 300);
        $avatar = json_decode($kernel->handle(Request::create(
            'POST',
            '/admin/media/api/upload',
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
            files: ['file' => MediaFixtures::uploadedFile($avatarSrc, 'camille-avatar.png', 'image/png')],
        ))->body(), true, 512, JSON_THROW_ON_ERROR)['item'];
        $avatarId = (int) $avatar['id'];

        $saveRes = $kernel->handle(Request::create('POST', '/admin/profile/info', body: [
            '_csrf_token' => $csrf,
            'display_name' => 'Camille Admin',
            'email' => 'admin@prime.test',
            'locale' => 'fr',
            'avatar_media_id' => (string) $avatarId,
        ]));
        $this->assertSame(302, $saveRes->status());

        // Visible in topbar (/admin), profile page (/admin/profile), users list (/admin/users), and media detail usages
        $this->assertStringContainsString((string) $avatar['thumbnail_url'], $kernel->handle(Request::create('GET', '/admin'))->body());
        $this->assertStringContainsString((string) $avatar['thumbnail_url'], $kernel->handle(Request::create('GET', '/admin/profile'))->body());
        $this->assertStringContainsString((string) $avatar['thumbnail_url'], $kernel->handle(Request::create('GET', '/admin/users'))->body());

        $detail = json_decode($kernel->handle(Request::create(
            'GET',
            "/admin/media/api/items/{$avatarId}",
            headers: ['Accept' => 'application/json'],
        ))->body(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertContains('avatar', array_column($detail['usages'], 'context'));

        // Clearing avatar removes usage cleanly
        $clearRes = $kernel->handle(Request::create('POST', '/admin/profile/info', body: [
            '_csrf_token' => $csrf,
            'display_name' => 'Camille Admin',
            'email' => 'admin@prime.test',
            'locale' => 'fr',
            'avatar_media_id' => '',
        ]));
        $this->assertSame(302, $clearRes->status());

        $detailAfter = json_decode($kernel->handle(Request::create(
            'GET',
            "/admin/media/api/items/{$avatarId}",
            headers: ['Accept' => 'application/json'],
        ))->body(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], $detailAfter['usages']);
    }

    public function testCustomMediaFieldSingleAndMultiplePersistenceUsageTrackingAndEditView(): void
    {
        $app = $this->createApp(authenticateAs: 'admin');
        /** @var FieldRegistry $fieldRegistry */
        $fieldRegistry = $app->get(FieldRegistry::class);
        $fieldRegistry->registerGroup(new FieldGroup(
            id: 'test.media_fields',
            title: 'Médias associés',
            contentTypes: ['post'],
            fields: [
                new Field(
                    name: 'client_photo',
                    type: 'media',
                    label: 'Photo du client',
                    mediaKinds: ['image'],
                ),
                new Field(
                    name: 'attachments',
                    type: 'media',
                    label: 'Pièces jointes',
                    multiple: true,
                ),
            ],
        ));

        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        $csrf = $this->csrfToken($app);

        $photoSrc = MediaFixtures::createJpeg($this->tmpDir . '/tmp/client.jpg', 400, 400);
        $pdfSrc = MediaFixtures::createPdf($this->tmpDir . '/tmp/spec.pdf');
        $zipSrc = MediaFixtures::createZip($this->tmpDir . '/tmp/kit.zip');

        $photoId = (int) json_decode($kernel->handle(Request::create(
            'POST',
            '/admin/media/api/upload',
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
            files: ['file' => MediaFixtures::uploadedFile($photoSrc, 'client.jpg', 'image/jpeg')],
        ))->body(), true, 512, JSON_THROW_ON_ERROR)['item']['id'];

        $pdfId = (int) json_decode($kernel->handle(Request::create(
            'POST',
            '/admin/media/api/upload',
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
            files: ['file' => MediaFixtures::uploadedFile($pdfSrc, 'spec.pdf', 'application/pdf')],
        ))->body(), true, 512, JSON_THROW_ON_ERROR)['item']['id'];

        $zipId = (int) json_decode($kernel->handle(Request::create(
            'POST',
            '/admin/media/api/upload',
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
            files: ['file' => MediaFixtures::uploadedFile($zipSrc, 'kit.zip', 'application/zip')],
        ))->body(), true, 512, JSON_THROW_ON_ERROR)['item']['id'];

        // Create post with BOTH single & multiple media custom fields
        $createRes = $kernel->handle(Request::create('POST', '/admin/content/post', body: [
            '_csrf_token' => $csrf,
            'title' => 'Étude de cas média',
            'slug' => 'etude-de-cas-media',
            'status' => 'published',
            'content_json' => '{"version":1,"blocks":[{"id":"p1","type":"paragraph","data":{"text":"Contenu"}}]}',
            'meta' => [
                'client_photo' => (string) $photoId,
                'attachments' => json_encode([$pdfId, $zipId], JSON_THROW_ON_ERROR),
            ],
        ]));
        $this->assertSame(302, $createRes->status());
        preg_match('#/(\d+)/edit$#', (string) $createRes->header('Location'), $m);
        $entryId = (int) $m[1];

        // Verify pc_media_usage tracks field:client_photo and field:attachments
        /** @var MediaUsageRepository $usageRepo */
        $usageRepo = $app->get(MediaUsageRepository::class);
        $this->assertContains('field:client_photo', array_column($usageRepo->findByMedia($photoId), 'context'));
        $this->assertContains('field:attachments', array_column($usageRepo->findByMedia($pdfId), 'context'));
        $this->assertContains('field:attachments', array_column($usageRepo->findByMedia($zipId), 'context'));

        // Edit page renders declarative [data-prime-media-field] widgets with JSON initial payloads
        $editHtml = $kernel->handle(Request::create('GET', "/admin/content/post/{$entryId}/edit"))->body();
        $this->assertStringContainsString('data-prime-media-field', $editHtml);
        $this->assertStringContainsString('data-prime-media-initial', $editHtml);
        $this->assertStringContainsString('client.jpg', $editHtml);
        $this->assertStringContainsString('spec.pdf', $editHtml);

        // Updating entry to clear custom media fields cleans pc_media_usage
        $clearRes = $kernel->handle(Request::create('POST', "/admin/content/post/{$entryId}", body: [
            '_csrf_token' => $csrf,
            'title' => 'Étude de cas média',
            'slug' => 'etude-de-cas-media',
            'status' => 'published',
            'content_json' => '{"version":1,"blocks":[{"id":"p1","type":"paragraph","data":{"text":"Contenu"}}]}',
            'meta' => [
                'client_photo' => '',
                'attachments' => '',
            ],
        ]));
        $this->assertSame(302, $clearRes->status());
        $this->assertSame([], $usageRepo->findByMedia($photoId));
        $this->assertSame([], $usageRepo->findByMedia($pdfId));
        $this->assertSame([], $usageRepo->findByMedia($zipId));
    }

    public function testContentBlocksRenderingFeaturedThumbnailAndDashboardKpi(): void
    {
        $app = $this->createApp(authenticateAs: 'admin');
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        $csrf = $this->csrfToken($app);

        $heroSrc = MediaFixtures::createJpeg($this->tmpDir . '/tmp/hero.jpg', 1200, 800);
        $pdfSrc = MediaFixtures::createPdf($this->tmpDir . '/tmp/brochure.pdf');

        $hero = json_decode($kernel->handle(Request::create(
            'POST',
            '/admin/media/api/upload',
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
            files: ['file' => MediaFixtures::uploadedFile($heroSrc, 'hero-post.jpg', 'image/jpeg')],
        ))->body(), true, 512, JSON_THROW_ON_ERROR)['item'];
        $heroId = (int) $hero['id'];

        $pdf = json_decode($kernel->handle(Request::create(
            'POST',
            '/admin/media/api/upload',
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
            files: ['file' => MediaFixtures::uploadedFile($pdfSrc, 'brochure.pdf', 'application/pdf')],
        ))->body(), true, 512, JSON_THROW_ON_ERROR)['item'];
        $pdfId = (int) $pdf['id'];

        // Admin HTML layout exposes `<meta name="csrf-token">` for browser JS fetch/XHR uploads
        $mediaPageHtml = $kernel->handle(Request::create('GET', '/admin/media'))->body();
        $this->assertStringContainsString('<meta name="csrf-token" content="', $mediaPageHtml);

        // Create post with featured image + image, gallery & file blocks
        $blocksJson = json_encode([
            'version' => 1,
            'blocks' => [
                ['id' => 'img1', 'type' => 'image', 'data' => ['mediaId' => $heroId, 'url' => $hero['url'], 'alt' => 'Couverture', 'alignment' => 'wide']],
                ['id' => 'gal1', 'type' => 'gallery', 'data' => ['columns' => 2, 'items' => [['mediaId' => $heroId, 'url' => $hero['url'], 'alt' => 'G1', 'caption' => '']]]],
                ['id' => 'fil1', 'type' => 'file', 'data' => ['mediaId' => $pdfId, 'url' => $pdf['url'], 'label' => 'Télécharger la brochure', 'filename' => 'brochure.pdf', 'extension' => 'pdf', 'sizeBytes' => $pdf['size_bytes']]],
            ],
        ], JSON_THROW_ON_ERROR);

        $createPost = $kernel->handle(Request::create('POST', '/admin/content/post', body: [
            '_csrf_token' => $csrf,
            'title' => 'Article complet avec blocs médias',
            'slug' => 'article-complet-blocs-medias',
            'status' => 'published',
            'featured_media_id' => (string) $heroId,
            'content_json' => $blocksJson,
        ]));
        $this->assertSame(302, $createPost->status());
        preg_match('#/(\d+)/edit$#', (string) $createPost->header('Location'), $m);
        $entryId = (int) $m[1];

        // Listing renders 40×40 thumbnail; preview renders <picture>, srcset, gallery, and download card
        $postIndex = $kernel->handle(Request::create('GET', '/admin/content/post'));
        $this->assertSame(200, $postIndex->status());
        $this->assertStringContainsString('pc-content-thumb', $postIndex->body());

        $previewHtml = $kernel->handle(Request::create('GET', "/admin/content/post/{$entryId}/preview"))->body();
        $this->assertStringContainsString('<picture>', $previewHtml);
        $this->assertStringContainsString('srcset=', $previewHtml);
        $this->assertStringContainsString('pc-block--gallery', $previewHtml);
        $this->assertStringContainsString('pc-block--file', $previewHtml);
        $this->assertStringContainsString('Télécharger la brochure', $previewHtml);

        // Usages reported for both hero (thumbnail + block) and PDF (block)
        $detailHero = json_decode($kernel->handle(Request::create('GET', "/admin/media/api/items/{$heroId}", headers: ['Accept' => 'application/json']))->body(), true, 512, JSON_THROW_ON_ERROR);
        $heroContexts = array_column($detailHero['usages'], 'context');
        $this->assertContains('thumbnail', $heroContexts);
        $this->assertContains('block', $heroContexts);

        $detailPdf = json_decode($kernel->handle(Request::create('GET', "/admin/media/api/items/{$pdfId}", headers: ['Accept' => 'application/json']))->body(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertContains('block', array_column($detailPdf['usages'], 'context'));

        // Live Dashboard KPI shows Phase 7 stats
        $dashboard = $kernel->handle(Request::create('GET', '/admin'));
        $this->assertSame(200, $dashboard->status());
        $this->assertStringContainsString('Phase 7', $dashboard->body());

        // Trash & permanently delete post -> usages cleaned
        $kernel->handle(Request::create('POST', "/admin/content/post/{$entryId}/trash", body: ['_csrf_token' => $csrf]));
        $kernel->handle(Request::create('POST', "/admin/content/post/{$entryId}/delete", body: ['_csrf_token' => $csrf]));
        /** @var MediaUsageRepository $usageRepo */
        $usageRepo = $app->get(MediaUsageRepository::class);
        $this->assertSame([], $usageRepo->findByMedia($heroId));
        $this->assertSame([], $usageRepo->findByMedia($pdfId));
    }

    public function testCliCommandsMediaRegenerateLinkAndCleanup(): void
    {
        $app = $this->createApp(authenticateAs: 'admin');
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        $csrf = $this->csrfToken($app);

        $heroSrc = MediaFixtures::createJpeg($this->tmpDir . '/tmp/cli-hero.jpg', 1000, 700);
        $hero = json_decode($kernel->handle(Request::create(
            'POST',
            '/admin/media/api/upload',
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
            files: ['file' => MediaFixtures::uploadedFile($heroSrc, 'cli-hero.jpg', 'image/jpeg')],
        ))->body(), true, 512, JSON_THROW_ON_ERROR)['item'];
        $heroId = (int) $hero['id'];

        $console = new ConsoleApplication($app);

        // 1. media:regenerate (filtered by --id & --variant & --force, plus global)
        $outRegenSingle = new ConsoleOutput(buffered: true);
        $this->assertSame(0, $console->run(['bin/prime', 'media:regenerate', "--id={$heroId}", '--variant=thumbnail', '--force'], $outRegenSingle));
        $this->assertStringContainsString('variantes régénérées', $outRegenSingle->stdout());

        $outRegenAll = new ConsoleOutput(buffered: true);
        $this->assertSame(0, $console->run(['bin/prime', 'media:regenerate'], $outRegenAll));
        $this->assertStringContainsString('1/1', $outRegenAll->stdout());

        // 2. media:link creates symlink + hardened .htaccess blocking hidden files, and is idempotent
        $outLink1 = new ConsoleOutput(buffered: true);
        $this->assertSame(0, $console->run(['bin/prime', 'media:link'], $outLink1));
        $this->assertTrue(is_link($this->tmpDir . '/public/uploads'));
        $htaccess = (string) file_get_contents($this->tmpDir . '/content/uploads/.htaccess');
        $this->assertStringContainsString('FilesMatch "^\\."', $htaccess);
        $this->assertStringContainsString('php_flag engine off', $htaccess);

        $outLink2 = new ConsoleOutput(buffered: true);
        $this->assertSame(0, $console->run(['bin/prime', 'media:link'], $outLink2));
        $this->assertStringContainsString('déjà en place', $outLink2->stdout());

        // 3. media:cleanup --dry-run reports orphans without deleting, then media:cleanup removes them
        $orphanAbs = $this->tmpDir . '/content/uploads/2026/11/orphan-junk.txt';
        file_put_contents($orphanAbs, 'orphan payload');
        $this->assertFileExists($orphanAbs);

        $outDry = new ConsoleOutput(buffered: true);
        $this->assertSame(0, $console->run(['bin/prime', 'media:cleanup', '--dry-run'], $outDry));
        $this->assertStringContainsString('orphan-junk.txt', $outDry->stdout());
        $this->assertFileExists($orphanAbs);

        $outClean = new ConsoleOutput(buffered: true);
        $this->assertSame(0, $console->run(['bin/prime', 'media:cleanup'], $outClean));
        $this->assertFileDoesNotExist($orphanAbs);
        $this->assertFileExists($this->tmpDir . '/content/uploads/.htaccess');
        $this->assertNotEmpty($this->listUploadedFiles(), 'Legitimate media files must never be deleted by media:cleanup.');
    }

    /**
     * List all non-`.htaccess` files under isolated `content/uploads/`.
     *
     * @return list<string>
     */
    private function listUploadedFiles(): array
    {
        $root = $this->tmpDir . '/content/uploads';
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        /** @var SplFileInfo $file */
        foreach ($it as $file) {
            if ($file->isFile() && $file->getFilename() !== '.htaccess') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Sample the RGB color at the center pixel of a JPEG image.
     *
     * @return array{r: int, g: int, b: int}
     */
    private function sampleCenterRgb(string $jpegPath): array
    {
        $im = imagecreatefromjpeg($jpegPath);
        $this->assertNotSame(false, $im, 'Failed to read JPEG thumbnail: ' . $jpegPath);
        $cx = (int) floor(imagesx($im) / 2);
        $cy = (int) floor(imagesy($im) / 2);
        $rgb = imagecolorat($im, $cx, $cy);
        imagedestroy($im);
        $this->assertNotSame(false, $rgb);

        return [
            'r' => ($rgb >> 16) & 0xFF,
            'g' => ($rgb >> 8) & 0xFF,
            'b' => $rgb & 0xFF,
        ];
    }

    private function createApp(?string $authenticateAs = 'admin'): Application
    {
        $this->tmpDir = sys_get_temp_dir() . '/prime_media_http_' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir . '/tmp', 0755, true);
        mkdir($this->tmpDir . '/public', 0755, true);

        $app = new Application(dirname(__DIR__, 2), loadEnv: false);
        $app->config()->set('app.env', 'testing');
        $app->config()->set('app.debug', true);
        $app->config()->set('app.key', 'base64:' . base64_encode(str_repeat('m', 32)));
        $app->boot();

        $app->container()->instance(InstallCheckerInterface::class, new class () implements InstallCheckerInterface {
            public function isInstalled(): bool
            {
                return true;
            }
        });

        /** @var array<string, mixed> $mediaRaw */
        $mediaRaw = $app->config()->get('media', []);
        $isolatedConfig = new MediaConfig($this->tmpDir, is_array($mediaRaw) ? $mediaRaw : []);
        $app->container()->instance(MediaConfig::class, $isolatedConfig);
        $app->container()->instance(UploadsGuard::class, new UploadsGuard($isolatedConfig));
        $app->container()->instance(ImageProcessor::class, new ImageProcessor($isolatedConfig));
        $app->container()->instance(MediaServingController::class, new MediaServingController($isolatedConfig));

        $pdo = new MysqlEmulatingPdo('name');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE pc_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(191) UNIQUE, value LONGTEXT, autoload INTEGER DEFAULT 1, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec("CREATE TABLE pc_migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration VARCHAR(191) UNIQUE, source VARCHAR(64) DEFAULT 'core', batch INTEGER, ran_at DATETIME)");
        $pdo->exec('CREATE TABLE pc_plugins (slug VARCHAR(64) PRIMARY KEY, version VARCHAR(64), status VARCHAR(20), manifest_hash VARCHAR(64), last_error TEXT, errored_at DATETIME, installed_at DATETIME, activated_at DATETIME, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_media_folders (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER NULL REFERENCES pc_media_folders(id) ON DELETE SET NULL, name VARCHAR(120), slug VARCHAR(140), created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_media (id INTEGER PRIMARY KEY AUTOINCREMENT, folder_id INTEGER NULL REFERENCES pc_media_folders(id) ON DELETE SET NULL, author_id INTEGER NULL, disk_path VARCHAR(255) UNIQUE, filename VARCHAR(255), original_name VARCHAR(255), extension VARCHAR(16), mime_type VARCHAR(120), kind VARCHAR(24), size_bytes INTEGER DEFAULT 0, width INTEGER NULL, height INTEGER NULL, checksum CHAR(64), alt VARCHAR(255), title VARCHAR(255), caption TEXT NULL, description TEXT NULL, focal_x REAL DEFAULT 0.5, focal_y REAL DEFAULT 0.5, variants LONGTEXT NULL, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(191) UNIQUE, password_hash VARCHAR(255), display_name VARCHAR(191), status VARCHAR(20), locale VARCHAR(10), avatar_media_id INTEGER NULL REFERENCES pc_media(id) ON DELETE SET NULL, last_login_at DATETIME NULL, session_version INTEGER DEFAULT 1, two_factor_secret TEXT NULL, two_factor_recovery_codes TEXT NULL, two_factor_confirmed_at DATETIME NULL, two_factor_last_step INTEGER NULL, password_changed_at DATETIME NULL, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, slug VARCHAR(191) UNIQUE, name VARCHAR(191), description TEXT NULL, is_system INTEGER DEFAULT 0, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_role_capabilities (role_id INTEGER, capability VARCHAR(100), PRIMARY KEY (role_id, capability))');
        $pdo->exec('CREATE TABLE pc_user_roles (user_id INTEGER, role_id INTEGER, PRIMARY KEY (user_id, role_id))');
        $pdo->exec('CREATE TABLE pc_remember_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, selector CHAR(24) UNIQUE, validator_hash CHAR(64), expires_at DATETIME, last_used_at DATETIME NULL, user_agent VARCHAR(255) NULL, created_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_password_resets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, token_hash CHAR(64) UNIQUE, expires_at DATETIME, used_at DATETIME NULL, created_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_rate_limits (key CHAR(64) PRIMARY KEY, hits INTEGER DEFAULT 0, reset_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_entries (id INTEGER PRIMARY KEY AUTOINCREMENT, type VARCHAR(40), status VARCHAR(20), title VARCHAR(255), slug VARCHAR(191), excerpt TEXT NULL, content LONGTEXT, content_html LONGTEXT, content_text LONGTEXT, author_id INTEGER NULL, parent_id INTEGER DEFAULT 0, menu_order INTEGER DEFAULT 0, template VARCHAR(100) NULL, published_at DATETIME NULL, previous_status VARCHAR(20) NULL, trashed_at DATETIME NULL, created_at DATETIME, updated_at DATETIME, UNIQUE (type, parent_id, slug))');
        $pdo->exec('CREATE TABLE pc_entry_meta (id INTEGER PRIMARY KEY AUTOINCREMENT, entry_id INTEGER, meta_key VARCHAR(191), meta_value LONGTEXT, UNIQUE (entry_id, meta_key))');
        $pdo->exec('CREATE TABLE pc_terms (id INTEGER PRIMARY KEY AUTOINCREMENT, taxonomy VARCHAR(40), name VARCHAR(191), slug VARCHAR(191), description TEXT NULL, parent_id INTEGER DEFAULT 0, menu_order INTEGER DEFAULT 0, count INTEGER DEFAULT 0, created_at DATETIME, updated_at DATETIME, UNIQUE (taxonomy, slug))');
        $pdo->exec('CREATE TABLE pc_entry_terms (entry_id INTEGER, term_id INTEGER, position INTEGER DEFAULT 0, PRIMARY KEY (entry_id, term_id))');
        $pdo->exec('CREATE TABLE pc_revisions (id INTEGER PRIMARY KEY AUTOINCREMENT, entry_id INTEGER, author_id INTEGER NULL, is_autosave INTEGER DEFAULT 0, title VARCHAR(255), excerpt TEXT NULL, content LONGTEXT, meta LONGTEXT NULL, created_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_entry_locks (entry_id INTEGER PRIMARY KEY, user_id INTEGER, acquired_at DATETIME, heartbeat_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_media_usage (media_id INTEGER REFERENCES pc_media(id) ON DELETE CASCADE, entry_id INTEGER REFERENCES pc_entries(id) ON DELETE CASCADE, context VARCHAR(100), PRIMARY KEY (media_id, entry_id, context))');

        $connection = new Connection(['prefix' => 'pc_'], pdo: $pdo);
        $app->container()->instance(Connection::class, $connection);

        $app->container()->singleton(UploadService::class, static function () use ($app, $isolatedConfig): UploadService {
            return new UploadService(
                $isolatedConfig,
                $app->get(MediaRepository::class),
                $app->get(MediaFolderRepository::class),
                $app->get(ImageProcessor::class),
                $app->get(UploadsGuard::class),
            );
        });

        $now = '2026-11-15 12:00:00';
        $hash = password_hash('Prime!Secret#2026', PASSWORD_BCRYPT);
        $pdo->exec("INSERT INTO pc_roles (id, slug, name, description, is_system, created_at, updated_at) VALUES (1, 'administrator', 'Administrateur', 'Tout', 1, '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO pc_roles (id, slug, name, description, is_system, created_at, updated_at) VALUES (2, 'contributor', 'Contributeur', 'Lecture médias', 1, '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO pc_roles (id, slug, name, description, is_system, created_at, updated_at) VALUES (3, 'editor', 'Éditeur', 'Gestion complète médias', 1, '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO pc_roles (id, slug, name, description, is_system, created_at, updated_at) VALUES (4, 'author', 'Auteur', 'Propres médias', 1, '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO pc_roles (id, slug, name, description, is_system, created_at, updated_at) VALUES (5, 'subscriber', 'Abonné', 'Aucun accès média', 0, '{$now}', '{$now}')");

        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (1, '*')");
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (2, 'dashboard.view'), (2, 'media.view')");
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (3, 'dashboard.view'), (3, 'media.view'), (3, 'media.upload'), (3, 'media.delete_any'), (3, 'content.view'), (3, 'content.create'), (3, 'content.edit_any'), (3, 'content.publish')");
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (4, 'dashboard.view'), (4, 'media.view'), (4, 'media.upload')");
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (5, 'dashboard.view')");

        $stmt = $pdo->prepare('INSERT INTO pc_users (id, email, password_hash, display_name, status, locale, session_version, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)');
        $stmt->execute([1, 'admin@prime.test', $hash, 'Camille Admin', 'active', 'fr', $now, $now]);
        $stmt->execute([2, 'contrib@prime.test', $hash, 'Lucas Contrib', 'active', 'fr', $now, $now]);
        $stmt->execute([3, 'editor@prime.test', $hash, 'Clara Editor', 'active', 'fr', $now, $now]);
        $stmt->execute([4, 'author@prime.test', $hash, 'Hugo Auteur', 'active', 'fr', $now, $now]);
        $stmt->execute([5, 'sub@prime.test', $hash, 'Nadia Abonnée', 'active', 'fr', $now, $now]);
        $pdo->exec('INSERT INTO pc_user_roles (user_id, role_id) VALUES (1, 1), (2, 2), (3, 3), (4, 4), (5, 5)');

        if ($authenticateAs !== null) {
            $userId = match ($authenticateAs) {
                'contributor' => 2,
                'editor' => 3,
                'author' => 4,
                'subscriber' => 5,
                default => 1,
            };
            $this->loginAs($app, $userId);
        }

        return $app;
    }

    private function loginAs(Application $app, int $userId): void
    {
        /** @var UserRepository $userRepo */
        $userRepo = $app->get(UserRepository::class);
        /** @var Auth $auth */
        $auth = $app->get(Auth::class);
        $auth->resetCache();
        $user = $userRepo->find($userId);
        if ($user !== null) {
            $auth->login($user);
        }
    }

    private function csrfToken(Application $app): string
    {
        /** @var Session $session */
        $session = $app->get(Session::class);

        return (new Csrf($session))->token();
    }
}
