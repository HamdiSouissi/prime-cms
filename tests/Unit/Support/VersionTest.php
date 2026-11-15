<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prime\Support\Exception\InvalidVersionException;
use Prime\Support\Version;

/**
 * Exhaustive SemVer 2.0 parsing, comparison, and constraint evaluation test suite (75+ cases).
 */
final class VersionTest extends TestCase
{
    public function testParsesValidSemVerAndIgnoresBuildMetadataInPrecedence(): void
    {
        $parsed = Version::parse('1.2.3-beta.11+exp.sha.5114f85');
        $this->assertSame(1, $parsed['major']);
        $this->assertSame(2, $parsed['minor']);
        $this->assertSame(3, $parsed['patch']);
        $this->assertSame(['beta', '11'], $parsed['prerelease']);
        $this->assertSame('exp.sha.5114f85', $parsed['build']);

        $this->assertTrue(Version::isValid('0.5.0'));
        $this->assertTrue(Version::isValid('1.0.0-alpha.1'));
        $this->assertFalse(Version::isValid('01.2.3'));
        $this->assertFalse(Version::isValid('1.2'));
        $this->assertFalse(Version::isValid('v1.2.3'));
        $this->assertFalse(Version::isValid('1.0.0-01'));
        $this->assertFalse(Version::isValid(''));

        // Build metadata MUST NOT affect precedence (SemVer 2.0 §10)
        $this->assertSame(0, Version::compare('1.0.0+build.1', '1.0.0+build.999'));
        $this->assertSame(0, Version::compare('1.0.0-alpha+001', '1.0.0-alpha+20130313'));
    }

    public function testSemVerPrecedenceOrderMatchesSpecification(): void
    {
        // Official SemVer 2.0 §11.4.4 precedence chain
        $ordered = [
            '1.0.0-alpha',
            '1.0.0-alpha.1',
            '1.0.0-alpha.2',
            '1.0.0-alpha.10',
            '1.0.0-alpha.beta',
            '1.0.0-beta',
            '1.0.0-beta.2',
            '1.0.0-beta.11',
            '1.0.0-rc.1',
            '1.0.0',
            '1.0.1-alpha.1',
            '1.0.1',
            '1.1.0',
            '2.0.0-alpha.1',
            '2.0.0',
        ];

        $count = count($ordered);
        for ($i = 0; $i < $count; $i++) {
            $this->assertSame(0, Version::compare($ordered[$i], $ordered[$i]));
            for ($j = $i + 1; $j < $count; $j++) {
                $this->assertSame(-1, Version::compare($ordered[$i], $ordered[$j]), "{$ordered[$i]} < {$ordered[$j]}");
                $this->assertSame(1, Version::compare($ordered[$j], $ordered[$i]), "{$ordered[$j]} > {$ordered[$i]}");
            }
        }
    }

    public function testNormalizeRuntimeVersionHandlesDistroSuffixes(): void
    {
        $this->assertSame('8.3.6', Version::normalizeRuntimeVersion('8.3.6-0ubuntu0.24.04.1'));
        $this->assertSame('8.2.35', Version::normalizeRuntimeVersion('8.2.35'));
        $this->assertSame('8.4.0', Version::normalizeRuntimeVersion('v8.4'));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function satisfiesProvider(): array
    {
        return [
            // Exact & equality
            'exact match' => ['1.2.3', '1.2.3', true],
            'exact mismatch patch' => ['1.2.4', '1.2.3', false],
            'equals operator match' => ['1.2.3', '=1.2.3', true],
            'double equals operator match' => ['1.2.3', '==1.2.3', true],
            'equals operator mismatch' => ['1.2.4', '=1.2.3', false],
            'equals with build metadata' => ['1.2.3+abc', '=1.2.3', true],
            'not equals true' => ['1.2.4', '!=1.2.3', true],
            'not equals false' => ['1.2.3', '!=1.2.3', false],
            'bare minor range match' => ['1.2.7', '1.2', true],
            'bare minor range upper reject' => ['1.3.0', '1.2', false],
            'bare major range match' => ['1.9.9', '1', true],
            'bare major range upper reject' => ['2.0.0', '1', false],

            // Comparison operators (>, >=, <, <=)
            'gt true' => ['1.2.4', '>1.2.3', true],
            'gt equal false' => ['1.2.3', '>1.2.3', false],
            'gte equal true' => ['1.2.3', '>=1.2.3', true],
            'gte higher true' => ['2.0.0', '>=1.2.3', true],
            'gte lower false' => ['1.2.2', '>=1.2.3', false],
            'gte with space' => ['8.3.1', '>= 8.2', true],
            'gte partial php match' => ['8.2.0', '>=8.2', true],
            'gte partial php reject' => ['8.1.99', '>=8.2', false],
            'lt true' => ['1.2.2', '<1.2.3', true],
            'lt equal false' => ['1.2.3', '<1.2.3', false],
            'lte equal true' => ['1.2.3', '<=1.2.3', true],
            'lte higher false' => ['1.2.4', '<=1.2.3', false],
            'lte partial minor true' => ['1.2.9', '<=1.2', true],
            'lte partial minor false' => ['1.3.0', '<=1.2', false],
            'gt partial minor true' => ['1.3.0', '>1.2', true],
            'gt partial minor false' => ['1.2.9', '>1.2', false],

            // Caret ^ (major >= 1)
            'caret 1.2.3 exact' => ['1.2.3', '^1.2.3', true],
            'caret 1.2.3 minor bump' => ['1.9.0', '^1.2.3', true],
            'caret 1.2.3 rejects 2.0.0' => ['2.0.0', '^1.2.3', false],
            'caret 1.2.3 rejects 2.0.0-alpha.1' => ['2.0.0-alpha.1', '^1.2.3', false],
            'caret 1.2.3 rejects lower' => ['1.2.2', '^1.2.3', false],
            'caret 1.2 partial match' => ['1.8.4', '^1.2', true],
            'caret 1 partial match' => ['1.0.0', '^1', true],
            'caret 1 partial reject 2.0.0' => ['2.0.0', '^1', false],

            // Caret ^ edge cases for 0.x and 0.0.x (critical SemVer rules!)
            'caret 0.5 matches 0.5.0 (Prime 0.5)' => ['0.5.0', '^0.5', true],
            'caret 0.5 matches 0.5.9' => ['0.5.9', '^0.5', true],
            'caret 0.5 rejects 0.6.0' => ['0.6.0', '^0.5', false],
            'caret 0.5 rejects 0.6.0-alpha.1' => ['0.6.0-alpha.1', '^0.5', false],
            'caret 0.5 rejects 0.4.9' => ['0.4.9', '^0.5', false],
            'caret 0.2.3 exact' => ['0.2.3', '^0.2.3', true],
            'caret 0.2.3 patch bump' => ['0.2.9', '^0.2.3', true],
            'caret 0.2.3 rejects 0.3.0' => ['0.3.0', '^0.2.3', false],
            'caret 0.2.3 rejects 0.2.2' => ['0.2.2', '^0.2.3', false],
            'caret 0.0.3 exact only' => ['0.0.3', '^0.0.3', true],
            'caret 0.0.3 rejects 0.0.4' => ['0.0.4', '^0.0.3', false],
            'caret 0.0.3 rejects 0.0.4-alpha.1' => ['0.0.4-alpha.1', '^0.0.3', false],
            'caret 0.0.3 rejects 0.0.2' => ['0.0.2', '^0.0.3', false],
            'caret 0.0.0 exact only' => ['0.0.0', '^0.0.0', true],
            'caret 0.0.0 rejects 0.0.1' => ['0.0.1', '^0.0.0', false],
            'caret 0.0 matches 0.0.9' => ['0.0.9', '^0.0', true],
            'caret 0.0 rejects 0.1.0' => ['0.1.0', '^0.0', false],
            'caret 0 matches 0.9.9' => ['0.9.9', '^0', true],
            'caret 0 rejects 1.0.0' => ['1.0.0', '^0', false],

            // Tilde ~
            'tilde 1.2.3 exact' => ['1.2.3', '~1.2.3', true],
            'tilde 1.2.3 patch bump' => ['1.2.9', '~1.2.3', true],
            'tilde 1.2.3 rejects minor bump' => ['1.3.0', '~1.2.3', false],
            'tilde 1.2.3 rejects 1.3.0-alpha.1' => ['1.3.0-alpha.1', '~1.2.3', false],
            'tilde 1.2 matches 1.2.5' => ['1.2.5', '~1.2', true],
            'tilde 1.2 rejects 1.3.0' => ['1.3.0', '~1.2', false],
            'tilde 1 matches 1.9.0' => ['1.9.0', '~1', true],
            'tilde 1 rejects 2.0.0' => ['2.0.0', '~1', false],
            'tilde 0.2.3 matches 0.2.9' => ['0.2.9', '~0.2.3', true],
            'tilde 0.2.3 rejects 0.3.0' => ['0.3.0', '~0.2.3', false],
            'tilde 0.0.3 matches 0.0.9' => ['0.0.9', '~0.0.3', true],
            'tilde 0.0.3 rejects 0.1.0' => ['0.1.0', '~0.0.3', false],

            // Wildcards (*, x, X)
            'star matches any release' => ['9.8.7', '*', true],
            'star matches 0.0.1' => ['0.0.1', '*', true],
            'star rejects prerelease' => ['1.0.0-alpha.1', '*', false],
            '1.x matches 1.5.2' => ['1.5.2', '1.x', true],
            '1.x rejects 2.0.0' => ['2.0.0', '1.x', false],
            '1.* matches 1.0.0' => ['1.0.0', '1.*', true],
            '1.2.x matches 1.2.0' => ['1.2.0', '1.2.x', true],
            '1.2.x matches 1.2.42' => ['1.2.42', '1.2.x', true],
            '1.2.x rejects 1.3.0' => ['1.3.0', '1.2.x', false],
            '1.2.X uppercase matches' => ['1.2.3', '1.2.X', true],
            '1.2.* rejects 1.1.9' => ['1.1.9', '1.2.*', false],

            // AND combinations (space & comma)
            'and space match' => ['1.5.0', '>=1.2.0 <2.0.0', true],
            'and space reject upper' => ['2.0.0', '>=1.2.0 <2.0.0', false],
            'and space reject lower' => ['1.1.9', '>=1.2.0 <2.0.0', false],
            'and comma match' => ['1.5.0', '>=1.2.0, <2.0.0', true],
            'and comma with exclusion reject' => ['1.5.0', '>=1.2.0, <2.0.0, !=1.5.0', false],
            'and comma with exclusion pass' => ['1.5.1', '>=1.2.0, <2.0.0, !=1.5.0', true],

            // OR combinations (||)
            'or first branch match' => ['1.4.0', '^1.2 || ^2.0', true],
            'or second branch match' => ['2.3.1', '^1.2 || ^2.0', true],
            'or neither branch match' => ['3.0.0', '^1.2 || ^2.0', false],
            'or complex with and' => ['2.5.0', '>=1.0.0 <1.5.0 || >=2.4.0, <3.0.0', true],
            'or complex gap reject' => ['1.9.0', '>=1.0.0 <1.5.0 || >=2.4.0, <3.0.0', false],

            // Prerelease matching rules
            'prerelease exact match' => ['1.2.3-beta.2', '1.2.3-beta.2', true],
            'prerelease exact mismatch' => ['1.2.3-beta.1', '1.2.3-beta.2', false],
            'caret prerelease matches higher prerelease same tuple' => ['1.2.3-beta.4', '^1.2.3-beta.2', true],
            'caret prerelease matches stable release' => ['1.2.3', '^1.2.3-beta.2', true],
            'caret prerelease matches higher minor release' => ['1.5.0', '^1.2.3-beta.2', true],
            'caret prerelease rejects prerelease of other tuple' => ['1.2.4-alpha.1', '^1.2.3-beta.2', false],
            'caret prerelease rejects lower prerelease' => ['1.2.3-alpha.9', '^1.2.3-beta.2', false],
            'stable range rejects prerelease' => ['1.5.0-rc.1', '>=1.0.0 <2.0.0', false],
            'explicit gte prerelease allows same tuple rc' => ['2.0.0-rc.1', '>=2.0.0-beta.1 <3.0.0', true],
            'explicit gte prerelease rejects next tuple prerelease' => ['2.1.0-alpha.1', '>=2.0.0-beta.1 <3.0.0', false],
        ];
    }

    #[DataProvider('satisfiesProvider')]
    public function testSatisfiesConstraint(string $version, string $constraint, bool $expected): void
    {
        $this->assertSame($expected, Version::satisfies($version, $constraint));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidConstraintProvider(): array
    {
        return [
            'empty' => [''],
            'whitespace only' => ['   '],
            'trailing or' => ['^1.0 || '],
            'leading or' => ['|| ^1.0'],
            'double comma' => ['>=1.0, , <2.0'],
            'unknown operator' => ['@1.0.0'],
            'invalid version token' => ['^abc'],
            'leading zero in major' => ['^01.2.3'],
            'double caret' => ['^^1.0.0'],
            'lone operator' => ['>='],
        ];
    }

    #[DataProvider('invalidConstraintProvider')]
    public function testInvalidConstraintThrowsClearException(string $badConstraint): void
    {
        $this->assertFalse(Version::isValidConstraint($badConstraint));
        $this->expectException(InvalidVersionException::class);
        Version::satisfies('1.0.0', $badConstraint);
    }
}
