<?php

declare(strict_types=1);

namespace App\Tenant\Attendance;

/**
 * One local day's attendance, computed from UTC instants.
 * Display strings are not an input.
 */
final class AttendanceDaySummary
{
    /**
     * @param list<array{eventType: string, occurredAt: \DateTimeImmutable}> $events chronological UTC events for the open session overlapping the day
     *
     * @return array{
     *     state: string,
     *     clockIn: \DateTimeImmutable|null,
     *     clockOut: \DateTimeImmutable|null,
     *     lunches: list<array{start: \DateTimeImmutable, end: \DateTimeImmutable|null, seconds: int}>,
     *     workedSeconds: int,
     *     lunchSeconds: int,
     *     missingClockOut: bool
     * }
     */
    public static function summarize(array $events, \DateTimeImmutable $dayStart, \DateTimeImmutable $dayEnd, \DateTimeImmutable $now): array
    {
        $state = AttendanceState::Off;
        $openingClockIn = null;
        $firstClockInInDay = null;
        $lastClockOutInDay = null;
        $openWork = null;
        $openLunch = null;
        $worked = 0;
        /** @var list<array{start: \DateTimeImmutable, end: \DateTimeImmutable|null, seconds: int}> $lunches */
        $lunches = [];

        foreach ($events as $event) {
            $action = AttendanceAction::tryFrom($event['eventType']);
            if (!$action instanceof AttendanceAction) {
                continue;
            }
            try {
                $state = $action->next($state);
            } catch (AttendanceRejectedException) {
                continue;
            }
            $at = $event['occurredAt'];
            if ($action === AttendanceAction::ClockIn) {
                $openingClockIn ??= $at;
                if ($at >= $dayStart && $at < $dayEnd && !$firstClockInInDay instanceof \DateTimeImmutable) {
                    $firstClockInInDay = $at;
                }
                $openWork = $at;
            } elseif ($action === AttendanceAction::LunchStart) {
                if ($openWork instanceof \DateTimeImmutable) {
                    $worked += self::clipped($openWork, $at, $dayStart, $dayEnd);
                    $openWork = null;
                }
                $openLunch = $at;
            } elseif ($action === AttendanceAction::LunchEnd) {
                if ($openLunch instanceof \DateTimeImmutable) {
                    $seconds = self::clipped($openLunch, $at, $dayStart, $dayEnd);
                    if ($seconds > 0) {
                        $lunches[] = ['start' => $openLunch, 'end' => $at, 'seconds' => $seconds];
                    }
                    $openLunch = null;
                }
                $openWork = $at;
            } elseif ($openWork instanceof \DateTimeImmutable) {
                $worked += self::clipped($openWork, $at, $dayStart, $dayEnd);
                $openWork = null;
                if ($at >= $dayStart && $at < $dayEnd) {
                    $lastClockOutInDay = $at;
                }
            } elseif ($at >= $dayStart && $at < $dayEnd) {
                $lastClockOutInDay = $at;
            }
        }

        $close = $now < $dayEnd ? $now : $dayEnd;
        if ($openWork instanceof \DateTimeImmutable && $close > $dayStart) {
            $worked += self::clipped($openWork, $close, $dayStart, $dayEnd);
        }
        if ($openLunch instanceof \DateTimeImmutable && $close > $dayStart) {
            $seconds = self::clipped($openLunch, $close, $dayStart, $dayEnd);
            if ($seconds > 0) {
                $lunches[] = ['start' => $openLunch, 'end' => null, 'seconds' => $seconds];
            }
        }

        $lunchSeconds = 0;
        foreach ($lunches as $lunch) {
            $lunchSeconds += $lunch['seconds'];
        }
        $missing = $state !== AttendanceState::Off && (
            ($openingClockIn instanceof \DateTimeImmutable && $openingClockIn < $dayStart) || $now >= $dayEnd
        );

        return [
            'state' => $state->value,
            'clockIn' => $firstClockInInDay ?? $openingClockIn,
            'clockOut' => $state === AttendanceState::Off ? $lastClockOutInDay : null,
            'lunches' => $lunches,
            'workedSeconds' => $worked,
            'lunchSeconds' => $lunchSeconds,
            'missingClockOut' => $missing,
        ];
    }

    private static function clipped(\DateTimeImmutable $start, \DateTimeImmutable $end, \DateTimeImmutable $windowStart, \DateTimeImmutable $windowEnd): int
    {
        $from = $start > $windowStart ? $start : $windowStart;
        $to = $end < $windowEnd ? $end : $windowEnd;
        if ($to <= $from) {
            return 0;
        }

        return AttendanceDuration::seconds($from, $to);
    }
}
