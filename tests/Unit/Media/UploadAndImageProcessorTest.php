<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Prime\Database\Connection;
use Prime\Editor\BlockRegistry;
use Prime\Editor\Renderer;
use Prime\Hooks\Hooks;
use Prime\Media\Exception\MediaValidationException;
use Prime\Media\ImageProcessor;
use Prime\Media\Media;
use Prime\Media\MediaConfig;
use Prime\Media\MediaFolderRepository;
use Prime\Media\MediaHelper;
use Prime\Media\MediaRepository;
use Prime\Media\UploadService;
use Prime\Media\UploadsGuard;
use Prime\Plugins\AssetPublisher;
use Prime\Security\HtmlSanitizer;
use Prime\Tests\Support\MediaFixtures;
use Prime\Tests\Support\MysqlEmulatingPdo;

/**
 * Exhaustive unit & security test suite for UploadService, ImageProcessor, MediaHelper,
 * and Phase 7 Editor media blocks (image, gallery, video, audio, file).
 */
final class UploadAndImageProcessorTest extends TestCase
{
    private string $tmpDir = '';
    private Connection $db;
    private MediaConfig $config;
    private MediaRepository $mediaRepo;
    private MediaFolderRepository $folderRepo;
    private UploadService $uploader;
    private Hooks $hooks;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/prime_media_unit_' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir . '/tmp', 0755, true);

        $pdo = new MysqlEmulatingPdo('id');
        $pdo->exec('CREATE TABLE pc_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(191), display_name VARCHAR(191), avatar_media_id INTEGER NULL, status VARCHAR(20))');
        $pdo->exec("INSERT INTO pc_users (id, email, display_name, status) VALUES (1, 'admin@prime.test', 'Admin', 'active')");
        $pdo->exec('CREATE TABLE pc_media_folders (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER NULL, name VARCHAR(120), slug VARCHAR(140), created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_media (id INTEGER PRIMARY KEY AUTOINCREMENT, folder_id INTEGER NULL, author_id INTEGER NULL, disk_path VARCHAR(255) UNIQUE, filename VARCHAR(255), original_name VARCHAR(255), extension VARCHAR(16), mime_type VARCHAR(120), kind VARCHAR(24), size_bytes INTEGER DEFAULT 0, width INTEGER NULL, height INTEGER NULL, checksum CHAR(64), alt VARCHAR(255), title VARCHAR(255), caption TEXT NULL, description TEXT NULL, focal_x REAL DEFAULT 0.5, focal_y REAL DEFAULT 0.5, variants LONGTEXT NULL, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_media_usage (media_id INTEGER, entry_id INTEGER, context VARCHAR(100), PRIMARY KEY (media_id, entry_id, context))');

        $this->db = new Connection(['prefix' => 'pc_'], pdo: $pdo);
        $this->hooks = new Hooks();
        /** @var array<string, mixed> $baseMediaCfg */
        $baseMediaCfg = require dirname(__DIR__, 3) . '/config/media.php';
        $this->config = new MediaConfig($this->tmpDir, $baseMediaCfg, $this->hooks);
        $this->mediaRepo = new MediaRepository($this->db);
        $this->folderRepo = new MediaFolderRepository($this->db);
        $guard = new UploadsGuard($this->config);
        $processor = new ImageProcessor($this->config);

        $this->uploader = new UploadService(
            $this->config,
            $this->mediaRepo,
            $this->folderRepo,
            $processor,
            $guard,
            $this->hooks,
        );
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            AssetPublisher::deleteDirectory($this->tmpDir);
        }
    }

    public function testUploadJpegGeneratesVariantsWebpAndFiresHooks(): void
    {
        $events = [];
        $this->hooks->on('media.before_upload', static function () use (&$events): void {
            $events[] = 'before';
        });
        $this->hooks->on('media.uploaded', static function (Media $m) use (&$events): void {
            $events[] = 'uploaded:' . $m->id;
        });

        $src = MediaFixtures::createJpeg($this->tmpDir . '/tmp/hero.jpg', 1800, 1200);
        $res = $this->uploader->upload(MediaFixtures::uploadedFile($src, 'Mon Super Hero.JPG', 'image/jpeg'));
        $media = $res['media'];

        $this->assertNull($res['duplicate']);
        $this->assertSame(Media::KIND_IMAGE, $media->kind);
        $this->assertSame('jpg', $media->extension);
        $this->assertSame('image/jpeg', $media->mimeType);
        $this->assertSame(1800, $media->width);
        $this->assertSame(1200, $media->height);
        $this->assertStringStartsWith(date('Y/m') . '/', $media->diskPath);
        $this->assertSame(['before', 'uploaded:' . $media->id], $events);

        // Verify all 3 variants exist on disk + WebP companions
        $uploadsRoot = $this->config->uploadsAbsolutePath();
        $this->assertFileExists($uploadsRoot . '/.htaccess');
        $this->assertFileExists($uploadsRoot . '/' . $media->diskPath);

        foreach (['thumbnail', 'medium', 'large'] as $sizeName) {
            $this->assertArrayHasKey($sizeName, $media->variants, "Expected variant [{$sizeName}]");
            $vPath = $media->variants[$sizeName]['path'];
            $this->assertFileExists($uploadsRoot . '/' . $vPath);
            if ($this->config->supportsWebp()) {
                $this->assertNotEmpty($media->variants[$sizeName]['webp'] ?? '');
                $this->assertFileExists($uploadsRoot . '/' . (string) $media->variants[$sizeName]['webp']);
            }
        }

        // Duplicate SHA-256 detection on second identical upload
        $src2 = MediaFixtures::createJpeg($this->tmpDir . '/tmp/hero-dup.jpg', 1800, 1200);
        $res2 = $this->uploader->upload(MediaFixtures::uploadedFile($src2, 'copie.jpg', 'image/jpeg'));
        $this->assertNotNull($res2['duplicate']);
        $this->assertSame($media->id, $res2['duplicate']->id);
    }

    public function testPolyglotJpegHeaderRejectedAndTrailingPhpPayloadStrippedByGdReencoding(): void
    {
        // 1. Header polyglot is immediately rejected by UploadService signature guard
        $headerPolyglot = MediaFixtures::createPolyglotJpegWithPhp($this->tmpDir . '/tmp/polyglot.jpg');
        $this->expectValidationFailure(
            fn () => $this->uploader->upload(MediaFixtures::uploadedFile($headerPolyglot, 'photo.jpg', 'image/jpeg')),
        );

        // 2. Trailing PHP bytes appended beyond header scan are completely stripped by GD re-encoding
        $trailing = MediaFixtures::createJpegWithDeepTrailingPhp($this->tmpDir . '/tmp/trailing.jpg');
        $this->assertStringContainsString('PRIME_TRAILING_MARKER', (string) file_get_contents($trailing));

        $res = $this->uploader->upload(MediaFixtures::uploadedFile($trailing, 'noisy.jpg', 'image/jpeg'));
        $media = $res['media'];
        $uploadsRoot = $this->config->uploadsAbsolutePath();

        foreach ($media->allRelativePaths() as $rel) {
            $bytes = (string) file_get_contents($uploadsRoot . '/' . $rel);
            $this->assertStringNotContainsString('<?php', $bytes, "PHP payload survived in {$rel}");
            $this->assertStringNotContainsString('PRIME_TRAILING_MARKER', $bytes, "Trailing marker survived in {$rel}");
        }
    }

    public function testExifOrientation6AutoRotatesAndStripsExifMetadata(): void
    {
        if (!function_exists('exif_read_data')) {
            $this->markTestSkipped('ext-exif is unavailable.');
        }

        $src = MediaFixtures::createJpegWithExifOrientation6($this->tmpDir . '/tmp/exif6.jpg', 120, 80);
        $beforeExif = @exif_read_data($src);
        $this->assertIsArray($beforeExif);
        $this->assertSame(6, (int) ($beforeExif['Orientation'] ?? 0));
        $this->assertSame('PrimeCamera', (string) ($beforeExif['Make'] ?? ''));

        $res = $this->uploader->upload(MediaFixtures::uploadedFile($src, 'portrait.jpg', 'image/jpeg'));
        $media = $res['media'];

        // 120x80 rotated 90° CW -> 80x120 portrait!
        $this->assertSame(80, $media->width);
        $this->assertSame(120, $media->height);

        // EXIF Make/Orientation metadata must be completely stripped after GD re-encode
        $storedPath = $this->config->uploadsAbsolutePath() . '/' . $media->diskPath;
        $storedBytes = (string) file_get_contents($storedPath);
        $this->assertStringNotContainsString('PrimeCamera', $storedBytes);
    }

    public function testTransparentPngPreservesAlphaChannel(): void
    {
        $src = MediaFixtures::createTransparentPng($this->tmpDir . '/tmp/logo.png', 500, 500);
        $res = $this->uploader->upload(MediaFixtures::uploadedFile($src, 'logo.png', 'image/png'));
        $media = $res['media'];

        $storedAbs = $this->config->uploadsAbsolutePath() . '/' . $media->diskPath;
        $im = imagecreatefrompng($storedAbs);
        $this->assertNotSame(false, $im);

        // Top-left pixel (5,5) must stay fully transparent (alpha = 127)
        $rgbaTopLeft = imagecolorsforindex($im, (int) imagecolorat($im, 5, 5));
        $this->assertSame(127, $rgbaTopLeft['alpha']);

        // Bottom-right pixel must remain opaque emerald (alpha = 0)
        $rgbaBottomRight = imagecolorsforindex($im, (int) imagecolorat($im, 450, 450));
        $this->assertSame(0, $rgbaBottomRight['alpha']);
        imagedestroy($im);
    }

    public function testFocalPointChangeProducesMeasurablyDifferentCroppedThumbnail(): void
    {
        // Two-tone 900×300 image: left half RED (210,40,40), right half BLUE (30,80,210)
        $src = MediaFixtures::createJpeg($this->tmpDir . '/tmp/wide.jpg', 900, 300);
        $media = $this->uploader->upload(MediaFixtures::uploadedFile($src, 'wide.jpg', 'image/jpeg'))['media'];

        // Shift focal point to far left (0.05, 0.5) -> square thumbnail should sample RED side
        $leftFocused = $this->uploader->updateMetadata($media, ['focal_x' => 0.05, 'focal_y' => 0.5]);
        $thumbAbs = $this->config->uploadsAbsolutePath() . '/' . $leftFocused->variants['thumbnail']['path'];
        $imLeft = imagecreatefromjpeg($thumbAbs);
        $this->assertNotSame(false, $imLeft);
        $cLeft = imagecolorsforindex($imLeft, (int) imagecolorat($imLeft, (int) (imagesx($imLeft) / 2), (int) (imagesy($imLeft) / 2)));
        imagedestroy($imLeft);
        $this->assertGreaterThan(160, $cLeft['red'], 'Left-focused crop should be predominantly red.');
        $this->assertLessThan(90, $cLeft['blue']);

        // Shift focal point to far right (0.95, 0.5) -> square thumbnail should sample BLUE side
        $rightFocused = $this->uploader->updateMetadata($leftFocused, ['focal_x' => 0.95, 'focal_y' => 0.5]);
        $thumbAbs2 = $this->config->uploadsAbsolutePath() . '/' . $rightFocused->variants['thumbnail']['path'];
        $imRight = imagecreatefromjpeg($thumbAbs2);
        $this->assertNotSame(false, $imRight);
        $cRight = imagecolorsforindex($imRight, (int) imagecolorat($imRight, (int) (imagesx($imRight) / 2), (int) (imagesy($imRight) / 2)));
        imagedestroy($imRight);
        $this->assertGreaterThan(160, $cRight['blue'], 'Right-focused crop should be predominantly blue.');
        $this->assertLessThan(90, $cRight['red']);
    }

    public function testGifWebpPdfAndZipUploadsSucceedAndPathTraversalFilenameIsRejected(): void
    {
        $gif = MediaFixtures::createGif($this->tmpDir . '/tmp/anim.gif');
        $webp = MediaFixtures::createWebp($this->tmpDir . '/tmp/modern.webp');
        $pdf = MediaFixtures::createPdf($this->tmpDir . '/tmp/report.pdf');
        $zip = MediaFixtures::createZip($this->tmpDir . '/tmp/bundle.zip');

        // Path traversal client filename is strictly rejected
        $this->expectValidationFailure(
            fn () => $this->uploader->upload(MediaFixtures::uploadedFile($gif, '../../etc/passwd.gif', 'image/gif')),
        );

        $mGif = $this->uploader->upload(MediaFixtures::uploadedFile($gif, 'anim.gif', 'image/gif'))['media'];
        $mWebp = $this->uploader->upload(MediaFixtures::uploadedFile($webp, 'hero.webp', 'image/webp'))['media'];
        $mPdf = $this->uploader->upload(MediaFixtures::uploadedFile($pdf, 'rapport-2026.pdf', 'application/pdf'))['media'];
        $mZip = $this->uploader->upload(MediaFixtures::uploadedFile($zip, 'assets.zip', 'application/zip'))['media'];

        $this->assertStringNotContainsString('..', $mGif->diskPath);
        $this->assertSame(Media::KIND_IMAGE, $mGif->kind);
        $this->assertSame(Media::KIND_IMAGE, $mWebp->kind);
        $this->assertSame(Media::KIND_DOCUMENT, $mPdf->kind);
        $this->assertSame(Media::KIND_ARCHIVE, $mZip->kind);
    }

    public function testSecurityRejectsDangerousExtensionsMimeSpoofingPixelBombsAndOversizedFiles(): void
    {
        // 1. Direct PHP script
        $phpFile = $this->tmpDir . '/tmp/evil.php';
        file_put_contents($phpFile, '<?php system("id"); ?>');
        $this->expectValidationFailure(fn () => $this->uploader->upload(MediaFixtures::uploadedFile($phpFile, 'evil.php', 'text/x-php')));

        // 2. Double extension shell.php.jpg (even with valid JPEG bytes!)
        $validJpg = MediaFixtures::createJpeg($this->tmpDir . '/tmp/double.jpg', 100, 100);
        $this->expectValidationFailure(fn () => $this->uploader->upload(MediaFixtures::uploadedFile($validJpg, 'shell.php.jpg', 'image/jpeg')));

        // 3. PHP code disguised with .jpg extension (finfo MIME mismatch)
        $this->expectValidationFailure(fn () => $this->uploader->upload(MediaFixtures::uploadedFile($phpFile, 'innocent.jpg', 'image/jpeg')));

        // 4. SVG XSS (banned by default allowlist)
        $svgFile = $this->tmpDir . '/tmp/xss.svg';
        file_put_contents($svgFile, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->expectValidationFailure(fn () => $this->uploader->upload(MediaFixtures::uploadedFile($svgFile, 'logo.svg', 'image/svg+xml')));
        $this->expectValidationFailure(fn () => $this->uploader->upload(MediaFixtures::uploadedFile($svgFile, 'logo.png', 'image/png')));

        // 5. Empty 0-byte file
        $emptyFile = $this->tmpDir . '/tmp/empty.jpg';
        file_put_contents($emptyFile, '');
        $this->expectValidationFailure(fn () => $this->uploader->upload(MediaFixtures::uploadedFile($emptyFile, 'empty.jpg', 'image/jpeg')));

        // 6. Pixel-bomb 100 MP PNG rejected BEFORE GD allocates memory
        $bombPng = MediaFixtures::createPixelBombPng($this->tmpDir . '/tmp/bomb.png', 10000, 10000);
        $this->expectValidationFailure(fn () => $this->uploader->upload(MediaFixtures::uploadedFile($bombPng, 'bomb.png', 'image/png')));

        // 7. Corrupted/truncated JPEG
        $corrupt = $this->tmpDir . '/tmp/corrupt.jpg';
        file_put_contents($corrupt, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00truncated");
        $this->expectValidationFailure(fn () => $this->uploader->upload(MediaFixtures::uploadedFile($corrupt, 'corrupt.jpg', 'image/jpeg')));

        // 8. Oversized file exceeding configured 1 MB limit
        $smallConfig = new MediaConfig($this->tmpDir, ['max_upload_mb' => 1]);
        $strictUploader = new UploadService(
            $smallConfig,
            $this->mediaRepo,
            $this->folderRepo,
            new ImageProcessor($smallConfig),
            new UploadsGuard($smallConfig),
        );
        $bigPdf = $this->tmpDir . '/tmp/big.pdf';
        file_put_contents($bigPdf, "%PDF-1.4\n" . str_repeat('0', 1_200_000) . "\n%%EOF");
        $this->expectValidationFailure(fn () => $strictUploader->upload(MediaFixtures::uploadedFile($bigPdf, 'big.pdf', 'application/pdf')));
    }

    public function testReplacePreservesIdAndMetadataAndDeleteCleansAllFilesFromDisk(): void
    {
        $src1 = MediaFixtures::createJpeg($this->tmpDir . '/tmp/v1.jpg', 800, 600);
        $original = $this->uploader->upload(
            MediaFixtures::uploadedFile($src1, 'banner.jpg', 'image/jpeg'),
            metadata: ['title' => 'Bannière Accueil', 'alt' => 'Illustration bannière'],
        )['media'];

        $src2 = MediaFixtures::createTransparentPng($this->tmpDir . '/tmp/v2.png', 400, 400);
        $replaced = $this->uploader->replace($original, MediaFixtures::uploadedFile($src2, 'banner-v2.png', 'image/png'));

        $this->assertSame($original->id, $replaced->id);
        $this->assertSame('Bannière Accueil', $replaced->title);
        $this->assertSame('Illustration bannière', $replaced->alt);
        $this->assertSame('png', $replaced->extension);
        $this->assertSame(400, $replaced->width);

        $uploadsRoot = $this->config->uploadsAbsolutePath();
        // Old jpg path was cleaned up, new png path exists
        $this->assertFileDoesNotExist($uploadsRoot . '/' . $original->diskPath);
        $this->assertFileExists($uploadsRoot . '/' . $replaced->diskPath);

        $pathsBeforeDelete = $replaced->allRelativePaths();
        $this->uploader->delete($replaced);

        $this->assertNull($this->mediaRepo->find($replaced->id));
        foreach ($pathsBeforeDelete as $rel) {
            $this->assertFileDoesNotExist($uploadsRoot . '/' . $rel);
        }
    }

    public function testMediaHelperAndPhase7EditorBlocksRenderSafely(): void
    {
        $src = MediaFixtures::createJpeg($this->tmpDir . '/tmp/article.jpg', 1600, 1000);
        $img = $this->uploader->upload(
            MediaFixtures::uploadedFile($src, 'article.jpg', 'image/jpeg'),
            metadata: ['title' => 'Vue Montagne', 'alt' => 'Montagne <script>alert(1)</script>'],
        )['media'];
        $img = $this->uploader->updateMetadata($img, ['focal_x' => 0.25, 'focal_y' => 0.75]);

        $pdfSrc = MediaFixtures::createPdf($this->tmpDir . '/tmp/brochure.pdf');
        $pdf = $this->uploader->upload(
            MediaFixtures::uploadedFile($pdfSrc, 'brochure.pdf', 'application/pdf'),
            metadata: ['title' => 'Brochure 2026'],
        )['media'];

        // MediaHelper unit checks
        $this->assertStringContainsString('/uploads/', (string) MediaHelper::url($img, 'medium'));
        $this->assertNotSame('', MediaHelper::srcset($img));
        $pictureHtml = MediaHelper::image($img, ['sizes' => '(max-width: 768px) 100vw, 800px', 'class' => 'hero-img']);
        $this->assertStringContainsString('<picture>', $pictureHtml);
        $this->assertStringContainsString('type="image/webp"', $pictureHtml);
        $this->assertStringContainsString('object-position:25% 75%', $pictureHtml);
        $this->assertStringContainsString('alt="Montagne &lt;script&gt;alert(1)&lt;/script&gt;"', $pictureHtml);
        $this->assertStringNotContainsString('<script>', $pictureHtml);
        $this->assertSame('', MediaHelper::image($pdf));
        $this->assertSame('', MediaHelper::image(null));

        // Decorative image forces empty alt + aria-hidden
        $deco = MediaHelper::image($img, ['decorative' => true]);
        $this->assertStringContainsString('alt=""', $deco);
        $this->assertStringContainsString('aria-hidden="true"', $deco);

        // Editor BlockRegistry rendering for image, gallery, video, audio, file
        $registry = new BlockRegistry(null, $this->mediaRepo);
        $renderer = new Renderer($registry, new HtmlSanitizer());

        $doc = [
            'version' => 1,
            'blocks' => [
                ['id' => 'b1', 'type' => 'image', 'data' => ['mediaId' => $img->id, 'align' => 'wide', 'caption' => 'Légende <em>propre</em>']],
                ['id' => 'b2', 'type' => 'gallery', 'data' => ['columns' => 3, 'items' => [['mediaId' => $img->id, 'caption' => 'Photo 1']]]],
                ['id' => 'b3', 'type' => 'video', 'data' => ['url' => '/uploads/2026/11/clip.mp4', 'controls' => true, 'caption' => 'Démo vidéo']],
                ['id' => 'b4', 'type' => 'audio', 'data' => ['url' => '/uploads/2026/11/podcast.mp3', 'title' => 'Épisode 1']],
                ['id' => 'b5', 'type' => 'file', 'data' => ['mediaId' => $pdf->id, 'showSize' => true]],
            ],
        ];

        $validated = $renderer->validateAndNormalize($doc);
        $this->assertSame([], $validated['errors']);

        $html = $renderer->renderHtml($validated['document']);
        $this->assertStringContainsString('<picture>', $html);
        $this->assertStringContainsString('pc-block--gallery pc-gallery--cols-3', $html);
        $this->assertStringContainsString('<video ', $html);
        $this->assertStringContainsString('<audio ', $html);
        $this->assertStringContainsString('pc-block--file', $html);
        $this->assertStringContainsString('Brochure 2026', $html);
        $this->assertStringContainsString('download', $html);
    }

    private function expectValidationFailure(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected MediaValidationException to be thrown.');
        } catch (MediaValidationException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
    }
}
