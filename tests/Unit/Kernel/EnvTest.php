<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Kernel;

use PHPUnit\Framework\TestCase;
use Prime\Kernel\Env;

final class EnvTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $keysToCleanup = [];

    private ?string $tempFile = null;

    protected function tearDown(): void
    {
        foreach ($this->keysToCleanup as $key) {
            Env::unset($key);
        }
        $this->keysToCleanup = [];

        if ($this->tempFile !== null && is_file($this->tempFile)) {
            unlink($this->tempFile);
        }
        $this->tempFile = null;
    }

    public function testParsesCommentsQuotesEscapesAndEmptyValues(): void
    {
        $content = <<<'ENV'
# Full line comment
   # Indented comment
PRIME_SIMPLE=hello
PRIME_INLINE=world # inline comment
PRIME_DOUBLE="Prime \"CMS\" # not a comment\nLine 2"
PRIME_SINGLE='Literal \n # value'
PRIME_EMPTY=
PRIME_EMPTY_QUOTED=""
export PRIME_EXPORTED=exported_val
INVALID_LINE_WITHOUT_EQUALS
ENV;

        $parsed = Env::parse($content);

        $this->assertSame('hello', $parsed['PRIME_SIMPLE']);
        $this->assertSame('world', $parsed['PRIME_INLINE']);
        $this->assertSame("Prime \"CMS\" # not a comment\nLine 2", $parsed['PRIME_DOUBLE']);
        $this->assertSame('Literal \n # value', $parsed['PRIME_SINGLE']);
        $this->assertSame('', $parsed['PRIME_EMPTY']);
        $this->assertSame('', $parsed['PRIME_EMPTY_QUOTED']);
        $this->assertSame('exported_val', $parsed['PRIME_EXPORTED']);
        $this->assertArrayNotHasKey('INVALID_LINE_WITHOUT_EQUALS', $parsed);
    }

    public function testTypeConversionsOnGet(): void
    {
        $this->trackAndSet('PRIME_TEST_TRUE', 'true');
        $this->trackAndSet('PRIME_TEST_FALSE', 'FALSE');
        $this->trackAndSet('PRIME_TEST_NULL', 'null');
        $this->trackAndSet('PRIME_TEST_EMPTY', '');
        $this->trackAndSet('PRIME_TEST_STR', 'Prime CMS');

        $this->assertTrue(Env::get('PRIME_TEST_TRUE'));
        $this->assertFalse(Env::get('PRIME_TEST_FALSE'));
        $this->assertNull(Env::get('PRIME_TEST_NULL', 'fallback'));
        $this->assertSame('', Env::get('PRIME_TEST_EMPTY', 'fallback'));
        $this->assertSame('Prime CMS', Env::get('PRIME_TEST_STR'));
        $this->assertSame('default', Env::get('PRIME_TEST_MISSING_KEY', 'default'));
    }

    public function testLoadDoesNotOverwriteExistingEnvironmentVariables(): void
    {
        $this->trackAndSet('PRIME_PREEXISTING', 'from_os');
        $this->keysToCleanup[] = 'PRIME_FROM_FILE';

        $this->tempFile = tempnam(sys_get_temp_dir(), 'prime_env_');
        $this->assertIsString($this->tempFile);

        file_put_contents($this->tempFile, "PRIME_PREEXISTING=from_file\nPRIME_FROM_FILE=loaded_ok\n");

        Env::load($this->tempFile);

        $this->assertSame('from_os', Env::get('PRIME_PREEXISTING'));
        $this->assertSame('loaded_ok', Env::get('PRIME_FROM_FILE'));
    }

    private function trackAndSet(string $key, string $value): void
    {
        $this->keysToCleanup[] = $key;
        Env::set($key, $value, overwrite: true);
    }
}
