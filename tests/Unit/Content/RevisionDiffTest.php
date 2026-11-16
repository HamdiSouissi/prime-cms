<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Content;

use PHPUnit\Framework\TestCase;
use Prime\Content\RevisionDiff;

final class RevisionDiffTest extends TestCase
{
    public function testComputesAddedRemovedAndEqualLines(): void
    {
        $old = "Ligne 1\nLigne 2\nLigne 3\nLigne 4";
        $new = "Ligne 1\nLigne 2 modifiée\nLigne 3\nLigne 4\nLigne 5";

        $diff = RevisionDiff::diffLines($old, $new);
        $stats = RevisionDiff::stats($diff);

        $this->assertSame(2, $stats['added']);
        $this->assertSame(1, $stats['removed']);
        $this->assertSame(3, $stats['unchanged']);

        $ops = array_map(static fn (array $row): string => $row['op'] . ':' . $row['text'], $diff);
        $this->assertSame([
            'equal:Ligne 1',
            'removed:Ligne 2',
            'added:Ligne 2 modifiée',
            'equal:Ligne 3',
            'equal:Ligne 4',
            'added:Ligne 5',
        ], $ops);
    }

    public function testIdenticalAndEmptyInputs(): void
    {
        $this->assertSame([], RevisionDiff::diffLines('', ''));

        $same = RevisionDiff::diffLines("A\nB", "A\nB");
        $this->assertSame(['added' => 0, 'removed' => 0, 'unchanged' => 2], RevisionDiff::stats($same));
    }
}
