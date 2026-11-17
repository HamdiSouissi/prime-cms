<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prime\Scheduler\CronExpression;

/**
 * Cron semantics the scheduler is built on: the Vixie day-of-month/day-of-week rule, the catch-up
 * lookup of the last matching minute, and the names and aliases operators actually type.
 */
final class CronExpressionTest extends TestCase
{
    public function testAliasesBecomeTheirFiveFieldForm(): void
    {
        $daily = CronExpression::parse('@daily');

        $this->assertSame('@daily', $daily->expression(), 'what the operator wrote is what the screen shows back');
        $this->assertTrue($daily->matches(self::at('2026-11-15 00:00')));
        $this->assertFalse($daily->matches(self::at('2026-11-15 00:01')));
    }

    public function testFieldsRangesListsAndSteps(): void
    {
        $expression = CronExpression::parse('*/15 9-17 * * 1-5');

        $this->assertFalse($expression->matches(self::at('2026-11-14 09:00')), 'Saturday is outside 1-5');
        $this->assertTrue($expression->matches(self::at('2026-11-16 09:00')), 'Monday 09:00');
        $this->assertTrue($expression->matches(self::at('2026-11-16 17:45')), 'Monday 17:45 is inside 9-17');
        $this->assertFalse($expression->matches(self::at('2026-11-16 18:00')), '18:00 is past the hour range');
        $this->assertFalse($expression->matches(self::at('2026-11-16 09:07')), ':07 is not a quarter hour');

        $list = CronExpression::parse('30 6 1,15 * *');
        $this->assertTrue($list->matches(self::at('2026-11-15 06:30')));
        $this->assertFalse($list->matches(self::at('2026-11-14 06:30')));
    }

    public function testDaysAreAcceptedByNameAndWeekdaysByNumberIncludingSeven(): void
    {
        $this->assertTrue(CronExpression::parse('0 0 * * 7')->matches(self::at('2026-11-15 00:00')), '7 is Sunday');
        $this->assertTrue(CronExpression::parse('0 0 * * sun')->matches(self::at('2026-11-15 00:00')));
        $this->assertTrue(CronExpression::parse('0 0 25 dec *')->matches(self::at('2026-12-25 00:00')));
        $this->assertFalse(CronExpression::parse('0 0 25 dec *')->matches(self::at('2026-12-24 00:00')));
    }

    public function testARestrictedDayOfMonthAndWeekdayAreCombinedWithOr(): void
    {
        // Vixie cron: "the 13th, or any Friday" — not the intersection.
        $expression = CronExpression::parse('0 0 13 * 5');

        $this->assertTrue($expression->matches(self::at('2026-11-13 00:00')), 'Friday the 13th matches twice over');
        $this->assertTrue($expression->matches(self::at('2026-11-20 00:00')), 'a Friday matches');
        $this->assertTrue($expression->matches(self::at('2026-12-13 00:00')), 'the 13th matches');
        $this->assertFalse($expression->matches(self::at('2026-11-21 00:00')), 'Saturday the 21st matches neither');

        // With the day-of-month left free, the weekday alone constrains (an AND, not an OR).
        $mondays = CronExpression::parse('0 0 * * 1');
        $this->assertTrue($mondays->matches(self::at('2026-11-16 00:00')));
        $this->assertFalse($mondays->matches(self::at('2026-11-17 00:00')));
    }

    public function testNextRunSkipsToTheFollowingMatch(): void
    {
        $hourly = CronExpression::parse('0 * * * *');
        $this->assertSame('2026-11-15 09:00', (string) $hourly->nextRun(self::at('2026-11-15 08:59'))->format('Y-m-d H:i'));

        $monthly = CronExpression::parse('@monthly');
        $this->assertSame('2026-12-01 00:00', (string) $monthly->nextRun(self::at('2026-11-15 08:00'))->format('Y-m-d H:i'));

        $leap = CronExpression::parse('0 0 29 2 *');
        $this->assertSame('2028-02-29 00:00', (string) $leap->nextRun(self::at('2026-11-15 08:00'))->format('Y-m-d H:i'));
    }

    public function testImpossibleDatesHaveNoNextRun(): void
    {
        $this->assertNull(CronExpression::parse('0 0 31 2 *')->nextRun(self::at('2026-11-15 08:00')));
    }

    public function testPreviousRunFindsTheTickThatIsOwed(): void
    {
        $daily = CronExpression::parse('30 3 * * *');

        $this->assertSame(
            '2026-11-15 03:30',
            (string) $daily->previousRun(self::at('2026-11-15 03:30'))->format('Y-m-d H:i'),
            'the matching minute itself counts',
        );
        $this->assertSame(
            '2026-11-15 03:30',
            (string) $daily->previousRun(self::at('2026-11-15 08:00'))->format('Y-m-d H:i'),
        );
        $this->assertSame(
            '2026-11-14 03:30',
            (string) $daily->previousRun(self::at('2026-11-15 03:29'))->format('Y-m-d H:i'),
        );
        $this->assertNull(CronExpression::parse('0 0 31 2 *')->previousRun(self::at('2026-11-15 08:00'), 400));
    }

    public function testRangesMayWrapAroundMidnight(): void
    {
        $expression = CronExpression::parse('0 22-2 * * *');

        foreach (['22:00', '23:00', '00:00', '01:00', '02:00'] as $time) {
            $this->assertTrue($expression->matches(self::at('2026-11-15 ' . $time)), $time);
        }

        $this->assertFalse($expression->matches(self::at('2026-11-15 03:00')));
        $this->assertFalse($expression->matches(self::at('2026-11-15 21:00')));
    }

    public function testMatchingIsDoneInUtcWhateverTheSiteTimezone(): void
    {
        $parisian = new DateTimeImmutable('2026-11-15 03:00', new DateTimeZone('Europe/Paris'));

        $this->assertFalse(CronExpression::parse('0 3 * * *')->matches($parisian), '03:00 in Paris is 02:00 UTC');
        $this->assertTrue(CronExpression::parse('0 2 * * *')->matches($parisian));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'too few fields' => ['0 0 * *', 'Cinq champs attendus'],
            'too many fields' => ['0 0 * * * 2026', 'Cinq champs attendus'],
            'minute out of range' => ['60 * * * *', 'hors bornes'],
            'unknown name' => ['* * * * tuesday', 'hors service'],
            'bad step' => ['*/0 * * * *', 'Pas invalide'],
            'empty item' => ['1,,2 * * * *', 'invalide'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidProvider')]
    public function testInvalidExpressionsAreRejectedWithAFrenchMessage(string $expression, string $needle): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($needle, '/') . '/u');

        CronExpression::parse($expression);
    }

    private static function at(string $minuteUtc): DateTimeImmutable
    {
        return new DateTimeImmutable($minuteUtc, new DateTimeZone('UTC'));
    }
}
