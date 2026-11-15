<?php

declare(strict_types=1);

namespace Prime\Support;

use Prime\Support\Exception\InvalidVersionException;

/**
 * Pure-PHP SemVer 2.0 version parser, comparator, and constraint evaluator.
 *
 * Supports exact versions (`1.2.3`), equality/comparison operators (`=`, `==`, `!=`, `>`, `>=`, `<`, `<=`),
 * caret (`^`), tilde (`~`), wildcards (`*`, `1.*`, `1.2.x`), AND combinations (space or comma),
 * and OR combinations (`||`).
 */
final class Version
{
    /**
     * Strict SemVer 2.0 regex (https://semver.org/).
     */
    private const SEMVER_PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';

    /**
     * Validate whether a string is a valid SemVer 2.0 version (without leading `v`).
     */
    public static function isValid(string $version): bool
    {
        return preg_match(self::SEMVER_PATTERN, trim($version)) === 1;
    }

    /**
     * Parse a strict SemVer 2.0 version string into structured components.
     *
     * @return array{major: int, minor: int, patch: int, prerelease: list<string>, build: ?string}
     */
    public static function parse(string $version): array
    {
        $trimmed = trim($version);
        if (preg_match(self::SEMVER_PATTERN, $trimmed, $m) !== 1) {
            throw new InvalidVersionException(
                sprintf('Version SemVer invalide : "%s" (format attendu : MAJEUR.MINEUR.CORRECTIF).', $version),
            );
        }

        $prerelease = isset($m[4]) && $m[4] !== '' ? explode('.', $m[4]) : [];
        $build = $m[5] ?? null;

        return [
            'major' => (int) $m[1],
            'minor' => (int) $m[2],
            'patch' => (int) $m[3],
            'prerelease' => $prerelease,
            'build' => $build,
        ];
    }

    /**
     * Normalize runtime PHP version strings (e.g. `8.3.6-0ubuntu0.24.04.1` or `8.2.35`) to strict SemVer.
     */
    public static function normalizeRuntimeVersion(string $version): string
    {
        $trimmed = ltrim(trim($version), 'vV');
        if (self::isValid($trimmed)) {
            return $trimmed;
        }

        if (preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $trimmed, $m) === 1) {
            return sprintf('%d.%d.%d', (int) $m[1], (int) $m[2], isset($m[3]) ? (int) $m[3] : 0);
        }

        throw new InvalidVersionException(sprintf('Impossible de normaliser la version "%s".', $version));
    }

    /**
     * Compare two SemVer 2.0 versions according to SemVer 2.0 §11 precedence rules.
     *
     * Returns `-1` when `$a < $b`, `0` when `$a == $b`, or `1` when `$a > $b`.
     */
    public static function compare(string $a, string $b): int
    {
        return self::compareParsed(self::parse($a), self::parse($b));
    }

    /**
     * Determine whether a SemVer 2.0 `$version` satisfies a `$constraint` expression.
     */
    public static function satisfies(string $version, string $constraint): bool
    {
        $candidate = self::parse($version);
        $branches = self::parseConstraint($constraint);

        foreach ($branches as $comparators) {
            if (self::matchesAndBranch($candidate, $comparators)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate a constraint expression without evaluating a version against it.
     */
    public static function isValidConstraint(string $constraint): bool
    {
        try {
            self::parseConstraint($constraint);

            return true;
        } catch (InvalidVersionException) {
            return false;
        }
    }

    /**
     * Parse an OR/AND constraint string into a non-empty list of AND comparator sets.
     *
     * @return non-empty-list<non-empty-list<array{op: string, target: array{major: int, minor: int, patch: int, prerelease: list<string>, build: ?string}, explicitPrerelease: bool}>>
     */
    private static function parseConstraint(string $constraint): array
    {
        $trimmed = trim($constraint);
        if ($trimmed === '') {
            throw new InvalidVersionException('La contrainte de version ne peut pas être vide.');
        }

        $orParts = explode('||', $trimmed);
        $branches = [];

        foreach ($orParts as $branchRaw) {
            $branchTrimmed = trim($branchRaw);
            if ($branchTrimmed === '') {
                throw new InvalidVersionException(
                    sprintf('Contrainte de version invalide "%s" : branche OU (||) vide.', $constraint),
                );
            }

            $tokens = self::tokenizeAndBranch($branchTrimmed, $constraint);
            $comparators = [];

            foreach ($tokens as $token) {
                foreach (self::expandToken($token, $constraint) as $comp) {
                    $comparators[] = $comp;
                }
            }

            if ($comparators === []) {
                throw new InvalidVersionException(
                    sprintf('Contrainte de version invalide "%s" : aucun comparateur détecté.', $constraint),
                );
            }

            $branches[] = $comparators;
        }

        return $branches;
    }

    /**
     * Split a single AND branch into individual comparator tokens (supporting spaces and commas,
     * as well as optional whitespace between an operator and its version like `>= 1.2.0`).
     *
     * @return list<string>
     */
    private static function tokenizeAndBranch(string $branch, string $fullConstraint): array
    {
        // Reject dangling comma at start/end or double commas like ", ,"
        if (preg_match('/^,|,$|,\s*,/', $branch) === 1) {
            throw new InvalidVersionException(
                sprintf('Contrainte de version invalide "%s" : virgule mal placée.', $fullConstraint),
            );
        }

        // Normalize commas to spaces, then merge standalone operators with their following version token.
        $normalized = (string) preg_replace('/\s*,\s*/', ' ', $branch);
        $normalized = (string) preg_replace('/(>=|<=|!=|==|[=><^~])\s+/', '$1', $normalized);

        $rawTokens = preg_split('/\s+/', trim($normalized));
        if ($rawTokens === false || $rawTokens === [] || $rawTokens[0] === '') {
            throw new InvalidVersionException(
                sprintf('Contrainte de version invalide "%s".', $fullConstraint),
            );
        }

        return $rawTokens;
    }

    /**
     * Expand one comparator token into one or two primitive (`=`, `!=`, `>`, `>=`, `<`, `<=`) comparators.
     *
     * @return non-empty-list<array{op: string, target: array{major: int, minor: int, patch: int, prerelease: list<string>, build: ?string}, explicitPrerelease: bool}>
     */
    private static function expandToken(string $token, string $fullConstraint): array
    {
        // Wildcard matching any stable version (`*`, `x`, `X`, `*.*`, `*.*.*`)
        if (preg_match('/^[*xX](?:\.[*xX]){0,2}$/', $token) === 1) {
            return [
                [
                    'op' => '>=',
                    'target' => self::tuple(0, 0, 0),
                    'explicitPrerelease' => false,
                ],
            ];
        }

        // Caret range: ^1.2.3, ^0.2.3, ^0.0.3, ^1.2, ^0.5, ^1, ^1.2.3-beta.1
        if (str_starts_with($token, '^')) {
            return self::expandCaret(substr($token, 1), $fullConstraint);
        }

        // Tilde range: ~1.2.3, ~1.2, ~1, ~0.2.3
        if (str_starts_with($token, '~')) {
            return self::expandTilde(substr($token, 1), $fullConstraint);
        }

        // Explicit comparison operator: >=, <=, !=, ==, =, >, <
        if (preg_match('/^(>=|<=|!=|==|=|>|<)(.+)$/', $token, $m) === 1) {
            $op = $m[1] === '==' ? '=' : $m[1];
            $verPart = $m[2];

            return self::expandOperator($op, $verPart, $fullConstraint);
        }

        // Reject unknown leading operator characters (e.g. `@1.0`, `!1.0`, `<>1.0`)
        if (preg_match('/^[^0-9]/', $token) === 1) {
            throw new InvalidVersionException(
                sprintf('Opérateur de contrainte invalide dans "%s" (jeton "%s").', $fullConstraint, $token),
            );
        }

        // Bare version or wildcard pattern (`1.2.3`, `1.2`, `1`, `1.x`, `1.2.x`, `1.2.*`)
        return self::expandBareOrWildcard($token, $fullConstraint);
    }

    /**
     * @return non-empty-list<array{op: string, target: array{major: int, minor: int, patch: int, prerelease: list<string>, build: ?string}, explicitPrerelease: bool}>
     */
    private static function expandCaret(string $expr, string $fullConstraint): array
    {
        $parts = self::parsePartialVersion($expr, $fullConstraint);
        $lower = self::tuple($parts['major'], $parts['minor'] ?? 0, $parts['patch'] ?? 0, $parts['prerelease']);

        if ($parts['major'] > 0) {
            $upper = self::tuple($parts['major'] + 1, 0, 0, ['0']);
        } elseif ($parts['minor'] === null) {
            // ^0 => >=0.0.0 <1.0.0-0
            $upper = self::tuple(1, 0, 0, ['0']);
        } elseif ($parts['minor'] > 0) {
            // ^0.5 or ^0.2.3 => >=0.x.y <0.(x+1).0-0
            $upper = self::tuple(0, $parts['minor'] + 1, 0, ['0']);
        } elseif ($parts['patch'] === null) {
            // ^0.0 => >=0.0.0 <0.1.0-0
            $upper = self::tuple(0, 1, 0, ['0']);
        } else {
            // ^0.0.3 => >=0.0.3 <0.0.4-0
            $upper = self::tuple(0, 0, $parts['patch'] + 1, ['0']);
        }

        return [
            ['op' => '>=', 'target' => $lower, 'explicitPrerelease' => $parts['prerelease'] !== []],
            ['op' => '<', 'target' => $upper, 'explicitPrerelease' => false],
        ];
    }

    /**
     * @return non-empty-list<array{op: string, target: array{major: int, minor: int, patch: int, prerelease: list<string>, build: ?string}, explicitPrerelease: bool}>
     */
    private static function expandTilde(string $expr, string $fullConstraint): array
    {
        $parts = self::parsePartialVersion($expr, $fullConstraint);
        $lower = self::tuple($parts['major'], $parts['minor'] ?? 0, $parts['patch'] ?? 0, $parts['prerelease']);

        if ($parts['minor'] === null) {
            // ~1 => >=1.0.0 <2.0.0-0
            $upper = self::tuple($parts['major'] + 1, 0, 0, ['0']);
        } else {
            // ~1.2 or ~1.2.3 => >=1.2.x <1.3.0-0
            $upper = self::tuple($parts['major'], $parts['minor'] + 1, 0, ['0']);
        }

        return [
            ['op' => '>=', 'target' => $lower, 'explicitPrerelease' => $parts['prerelease'] !== []],
            ['op' => '<', 'target' => $upper, 'explicitPrerelease' => false],
        ];
    }

    /**
     * @return non-empty-list<array{op: string, target: array{major: int, minor: int, patch: int, prerelease: list<string>, build: ?string}, explicitPrerelease: bool}>
     */
    private static function expandOperator(string $op, string $verPart, string $fullConstraint): array
    {
        $parts = self::parsePartialVersion($verPart, $fullConstraint);
        $hasPre = $parts['prerelease'] !== [];

        // Exact full version (or `!=`, `>`, `>=`, `<`, `<=`) with 3 parts
        if ($parts['minor'] !== null && $parts['patch'] !== null) {
            return [
                [
                    'op' => $op,
                    'target' => self::tuple($parts['major'], $parts['minor'], $parts['patch'], $parts['prerelease']),
                    'explicitPrerelease' => $hasPre,
                ],
            ];
        }

        // Partial version with operators: e.g. `>=8.2`, `<2.0`, `<=1.2`, `>1.2`, `=1.2`
        if ($op === '=') {
            return self::expandBareOrWildcard($verPart, $fullConstraint);
        }

        if ($op === '!=') {
            return [
                [
                    'op' => '!=',
                    'target' => self::tuple($parts['major'], $parts['minor'] ?? 0, 0),
                    'explicitPrerelease' => false,
                ],
            ];
        }

        if ($op === '>=' || $op === '<') {
            return [
                [
                    'op' => $op,
                    'target' => self::tuple($parts['major'], $parts['minor'] ?? 0, 0, $op === '<' ? ['0'] : []),
                    'explicitPrerelease' => false,
                ],
            ];
        }

        if ($op === '>') {
            $next = $parts['minor'] === null
                ? self::tuple($parts['major'] + 1, 0, 0)
                : self::tuple($parts['major'], $parts['minor'] + 1, 0);

            return [
                ['op' => '>=', 'target' => $next, 'explicitPrerelease' => false],
            ];
        }

        // `<=` on partial version: `<=1.2` => `<1.3.0-0`, `<=1` => `<2.0.0-0`
        $upper = $parts['minor'] === null
            ? self::tuple($parts['major'] + 1, 0, 0, ['0'])
            : self::tuple($parts['major'], $parts['minor'] + 1, 0, ['0']);

        return [
            ['op' => '<', 'target' => $upper, 'explicitPrerelease' => false],
        ];
    }

    /**
     * Expand bare versions (`1.2.3`, `1.2`, `1`) and wildcards (`1.x`, `1.*`, `1.2.x`, `1.2.*`).
     *
     * @return non-empty-list<array{op: string, target: array{major: int, minor: int, patch: int, prerelease: list<string>, build: ?string}, explicitPrerelease: bool}>
     */
    private static function expandBareOrWildcard(string $token, string $fullConstraint): array
    {
        // `1.2.x` or `1.2.*`
        if (preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.[*xX]$/', $token, $m) === 1) {
            $major = (int) $m[1];
            $minor = (int) $m[2];

            return [
                ['op' => '>=', 'target' => self::tuple($major, $minor, 0), 'explicitPrerelease' => false],
                ['op' => '<', 'target' => self::tuple($major, $minor + 1, 0, ['0']), 'explicitPrerelease' => false],
            ];
        }

        // `1.x`, `1.*`, `1.x.x`, `1.*.*`
        if (preg_match('/^(0|[1-9]\d*)\.[*xX](?:\.[*xX])?$/', $token, $m) === 1) {
            $major = (int) $m[1];

            return [
                ['op' => '>=', 'target' => self::tuple($major, 0, 0), 'explicitPrerelease' => false],
                ['op' => '<', 'target' => self::tuple($major + 1, 0, 0, ['0']), 'explicitPrerelease' => false],
            ];
        }

        $parts = self::parsePartialVersion($token, $fullConstraint);

        if ($parts['minor'] !== null && $parts['patch'] !== null) {
            return [
                [
                    'op' => '=',
                    'target' => self::tuple($parts['major'], $parts['minor'], $parts['patch'], $parts['prerelease']),
                    'explicitPrerelease' => $parts['prerelease'] !== [],
                ],
            ];
        }

        if ($parts['minor'] === null) {
            return [
                ['op' => '>=', 'target' => self::tuple($parts['major'], 0, 0), 'explicitPrerelease' => false],
                ['op' => '<', 'target' => self::tuple($parts['major'] + 1, 0, 0, ['0']), 'explicitPrerelease' => false],
            ];
        }

        return [
            ['op' => '>=', 'target' => self::tuple($parts['major'], $parts['minor'], 0), 'explicitPrerelease' => false],
            ['op' => '<', 'target' => self::tuple($parts['major'], $parts['minor'] + 1, 0, ['0']), 'explicitPrerelease' => false],
        ];
    }

    /**
     * Parse a full or partial version token (`1`, `1.2`, `1.2.3`, `1.2.3-rc.1+build`).
     *
     * @return array{major: int, minor: ?int, patch: ?int, prerelease: list<string>}
     */
    private static function parsePartialVersion(string $token, string $fullConstraint): array
    {
        if (preg_match(self::SEMVER_PATTERN, $token, $m) === 1) {
            return [
                'major' => (int) $m[1],
                'minor' => (int) $m[2],
                'patch' => (int) $m[3],
                'prerelease' => isset($m[4]) && $m[4] !== '' ? explode('.', $m[4]) : [],
            ];
        }

        if (preg_match('/^(0|[1-9]\d*)(?:\.(0|[1-9]\d*))?$/', $token, $m) === 1) {
            return [
                'major' => (int) $m[1],
                'minor' => isset($m[2]) ? (int) $m[2] : null,
                'patch' => null,
                'prerelease' => [],
            ];
        }

        throw new InvalidVersionException(
            sprintf('Expression de version invalide "%s" dans la contrainte "%s".', $token, $fullConstraint),
        );
    }

    /**
     * Evaluate a parsed candidate version against an AND list of primitive comparators.
     *
     * @param array{major: int, minor: int, patch: int, prerelease: list<string>, build: ?string} $candidate
     * @param list<array{op: string, target: array{major: int, minor: int, patch: int, prerelease: list<string>, build: ?string}, explicitPrerelease: bool}> $comparators
     */
    private static function matchesAndBranch(array $candidate, array $comparators): bool
    {
        foreach ($comparators as $comp) {
            $cmp = self::compareParsed($candidate, $comp['target']);
            $ok = match ($comp['op']) {
                '=' => $cmp === 0,
                '!=' => $cmp !== 0,
                '>' => $cmp > 0,
                '>=' => $cmp >= 0,
                '<' => $cmp < 0,
                '<=' => $cmp <= 0,
                default => false,
            };

            if (!$ok) {
                return false;
            }
        }

        // SemVer prerelease safety: a prerelease candidate only matches an AND branch
        // if at least one comparator explicitly specifies a prerelease on the same [major, minor, patch].
        if ($candidate['prerelease'] !== []) {
            $prereleaseAllowed = false;
            foreach ($comparators as $comp) {
                if (
                    $comp['explicitPrerelease']
                    && $comp['target']['major'] === $candidate['major']
                    && $comp['target']['minor'] === $candidate['minor']
                    && $comp['target']['patch'] === $candidate['patch']
                ) {
                    $prereleaseAllowed = true;
                    break;
                }
            }

            if (!$prereleaseAllowed) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{major: int, minor: int, patch: int, prerelease: list<string>, build: ?string} $a
     * @param array{major: int, minor: int, patch: int, prerelease: list<string>, build: ?string} $b
     */
    private static function compareParsed(array $a, array $b): int
    {
        $core = ($a['major'] <=> $b['major'])
            ?: ($a['minor'] <=> $b['minor'])
            ?: ($a['patch'] <=> $b['patch']);

        if ($core !== 0) {
            return $core;
        }

        $aPre = $a['prerelease'];
        $bPre = $b['prerelease'];

        if ($aPre === [] && $bPre === []) {
            return 0;
        }

        // Stable release always has higher precedence than any pre-release.
        if ($aPre === []) {
            return 1;
        }
        if ($bPre === []) {
            return -1;
        }

        $len = min(count($aPre), count($bPre));
        for ($i = 0; $i < $len; $i++) {
            if ($aPre[$i] === $bPre[$i]) {
                continue;
            }

            $aNum = ctype_digit($aPre[$i]);
            $bNum = ctype_digit($bPre[$i]);

            if ($aNum && $bNum) {
                return (int) $aPre[$i] <=> (int) $bPre[$i];
            }

            // Numeric identifiers always have lower precedence than non-numeric identifiers.
            if ($aNum) {
                return -1;
            }
            if ($bNum) {
                return 1;
            }

            return strcmp($aPre[$i], $bPre[$i]) <=> 0;
        }

        return count($aPre) <=> count($bPre);
    }

    /**
     * @param list<string> $prerelease
     * @return array{major: int, minor: int, patch: int, prerelease: list<string>, build: ?string}
     */
    private static function tuple(int $major, int $minor, int $patch, array $prerelease = []): array
    {
        return [
            'major' => $major,
            'minor' => $minor,
            'patch' => $patch,
            'prerelease' => $prerelease,
            'build' => null,
        ];
    }
}
