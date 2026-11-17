<?php

declare(strict_types=1);

namespace Prime\Scheduler;

/**
 * Plain-language frequency of a cron expression (« Toutes les 5 minutes », « Chaque jour à 03:30 »).
 *
 * Only the shapes people actually write are spelled out; anything else falls back to the
 * expression itself, which the screen shows next to it anyway.
 */
final class CronDescriber
{
    public static function describe(string $expression): string
    {
        $parts = preg_split('/\s+/', trim($expression)) ?: [];
        if (count($parts) !== 5) {
            return $expression;
        }

        [$minute, $hour, $day, $month, $weekday] = $parts;

        if ($month !== '*') {
            return $expression;
        }

        if ($hour === '*' && $day === '*' && $weekday === '*') {
            if ($minute === '*') {
                return t('core.scheduler.every_minute');
            }
            if (preg_match('/^\*\/(\d{1,2})$/', $minute, $m) === 1) {
                return t('core.scheduler.every_n_minutes', ['count' => (int) $m[1]]);
            }
            if (ctype_digit($minute)) {
                return t('core.scheduler.hourly_at', ['minute' => sprintf('%02d', (int) $minute)]);
            }

            return $expression;
        }

        if ($minute === '0' && $day === '*' && $weekday === '*' && preg_match('/^\*\/(\d{1,2})$/', $hour, $m) === 1) {
            return t('core.scheduler.every_n_hours', ['count' => (int) $m[1]]);
        }

        if (!ctype_digit($minute) || !ctype_digit($hour)) {
            return $expression;
        }

        $time = sprintf('%02d:%02d', (int) $hour, (int) $minute);

        if ($day === '*' && $weekday === '*') {
            return t('core.scheduler.daily_at', ['time' => $time]);
        }

        if ($day === '*' && preg_match('/^[0-7]$/', $weekday) === 1) {
            $names = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

            return t('core.scheduler.weekly_at', ['day' => t('core.scheduler.days.' . $names[(int) $weekday]), 'time' => $time]);
        }

        if ($weekday === '*' && ctype_digit($day)) {
            return t('core.scheduler.monthly_at', ['day' => (int) $day, 'time' => $time]);
        }

        return $expression;
    }
}
