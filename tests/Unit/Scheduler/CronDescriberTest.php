<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Scheduler;

use PHPUnit\Framework\TestCase;
use Prime\Scheduler\CronDescriber;

final class CronDescriberTest extends TestCase
{
    public function testCommonShapesAreSpelledOutInPlainFrench(): void
    {
        $this->assertSame('Toutes les minutes', CronDescriber::describe('* * * * *'));
        $this->assertSame('Toutes les 5 minutes', CronDescriber::describe('*/5 * * * *'));
        $this->assertSame('Toutes les heures, à la minute 15', CronDescriber::describe('15 * * * *'));
        $this->assertSame('Toutes les 6 heures', CronDescriber::describe('0 */6 * * *'));
        $this->assertSame('Chaque jour à 03:30', CronDescriber::describe('30 3 * * *'));
        $this->assertSame('Chaque lundi à 04:00', CronDescriber::describe('0 4 * * 1'));
        $this->assertSame('Le 1 de chaque mois à 00:00', CronDescriber::describe('0 0 1 * *'));
    }

    public function testUnusualExpressionsFallBackToTheExpressionItself(): void
    {
        $this->assertSame('0 0 31 2 *', CronDescriber::describe('0 0 31 2 *'));
        $this->assertSame('0,30 9-17 * * 1-5', CronDescriber::describe('0,30 9-17 * * 1-5'));
        $this->assertSame('not cron', CronDescriber::describe('not cron'));
    }
}
