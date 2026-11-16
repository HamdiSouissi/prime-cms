<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Prime\Http\Exception\ForbiddenException;
use Prime\Http\Exception\NotFoundException;
use Prime\Http\Request;
use Prime\Media\MediaConfig;
use Prime\Media\MediaServingController;
use Prime\Media\UploadsGuard;
use Prime\Plugins\AssetPublisher;
use Prime\Tests\Support\MediaFixtures;

/**
 * Tests hardened `.htaccess` generation, symlink management (`UploadsGuard`),
 * and the secure PHP fallback media serving controller (`GET|HEAD /uploads/{path}`).
 */
final class MediaServingAndSecurityTest extends TestCase
{
    private string $tmpDir = '';
    private MediaConfig $config;
    private UploadsGuard $guard;
    private MediaServingController $controller;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/prime_media_serve_' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir . '/public', 0755, true);
        mkdir($this->tmpDir . '/content/uploads/2026/11', 0755, true);

        $this->config = new MediaConfig($this->tmpDir, []);
        $this->guard = new UploadsGuard($this->config);
        $this->controller = new MediaServingController($this->config);
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            AssetPublisher::deleteDirectory($this->tmpDir);
        }
    }

    public function testUploadsGuardCreatesHardenedHtaccessAndPublicSymlink(): void
    {
        $uploadsDir = $this->guard->ensureProtectedUploadsDirectory();
        $htaccess = $uploadsDir . '/.htaccess';

        $this->assertFileExists($htaccess);
        $rules = (string) file_get_contents($htaccess);
        $this->assertStringContainsString('Options -Indexes', $rules);
        $this->assertStringContainsString('php_flag engine off', $rules);
        $this->assertStringContainsString('Require all denied', $rules);
        $this->assertStringContainsString('X-Content-Type-Options "nosniff"', $rules);
        $this->assertStringContainsString('Content-Security-Policy', $rules);
        $this->assertStringContainsString('Content-Disposition "attachment"', $rules);

        $link1 = $this->guard->createPublicSymlink();
        $this->assertSame('created', $link1['status']);
        $this->assertTrue(is_link($this->tmpDir . '/public/uploads'));

        // Idempotent second call
        $link2 = $this->guard->createPublicSymlink();
        $this->assertSame('already_linked', $link2['status']);
    }

    public function testServesImageWithSecurityHeadersEtag304HeadAndByteRanges(): void
    {
        $jpgPath = MediaFixtures::createJpeg($this->tmpDir . '/content/uploads/2026/11/hero-1234.jpg', 200, 120);
        $rawBytes = (string) file_get_contents($jpgPath);
        $totalLen = strlen($rawBytes);

        // 1. Standard GET 200
        $res = $this->controller->serve(Request::create('GET', '/uploads/2026/11/hero-1234.jpg'), '2026/11/hero-1234.jpg');
        $this->assertSame(200, $res->status());
        $this->assertSame('image/jpeg', $res->header('Content-Type'));
        $this->assertSame('nosniff', $res->header('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $res->header('Content-Security-Policy'));
        $this->assertStringContainsString('immutable', (string) $res->header('Cache-Control'));
        $this->assertSame('bytes', $res->header('Accept-Ranges'));
        $this->assertSame($rawBytes, $res->body());
        $etag = (string) $res->header('ETag');
        $this->assertNotSame('', $etag);

        // 2. Conditional GET If-None-Match -> 304 Not Modified with empty body
        $res304 = $this->controller->serve(
            Request::create('GET', '/uploads/2026/11/hero-1234.jpg', headers: ['If-None-Match' => $etag]),
            '2026/11/hero-1234.jpg',
        );
        $this->assertSame(304, $res304->status());
        $this->assertSame('', $res304->body());

        // 3. HEAD request -> 200 with Content-Length and empty body
        $resHead = $this->controller->serve(Request::create('HEAD', '/uploads/2026/11/hero-1234.jpg'), '2026/11/hero-1234.jpg');
        $this->assertSame(200, $resHead->status());
        $this->assertSame((string) $totalLen, $resHead->header('Content-Length'));
        $this->assertSame('', $resHead->body());

        // 4. Range request -> 206 Partial Content
        $resRange = $this->controller->serve(
            Request::create('GET', '/uploads/2026/11/hero-1234.jpg', headers: ['Range' => 'bytes=0-15']),
            '2026/11/hero-1234.jpg',
        );
        $this->assertSame(206, $resRange->status());
        $this->assertSame("bytes 0-15/{$totalLen}", $resRange->header('Content-Range'));
        $this->assertSame(substr($rawBytes, 0, 16), $resRange->body());

        // 5. Out-of-bounds Range -> 416
        $res416 = $this->controller->serve(
            Request::create('GET', '/uploads/2026/11/hero-1234.jpg', headers: ['Range' => 'bytes=9999999-']),
            '2026/11/hero-1234.jpg',
        );
        $this->assertSame(416, $res416->status());
    }

    public function testContentDispositionInlineForPdfAndAttachmentForZip(): void
    {
        MediaFixtures::createPdf($this->tmpDir . '/content/uploads/2026/11/doc.pdf');
        MediaFixtures::createZip($this->tmpDir . '/content/uploads/2026/11/pack.zip');

        $pdfRes = $this->controller->serve(Request::create('GET', '/uploads/2026/11/doc.pdf'), '2026/11/doc.pdf');
        $this->assertSame(200, $pdfRes->status());
        // PDF is safe for inline preview (no attachment disposition forced)
        $this->assertNull($pdfRes->header('Content-Disposition'));

        $zipRes = $this->controller->serve(Request::create('GET', '/uploads/2026/11/pack.zip'), '2026/11/pack.zip');
        $this->assertSame(200, $zipRes->status());
        $this->assertStringStartsWith('attachment;', (string) $zipRes->header('Content-Disposition'));
    }

    public function testBlocksPathTraversalHiddenFilesAndExecutableScripts(): void
    {
        file_put_contents($this->tmpDir . '/.env', 'APP_KEY=secret');
        file_put_contents($this->tmpDir . '/content/uploads/.htaccess', 'deny');
        file_put_contents($this->tmpDir . '/content/uploads/2026/11/shell.php', '<?php echo 1;');
        file_put_contents($this->tmpDir . '/content/uploads/2026/11/xss.svg', '<svg/>');

        foreach (['../../.env', '..%2F..%2F.env', '.htaccess', '2026/11/shell.php', '2026/11/xss.svg', "2026/11/a\0.jpg"] as $badPath) {
            $blocked = false;
            try {
                $this->controller->serve(Request::create('GET', '/uploads/' . $badPath), $badPath);
            } catch (ForbiddenException|NotFoundException) {
                $blocked = true;
            }
            $this->assertTrue($blocked, "Expected ForbiddenException/NotFoundException for [{$badPath}]");
        }
    }
}
