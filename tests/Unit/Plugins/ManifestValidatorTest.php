<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Plugins;

use PHPUnit\Framework\TestCase;
use Prime\Plugins\ManifestValidator;

final class ManifestValidatorTest extends TestCase
{
    private ManifestValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ManifestValidator();
    }

    public function testValidatesFullManifestWithoutErrors(): void
    {
        $json = json_encode([
            'slug' => 'hello-prime',
            'name' => 'Hello Prime',
            'description' => 'Reference plugin for Prime CMS.',
            'version' => '1.2.3',
            'author' => [
                'name' => 'Prime CMS',
                'url' => 'https://primecms.dev',
            ],
            'license' => 'MIT',
            'namespace' => 'HelloPrime\\',
            'main' => 'HelloPrime\\Plugin',
            'requires' => [
                'prime' => '^0.5',
                'php' => '^8.2',
                'extensions' => ['json', 'mbstring'],
            ],
            'dependencies' => [
                'base-lib' => '^1.0',
            ],
            'icon' => 'assets/icon.svg',
            'accent' => 'violet',
            'settings_url' => '/admin/hello-prime',
        ], JSON_THROW_ON_ERROR);

        $result = $this->validator->validateJson($json, 'hello-prime');

        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['warnings']);
        $this->assertNotNull($result['manifest']);
        $this->assertSame('hello-prime', $result['manifest']->slug);
        $this->assertSame('HelloPrime\\Plugin', $result['manifest']->main);
        $this->assertSame(['base-lib' => '^1.0'], $result['manifest']->dependencies);
        $this->assertSame('HP', $result['manifest']->initials());
    }

    public function testUnknownFieldsProduceWarningsNotErrors(): void
    {
        $json = json_encode([
            'slug' => 'demo-warn',
            'name' => 'Demo Warn',
            'description' => 'Has unknown fields.',
            'version' => '1.0.0',
            'author' => ['name' => 'Prime', 'twitter' => '@prime'],
            'namespace' => 'DemoWarn\\',
            'main' => 'DemoWarn\\Plugin',
            'requires' => ['prime' => '^0.5', 'os' => 'linux'],
            'custom_extra' => true,
        ], JSON_THROW_ON_ERROR);

        $result = $this->validator->validateJson($json, 'demo-warn');

        $this->assertSame([], $result['errors']);
        $this->assertNotNull($result['manifest']);
        $this->assertCount(3, $result['warnings']);
    }

    public function testRejectsSlugDirectoryMismatchAndInvalidSlug(): void
    {
        $json = json_encode([
            'slug' => 'good-slug',
            'name' => 'Good',
            'description' => 'Desc',
            'version' => '1.0.0',
            'author' => ['name' => 'Prime'],
            'namespace' => 'Good\\',
            'main' => 'Good\\Plugin',
        ], JSON_THROW_ON_ERROR);

        $mismatch = $this->validator->validateJson($json, 'wrong-folder');
        $this->assertNull($mismatch['manifest']);
        $this->assertNotEmpty($mismatch['errors']);

        $badSlug = $this->validator->validateJson(str_replace('good-slug', 'Bad_Slug!', $json));
        $this->assertNull($badSlug['manifest']);
        $this->assertNotEmpty($badSlug['errors']);

        $tooShort = $this->validator->validateJson(str_replace('good-slug', 'ab', $json));
        $this->assertNull($tooShort['manifest']);
        $this->assertNotEmpty($tooShort['errors']);
    }

    public function testRejectsReservedNamespaceMainMismatchBadSemverAndBadPaths(): void
    {
        $json = json_encode([
            'slug' => 'bad-plugin',
            'name' => 'Bad Plugin',
            'description' => 'Invalid fields',
            'version' => 'not-semver',
            'author' => ['name' => 'Author', 'url' => 'javascript:alert(1)'],
            'namespace' => 'Prime\\Sneaky\\',
            'main' => 'OtherNamespace\\Plugin',
            'requires' => ['prime' => 'invalid!!'],
            'dependencies' => ['bad-plugin' => '^1.0'],
            'icon' => '../evil.php',
            'accent' => 'neon-pink',
            'settings_url' => 'https://evil.test/phish',
        ], JSON_THROW_ON_ERROR);

        $result = $this->validator->validateJson($json, 'bad-plugin');

        $this->assertNull($result['manifest']);
        $this->assertGreaterThanOrEqual(8, count($result['errors']));
    }

    public function testRejectsMalformedJsonAndMissingRequiredFields(): void
    {
        $malformed = $this->validator->validateJson('{not-json');
        $this->assertNull($malformed['manifest']);
        $this->assertCount(1, $malformed['errors']);

        $empty = $this->validator->validateJson('{}');
        $this->assertNull($empty['manifest']);
        $this->assertGreaterThanOrEqual(6, count($empty['errors']));
    }
}
