<?php

declare(strict_types=1);

namespace Prime\Content;

/**
 * Pure-PHP line-by-line LCS diff calculator for content revision comparisons.
 */
final class RevisionDiff
{
    public const OP_EQUAL = 'equal';
    public const OP_ADDED = 'added';
    public const OP_REMOVED = 'removed';

    /**
     * Compute a line-based diff between `$oldText` and `$newText`.
     *
     * @return list<array{op: 'equal'|'added'|'removed', text: string}>
     */
    public static function diffLines(string $oldText, string $newText): array
    {
        $oldLines = self::splitLines($oldText);
        $newLines = self::splitLines($newText);

        $n = count($oldLines);
        $m = count($newLines);

        if ($n === 0 && $m === 0) {
            return [];
        }

        // Guard memory for very large documents by trimming common prefix/suffix first
        $prefix = 0;
        while ($prefix < $n && $prefix < $m && $oldLines[$prefix] === $newLines[$prefix]) {
            $prefix++;
        }

        $suffix = 0;
        while (
            $suffix < ($n - $prefix)
            && $suffix < ($m - $prefix)
            && $oldLines[$n - 1 - $suffix] === $newLines[$m - 1 - $suffix]
        ) {
            $suffix++;
        }

        $result = [];
        for ($i = 0; $i < $prefix; $i++) {
            $result[] = ['op' => self::OP_EQUAL, 'text' => $oldLines[$i]];
        }

        $midOld = array_slice($oldLines, $prefix, $n - $prefix - $suffix);
        $midNew = array_slice($newLines, $prefix, $m - $prefix - $suffix);

        $om = count($midOld);
        $nm = count($midNew);

        if ($om * $nm <= 250_000) {
            foreach (self::lcsDiff($midOld, $midNew) as $entry) {
                $result[] = $entry;
            }
        } else {
            // Fallback for massive inputs to stay O(n + m)
            foreach ($midOld as $line) {
                $result[] = ['op' => self::OP_REMOVED, 'text' => $line];
            }
            foreach ($midNew as $line) {
                $result[] = ['op' => self::OP_ADDED, 'text' => $line];
            }
        }

        for ($i = $n - $suffix; $i < $n; $i++) {
            $result[] = ['op' => self::OP_EQUAL, 'text' => $oldLines[$i]];
        }

        return $result;
    }

    /**
     * Summarize how many lines were added and removed.
     *
     * @param list<array{op: 'equal'|'added'|'removed', text: string}> $diff
     * @return array{added: int, removed: int, unchanged: int}
     */
    public static function stats(array $diff): array
    {
        $added = 0;
        $removed = 0;
        $unchanged = 0;

        foreach ($diff as $row) {
            if ($row['op'] === self::OP_ADDED) {
                $added++;
            } elseif ($row['op'] === self::OP_REMOVED) {
                $removed++;
            } else {
                $unchanged++;
            }
        }

        return [
            'added' => $added,
            'removed' => $removed,
            'unchanged' => $unchanged,
        ];
    }

    /**
     * @return list<string>
     */
    private static function splitLines(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $text);

        return explode("\n", $normalized);
    }

    /**
     * @param list<string> $old
     * @param list<string> $new
     * @return list<array{op: 'equal'|'added'|'removed', text: string}>
     */
    private static function lcsDiff(array $old, array $new): array
    {
        $n = count($old);
        $m = count($new);

        /** @var array<int, array<int, int>> $dp */
        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                if ($old[$i] === $new[$j]) {
                    $dp[$i][$j] = $dp[$i + 1][$j + 1] + 1;
                } else {
                    $dp[$i][$j] = max($dp[$i + 1][$j], $dp[$i][$j + 1]);
                }
            }
        }

        $ops = [];
        $i = 0;
        $j = 0;

        while ($i < $n && $j < $m) {
            if ($old[$i] === $new[$j]) {
                $ops[] = ['op' => self::OP_EQUAL, 'text' => $old[$i]];
                $i++;
                $j++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $ops[] = ['op' => self::OP_REMOVED, 'text' => $old[$i]];
                $i++;
            } else {
                $ops[] = ['op' => self::OP_ADDED, 'text' => $new[$j]];
                $j++;
            }
        }

        while ($i < $n) {
            $ops[] = ['op' => self::OP_REMOVED, 'text' => $old[$i]];
            $i++;
        }

        while ($j < $m) {
            $ops[] = ['op' => self::OP_ADDED, 'text' => $new[$j]];
            $j++;
        }

        return $ops;
    }
}
