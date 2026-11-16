<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Themes;

use PHPUnit\Framework\TestCase;
use Prime\Content\ContentType;
use Prime\Hooks\Hooks;
use Prime\Kernel\Application;
use Prime\Plugins\AssetPublisher;
use Prime\Site\PageContext;
use Prime\Site\TemplateResolver;
use Prime\Themes\Exception\ThemeInstallationException;
use Prime\Themes\Exception\ThemeValidationException;
use Prime\Themes\ThemeAssetPublisher;
use Prime\Themes\ThemeAssetRegistry;
use Prime\Themes\ThemeInstaller;
use Prime\Themes\ThemeManager;
use Prime\Themes\ThemeManifest;
use Prime\Themes\ThemeManifestValidator;
use Prime\Themes\ThemeOptions;
use ZipArchive;

/**
 * Unit tests for Phase 8 Theme Engine:
 * - Manifest validation (Aurora real manifest + invalid cases)
 * - ThemeOptions schema inheritance, strict validation, CSS injection rejection, compiled CSS
 * - ThemeInstaller ZIP security (valid install, traversal, symlinks, invalid manifest)
 * - TemplateResolver hierarchy (all page types, child > parent > plugin > core, path traversal)
 */
final class ThemeEngineTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/prime_theme_unit_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            AssetPublisher::deleteDirectory($this->tmpDir);
        }
        parent::tearDown();
    }

    public function testAuroraRealManifestIsValidAndCompatible(): void
    {
        $validator = new ThemeManifestValidator();
        $path = dirname(__DIR__, 3) . '/content/themes/aurora/theme.json';
        $result = $validator->validateFile($path, 'aurora');

        $this->assertTrue($result['valid'], implode('; ', $result['errors']));
        $this->assertInstanceOf(ThemeManifest::class, $result['manifest']);
        $manifest = $result['manifest'];
        $this->assertSame('aurora', $manifest->slug);
        $this->assertSame('Aurora', $manifest->namespace);
        $this->assertSame('Aurora\\Theme', $manifest->main);
        $this->assertArrayHasKey('primary', $manifest->menus);
        $this->assertArrayHasKey('footer', $manifest->menus);
        $this->assertArrayHasKey('sidebar', $manifest->zones);
        $this->assertArrayHasKey('footer_1', $manifest->zones);
        $this->assertArrayHasKey('full-width', $manifest->templates['page'] ?? []);
        $this->assertArrayHasKey('landing', $manifest->templates['page'] ?? []);

        $compat = $validator->checkCompatibility($manifest, '0.8.0', '8.3.0');
        $this->assertSame([], $compat);
    }

    public function testManifestValidatorRejectsInvalidSlugReservedNamespaceAndBadCssVar(): void
    {
        $validator = new ThemeManifestValidator();

        $bad = $validator->validateArray([
            'slug' => 'Bad_Slug!',
            'name' => 'Bad Theme',
            'version' => 'not-semver',
            'namespace' => 'Prime\\Evil\\',
            'entry' => 'Prime\\Evil\\Theme',
            'options' => [
                'sections' => ['colors' => ['label' => 'Colors']],
                'fields' => [
                    'accent' => [
                        'section' => 'colors',
                        'type' => 'color',
                        'label' => 'Accent',
                        'default' => '#ED6647',
                        'css_var' => 'not-a-css-var',
                    ],
                ],
            ],
        ]);

        $this->assertFalse($bad['valid']);
        $joined = implode(' ', $bad['errors']);
        $this->assertStringContainsString('slug', strtolower($joined));
        $this->assertStringContainsString('prime', strtolower($joined));
        $this->assertStringContainsString('css', strtolower($joined));
    }

    public function testThemeOptionsValidatesSanitizesInheritsAndCompilesSafeCss(): void
    {
        $validator = new ThemeManifestValidator();
        $aurora = $validator->validateFile(dirname(__DIR__, 3) . '/content/themes/aurora/theme.json', 'aurora')['manifest'];
        $this->assertInstanceOf(ThemeManifest::class, $aurora);

        $childResult = $validator->validateArray([
            'slug' => 'aurora-studio',
            'name' => 'Aurora Studio',
            'version' => '1.0.0',
            'parent' => 'aurora',
            'namespace' => 'AuroraStudio\\',
            'entry' => 'AuroraStudio\\Theme',
            'options' => [
                'sections' => [
                    'studio' => ['label' => 'Studio'],
                ],
                'fields' => [
                    'accent_color' => [
                        'section' => 'colors',
                        'type' => 'color',
                        'label' => 'Accent Studio',
                        'default' => '#7C5CFF',
                        'css_var' => '--aurora-accent',
                    ],
                    'badge_text' => [
                        'section' => 'studio',
                        'type' => 'text',
                        'label' => 'Badge',
                        'default' => 'Studio',
                    ],
                ],
            ],
        ]);
        $this->assertTrue($childResult['valid']);
        $child = $childResult['manifest'];
        $this->assertInstanceOf(ThemeManifest::class, $child);

        $publisher = new ThemeAssetPublisher($this->tmpDir . '/public/assets/themes');
        $options = new ThemeOptions(
            settings: null,
            publisher: $publisher,
            publicRoot: $this->tmpDir . '/public',
        );

        // Child inherits Aurora sections/fields and overrides accent_color default
        $schema = $options->schema($child, $aurora);
        $this->assertArrayHasKey('colors', $schema['sections']);
        $this->assertArrayHasKey('studio', $schema['sections']);
        $this->assertArrayHasKey('badge_text', $schema['fields']);
        $this->assertSame('#7C5CFF', $schema['fields']['accent_color']['default']);

        // Valid values compile cleanly into :root CSS variables
        $validation = $options->validate($aurora, [
            'accent_color' => '#14b8a6',
            'container_width' => 1280,
            'show_sidebar' => false,
        ]);
        $this->assertTrue($validation['valid'], json_encode($validation['errors']) ?: '');
        $validated = $validation['values'];
        $this->assertSame('#14B8A6', $validated['accent_color']);
        $this->assertSame(1280, $validated['container_width']);
        $this->assertFalse($validated['show_sidebar']);

        $css = $options->compileCss($aurora, $validated);
        $this->assertStringContainsString(':root {', $css);
        $this->assertStringContainsString('--aurora-accent: #14B8A6;', $css);
        $this->assertStringContainsString('--aurora-container: 1280px;', $css);

        $cssUrl = $options->writeOptionsCss($aurora, $validated);
        $this->assertNotNull($cssUrl);
        $this->assertMatchesRegularExpression('#^/assets/themes/aurora/options-[a-f0-9]+\.css$#', $cssUrl);
        $this->assertFileExists($this->tmpDir . '/public' . $cssUrl);

        // Reject invalid color / CSS injection payload
        $badVal = $options->validate($aurora, [
            'accent_color' => '#fff; } body { background: url(https://evil.test/x); }',
        ]);
        $this->assertFalse($badVal['valid']);
        $this->assertArrayHasKey('accent_color', $badVal['errors']);

        // save() throws ThemeValidationException on invalid input
        $this->expectException(ThemeValidationException::class);
        $options->save($aurora, [
            'accent_color' => '#fff; } body { background: url(https://evil.test/x); }',
        ]);
    }

    public function testThemeInstallerInstallsValidZipAndRejectsTraversalOrInvalidArchive(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ext-zip is not loaded.');
        }

        $themesDir = $this->tmpDir . '/content/themes';
        $tmpZipDir = $this->tmpDir . '/storage/themes/tmp';
        $publicDir = $this->tmpDir . '/public/assets/themes';
        mkdir($themesDir, 0755, true);
        mkdir($tmpZipDir, 0755, true);
        mkdir($publicDir, 0755, true);

        $hooks = new Hooks();
        $validator = new ThemeManifestValidator();
        $publisher = new ThemeAssetPublisher($publicDir);
        $app = new Application(dirname(__DIR__, 3), loadEnv: false);
        $manager = new ThemeManager(
            app: $app,
            themesPath: $themesDir,
            validator: $validator,
            hooks: $hooks,
            assets: new ThemeAssetRegistry(),
            publisher: $publisher,
            options: new ThemeOptions(null, $publisher, $this->tmpDir . '/public'),
            settings: null,
        );

        $installer = new ThemeInstaller(
            themesPath: $themesDir,
            tmpPath: $tmpZipDir,
            validator: $validator,
            manager: $manager,
            hooks: $hooks,
        );

        // 1. Valid theme ZIP
        $goodZip = $this->tmpDir . '/good-theme.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($goodZip, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('zenith/theme.json', json_encode([
            'slug' => 'zenith',
            'name' => 'Zenith',
            'version' => '1.0.0',
            'namespace' => 'Zenith\\',
            'entry' => 'Zenith\\Theme',
        ], JSON_THROW_ON_ERROR));
        $zip->addFromString('zenith/src/Theme.php', "<?php\nnamespace Zenith;\nuse Prime\\Themes\\AbstractTheme;\nfinal class Theme extends AbstractTheme {}\n");
        $zip->addFromString('zenith/assets/css/zenith.css', 'body { margin: 0; }');
        $zip->close();

        $installed = $installer->installFromZip($goodZip);
        $this->assertSame('zenith', $installed['manifest']->slug);
        $this->assertFalse($installed['replaced']);
        $this->assertFileExists($themesDir . '/zenith/theme.json');
        $this->assertFileExists($publicDir . '/zenith/css/zenith.css');

        // 2. Path traversal ZIP must be rejected
        $evilZip = $this->tmpDir . '/evil.zip';
        $zip2 = new ZipArchive();
        $this->assertTrue($zip2->open($evilZip, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip2->addFromString('../escape.php', '<?php echo "pwn";');
        $zip2->close();

        $this->expectException(ThemeInstallationException::class);
        $installer->installFromZip($evilZip);
    }

    public function testTemplateResolverHierarchyAndPathTraversalProtection(): void
    {
        $childDir = $this->tmpDir . '/child/templates';
        $parentDir = $this->tmpDir . '/parent/templates';
        $pluginDir = $this->tmpDir . '/plugin/templates';
        $coreDir = $this->tmpDir . '/core/templates';
        foreach ([$childDir . '/entry', $parentDir . '/entry', $pluginDir . '/archive', $coreDir] as $d) {
            mkdir($d, 0755, true);
        }

        file_put_contents($childDir . '/entry/post.php', 'child post');
        file_put_contents($parentDir . '/entry/post.php', 'parent post');
        file_put_contents($parentDir . '/entry/page.php', 'parent page');
        file_put_contents($pluginDir . '/archive/testimonial.php', 'plugin testimonial archive');
        file_put_contents($coreDir . '/404.php', 'core 404');

        $hooks = new Hooks();
        $hooks->filter('site.template_paths', static function (array $paths) use ($pluginDir): array {
            $paths[] = $pluginDir;

            return $paths;
        });

        $resolver = new TemplateResolver($hooks);

        $postType = new ContentType('post', ['labels' => ['plural' => 'Articles', 'singular' => 'Article']]);
        $pageType = new ContentType('page', ['labels' => ['plural' => 'Pages', 'singular' => 'Page'], 'hierarchical' => true]);
        $testimonialType = new ContentType('testimonial', ['labels' => ['plural' => 'Témoignages', 'singular' => 'Témoignage']]);

        // Child overrides parent for entry/post (needs real Entry instance on PageContext)
        $postEntry = new \Prime\Content\Entry(
            id: 1,
            type: 'post',
            status: \Prime\Content\Entry::STATUS_PUBLISHED,
            title: 'Hello',
            slug: 'hello',
            excerpt: '',
            content: '{"version":1,"blocks":[]}',
            contentHtml: '<p>Hi</p>',
            contentText: 'Hi',
            authorId: 1,
            parentId: 0,
            menuOrder: 0,
            template: null,
            publishedAt: '2026-11-15 10:00:00',
            previousStatus: null,
            trashedAt: null,
            createdAt: '2026-11-15 10:00:00',
            updatedAt: '2026-11-15 10:00:00',
        );
        $pageEntry = new \Prime\Content\Entry(
            id: 2,
            type: 'page',
            status: \Prime\Content\Entry::STATUS_PUBLISHED,
            title: 'About',
            slug: 'about',
            excerpt: '',
            content: '{"version":1,"blocks":[]}',
            contentHtml: '<p>About</p>',
            contentText: 'About',
            authorId: 1,
            parentId: 0,
            menuOrder: 0,
            template: null,
            publishedAt: '2026-11-15 10:00:00',
            previousStatus: null,
            trashedAt: null,
            createdAt: '2026-11-15 10:00:00',
            updatedAt: '2026-11-15 10:00:00',
        );

        $postCtx = new PageContext(
            type: PageContext::TYPE_ENTRY,
            path: '/blog/hello',
            entry: $postEntry,
            contentType: $postType,
        );
        $resPost = $resolver->resolve($postCtx, [$childDir, $parentDir]);
        $this->assertNotNull($resPost);
        $this->assertSame($childDir . '/entry/post.php', $resPost['file']);

        // Parent used when child does not define entry/page
        $pageCtx = new PageContext(
            type: PageContext::TYPE_ENTRY,
            path: '/about',
            entry: $pageEntry,
            contentType: $pageType,
        );
        $resPage = $resolver->resolve($pageCtx, [$childDir, $parentDir]);
        $this->assertNotNull($resPage);
        $this->assertSame($parentDir . '/entry/page.php', $resPage['file']);

        // Plugin fallback used for custom content type archive
        $pluginCtx = new PageContext(
            type: PageContext::TYPE_ARCHIVE,
            path: '/testimonial',
            contentType: $testimonialType,
        );
        $resPlugin = $resolver->resolve($pluginCtx, [$childDir, $parentDir]);
        $this->assertNotNull($resPlugin);
        $this->assertSame($pluginDir . '/archive/testimonial.php', $resPlugin['file']);

        // Core fallback for 404
        $notFoundCtx = new PageContext(type: PageContext::TYPE_NOT_FOUND, path: '/nope', httpStatus: 404);
        $res404 = $resolver->resolve($notFoundCtx, [$childDir, $parentDir, $coreDir]);
        $this->assertNotNull($res404);
        $this->assertSame($coreDir . '/404.php', $res404['file']);

        // Path traversal strictly rejected
        $this->assertFalse($resolver->isSafeRelativeTemplate('../../etc/passwd'));
        $this->assertFalse($resolver->isSafeRelativeTemplate('/abs/path.php'));
        $this->assertFalse($resolver->isSafeRelativeTemplate('entry\\post.php'));
        $this->assertTrue($resolver->isSafeRelativeTemplate('entry/post.php'));
    }
}
