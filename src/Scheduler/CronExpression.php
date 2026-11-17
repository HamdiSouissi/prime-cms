<?php

declare(strict_types=1);

namespace Prime\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The five-field cron expression of Vixie cron (`minute hour day-of-month month day-of-week`), with
 * the usual `*` `,` `-` `/` operators, month and weekday names, and the `@daily` style aliases.
 *
 * Time is UTC everywhere, which is the timezone `pc_entries.published_at` and `pc_jobs.available_at`
 * use: a schedule written as `0 3 * * *` fires at 03:00 UTC regardless of the site locale.
 */
final class CronExpression
{
    public const MAX_FORWARD_DAYS = 800;

    private const ALIASES = [
        '@yearly' => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly' => '0 0 1 * *',
        '@weekly' => '0 0 * * 0',
        '@daily' => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly' => '0 * * * *',
        '@minutely' => '* * * * *',
    ];

    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    private const WEEKDAYS = [
        'sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6,
    ];

    /**
     * @param array<int, true> $minutes
     * @param array<int, true> $hours
     * @param array<int, true> $days
     * @param array<int, true> $months
     * @param array<int, true> $weekdays
     */
    private function __construct(
        private readonly string $expression,
        private readonly array $minutes,
        private readonly array $hours,
        private readonly array $days,
        private readonly array $months,
        private readonly array $weekdays,
        private readonly bool $daysRestricted,
        private readonly bool $weekdaysRestricted,
    ) {
    }

    public static function parse(string $expression): self
    {
        $normalized = trim($expression);
        $alias = strtolower($normalized);
        $canonical = $normalized;

        if (isset(self::ALIASES[$alias])) {
            // Keep the alias as the canonical text: it is what an operator wrote, and what the admin
            // screen shows back.
            $canonical = $alias;
            $normalized = self::ALIASES[$alias];
        }

        $normalized = str_replace("\t", ' ', $normalized);
        $fields = preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($fields) !== 5) {
            throw new InvalidArgumentException(sprintf(
                'Expression cron invalide : « %s ». Cinq champs attendus (minute heure jour mois jour-de-semaine).',
                $expression,
            ));
        }

        $minutes = self::parseField($fields[0], 0, 59, $expression, []);
        $hours = self::parseField($fields[1], 0, 23, $expression, []);
        $days = self::parseField($fields[2], 1, 31, $expression, []);
        $months = self::parseField($fields[3], 1, 12, $expression, self::MONTHS);
        $weekdays = self::parseField($fields[4], 0, 6, $expression, self::WEEKDAYS);

        return new self(
            expression: $canonical,
            minutes: $minutes,
            hours: $hours,
            days: $days,
            months: $months,
            weekdays: $weekdays,
            daysRestricted: trim($fields[2]) !== '*' && trim($fields[2]) !== '?',
            weekdaysRestricted: trim($fields[4]) !== '*' && trim($fields[4]) !== '?',
        );
    }

    public function expression(): string
    {
        return $this->expression;
    }

    public function matches(DateTimeImmutable $at): bool
    {
        $at = $at->setTimezone(new DateTimeZone('UTC'));

        if (!isset($this->months[(int) $at->format('n')])) {
            return false;
        }

        if (!isset($this->minutes[(int) $at->format('i')]) || !isset($this->hours[(int) $at->format('G')])) {
            return false;
        }

        return $this->dayMatches($at);
    }

    /**
     * The first matching minute strictly after `$after`, or null when none exists within
     * `MAX_FORWARD_DAYS` (an impossible date such as `0 0 30 2 *`).
     */
    public function nextRun(?DateTimeImmutable $after = null): ?DateTimeImmutable
    {
        $cursor = $after ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $cursor = $cursor->setTimezone(new DateTimeZone('UTC'))
            ->setTime((int) $cursor->format('G'), (int) $cursor->format('i'), 0)
            ->modify('+1 minute');

        $limit = self::MAX_FORWARD_DAYS * 24 * 60;

        for ($step = 0; $step < $limit; ++$step) {
            if (!isset($this->months[(int) $cursor->format('n')])) {
                $cursor = $cursor->modify('first day of next month')->setTime(0, 0);
                continue;
            }

            if (!$this->dayMatches($cursor)) {
                $cursor = $cursor->setTime(0, 0)->modify('+1 day');
                continue;
            }

            if (!isset($this->hours[(int) $cursor->format('G')])) {
                $cursor = $cursor->setTime((int) $cursor->format('G') + 1, 0);
                continue;
            }

            if (!isset($this->minutes[(int) $cursor->format('i')])) {
                $cursor = $cursor->setTime((int) $cursor->format('G'), (int) $cursor->format('i') + 1);
                continue;
            }

            return $cursor;
        }

        return null;
    }

    /**
     * The most recent matching minute at or before `$before` — the tick a due check looks for, which
     * is what keeps a schedule from being missed when the runner was down for a while.
     */
    public function previousRun(?DateTimeImmutable $before = null, int $maxDays = self::MAX_FORWARD_DAYS): ?DateTimeImmutable
    {
        $reference = ($before ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        $startMinute = (int) $reference->format('G') * 60 + (int) $reference->format('i');
        $minutes = $this->orderedMinutesDesc();
        $hours = $this->orderedHoursDesc();

        for ($ago = 0; $ago <= max(0, $maxDays); ++$ago) {
            $day = $ago === 0 ? $reference : $reference->setTime(0, 0)->modify("-$ago day");
            $day = $day->setTime(0, 0);

            if (!isset($this->months[(int) $day->format('n')]) || !$this->dayMatches($day)) {
                continue;
            }

            $ceilingHour = $ago === 0 ? intdiv($startMinute, 60) : 23;
            $ceilingMinute = $ago === 0 ? $startMinute % 60 : 59;

            foreach ($hours as $hour) {
                if ($hour > $ceilingHour) {
                    continue;
                }

                foreach ($minutes as $minute) {
                    if ($hour === $ceilingHour && $minute > $ceilingMinute) {
                        continue;
                    }

                    return $day->setTime($hour, $minute);
                }
            }
        }

        return null;
    }

    /**
     * Day-of-month and day-of-week follow Vixie cron: when both are restricted, either one matches.
     */
    private function dayMatches(DateTimeImmutable $at): bool
    {
        $dayOfMonth = isset($this->days[(int) $at->format('j')]);
        $weekday = isset($this->weekdays[(int) $at->format('w')]);

        if ($this->daysRestricted && $this->weekdaysRestricted) {
            return $dayOfMonth || $weekday;
        }

        return $dayOfMonth && $weekday;
    }

    /**
     * @return list<int>
     */
    private function orderedMinutesDesc(): array
    {
        return $this->descending($this->minutes);
    }

    /**
     * @return list<int>
     */
    private function orderedHoursDesc(): array
    {
        return $this->descending($this->hours);
    }

    /**
     * @param array<int, true> $set
     *
     * @return list<int>
     */
    private function descending(array $set): array
    {
        $values = array_map('intval', array_keys($set));
        rsort($values);

        return $values;
    }

    /**
     * @param array<string, int> $names
     *
     * @return array<int, true>
     */
    private static function parseField(string $field, int $min, int $max, string $expression, array $names): array
    {
        $value = trim($field);

        if ($value === '*' || $value === '?') {
            return self::range($min, $max, 1);
        }

        $set = [];

        foreach (explode(',', $value) as $item) {
            $item = trim($item);
            if ($item === '') {
                throw new InvalidArgumentException(sprintf('Expression cron invalide : « %s ».', $expression));
            }

            $step = 1;
            $span = $item;

            if (str_contains($item, '/')) {
                [$span, $stepText] = explode('/', $item, 2);
                if (preg_match('/^\d+$/', $stepText) !== 1 || (int) $stepText < 1) {
                    throw new InvalidArgumentException(sprintf(
                        'Pas invalide dans « %s » (expression « %s »).',
                        $item,
                        $expression,
                    ));
                }

                $step = (int) $stepText;
            }

            $span = trim($span);

            if ($span === '*' || $span === '?') {
                $set += self::range($min, $max, $step);
                continue;
            }

            [$start, $end] = self::parseBounds($span, $min, $max, $names, $expression);

            if ($end < $start) {
                // `22-2` wraps midnight: expand it into the two halves.
                $set += self::range($start, $max, $step);
                $set += self::range($min, $end, $step);
                continue;
            }

            $set += self::range($start, $end, $step);
        }

        if ($set === []) {
            throw new InvalidArgumentException(sprintf('Expression cron invalide : « %s ».', $expression));
        }

        return $set;
    }

    /**
     * @param array<string, int> $names
     *
     * @return array{0: int, 1: int}
     */
    private static function parseBounds(string $span, int $min, int $max, array $names, string $expression): array
    {
        if (!str_contains($span, '-')) {
            $start = self::parseValue($span, $min, $max, $names, $expression);

            return [$start, $start];
        }

        [$from, $to] = explode('-', $span, 2);

        return [
            self::parseValue($from, $min, $max, $names, $expression),
            self::parseValue($to, $min, $max, $names, $expression),
        ];
    }

    /**
     * @param array<string, int> $names
     */
    private static function parseValue(string $token, int $min, int $max, array $names, string $expression): int
    {
        $token = strtolower(trim($token));

        if ($token === '') {
            throw new InvalidArgumentException(sprintf('Expression cron invalide : « %s ».', $expression));
        }

        if (isset($names[$token])) {
            return $names[$token];
        }

        if (preg_match('/^\d+$/', $token) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Valeur « %s » hors service dans l’expression « %s ».',
                $token,
                $expression,
            ));
        }

        $value = (int) $token;

        // Vixie cron accepts 7 for Sunday.
        if ($max === 6 && $value === 7) {
            return 0;
        }

        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException(sprintf(
                'Valeur %d hors bornes (%d-%d) dans l’expression « %s ».',
                $value,
                $min,
                $max,
                $expression,
            ));
        }

        return $value;
    }

    /**
     * @return array<int, true>
     */
    private static function range(int $from, int $to, int $step): array
    {
        $set = [];

        for ($value = $from; $value <= $to; $value += max(1, $step)) {
            $set[$value] = true;
        }

        return $set;
    }
}
