<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Shared\Time\UtcTime;
use App\Tenant\Attendance\AttendanceDaySummary;
use App\Tenant\Attendance\AttendanceDuration;
use PHPUnit\Framework\TestCase;

final class AttendanceDaySummaryTest extends TestCase
{
    public function testWorkedTimeUsesTimestampsAndExcludesLunch(): void
    {
        $day = UtcTime::localDay('2026-10-07', 'Europe/Stockholm');
        $clockIn = new \DateTimeImmutable('2026-10-07 06:42:15', new \DateTimeZone('UTC'));
        $lunchStart = new \DateTimeImmutable('2026-10-07 07:00:00', new \DateTimeZone('UTC'));
        $lunchEnd = new \DateTimeImmutable('2026-10-07 07:15:00', new \DateTimeZone('UTC'));
        $clockOut = new \DateTimeImmutable('2026-10-07 07:42:15', new \DateTimeZone('UTC'));
        $summary = AttendanceDaySummary::summarize([
            ['eventType' => 'clock_in', 'occurredAt' => $clockIn],
            ['eventType' => 'lunch_start', 'occurredAt' => $lunchStart],
            ['eventType' => 'lunch_end', 'occurredAt' => $lunchEnd],
            ['eventType' => 'clock_out', 'occurredAt' => $clockOut],
        ], $day['start'], $day['end'], $clockOut);

        self::assertSame('off', $summary['state']);
        self::assertSame($clockIn, $summary['clockIn']);
        self::assertSame($clockOut, $summary['clockOut']);
        self::assertFalse($summary['missingClockOut']);
        self::assertSame(900, $summary['lunchSeconds']);
        self::assertSame(2700, $summary['workedSeconds']);
        self::assertSame(
            AttendanceDuration::seconds($clockIn, $clockOut) - AttendanceDuration::seconds($lunchStart, $lunchEnd),
            $summary['workedSeconds'],
        );
        self::assertSame('2026-10-07 08:42:15', UtcTime::present($clockIn, 'Europe/Stockholm'));
        self::assertSame('2026-10-07 09:42:15', UtcTime::present($clockOut, 'Europe/Stockholm'));
    }

    public function testDaylightSavingDisplayStringsAreNotTheDuration(): void
    {
        $day = UtcTime::localDay('2026-03-29', 'Europe/Stockholm');
        $clockIn = new \DateTimeImmutable('2026-03-29 00:30:00', new \DateTimeZone('UTC'));
        $clockOut = new \DateTimeImmutable('2026-03-29 01:30:00', new \DateTimeZone('UTC'));
        $summary = AttendanceDaySummary::summarize([
            ['eventType' => 'clock_in', 'occurredAt' => $clockIn],
            ['eventType' => 'clock_out', 'occurredAt' => $clockOut],
        ], $day['start'], $day['end'], $clockOut);

        $localStart = UtcTime::present($clockIn, 'Europe/Stockholm');
        $localEnd = UtcTime::present($clockOut, 'Europe/Stockholm');
        self::assertSame('2026-03-29 01:30:00', $localStart);
        self::assertSame('2026-03-29 03:30:00', $localEnd);
        $naiveStart = new \DateTimeImmutable($localStart, new \DateTimeZone('UTC'));
        $naiveEnd = new \DateTimeImmutable($localEnd, new \DateTimeZone('UTC'));
        self::assertSame(7200, AttendanceDuration::seconds($naiveStart, $naiveEnd));
        self::assertSame(3600, $summary['workedSeconds']);
        self::assertSame(AttendanceDuration::seconds($clockIn, $clockOut), $summary['workedSeconds']);
    }

    public function testAnOpenSessionFromThePreviousLocalDayIsMissing(): void
    {
        $day = UtcTime::localDay('2026-10-07', 'Europe/Stockholm');
        $clockIn = new \DateTimeImmutable('2026-10-06 20:00:00', new \DateTimeZone('UTC'));
        $now = new \DateTimeImmutable('2026-10-07 10:00:00', new \DateTimeZone('UTC'));
        $summary = AttendanceDaySummary::summarize([
            ['eventType' => 'clock_in', 'occurredAt' => $clockIn],
        ], $day['start'], $day['end'], $now);

        self::assertSame('working', $summary['state']);
        self::assertTrue($summary['missingClockOut']);
        self::assertNull($summary['clockOut']);
        self::assertSame($clockIn, $summary['clockIn']);
        self::assertSame(AttendanceDuration::seconds($day['start'], $now), $summary['workedSeconds']);
    }

    public function testAClockInDuringTheOpenDayIsNotMissing(): void
    {
        $day = UtcTime::localDay('2026-10-07', 'Europe/Stockholm');
        $clockIn = new \DateTimeImmutable('2026-10-07 06:42:15', new \DateTimeZone('UTC'));
        $now = new \DateTimeImmutable('2026-10-07 08:42:15', new \DateTimeZone('UTC'));
        $summary = AttendanceDaySummary::summarize([
            ['eventType' => 'clock_in', 'occurredAt' => $clockIn],
        ], $day['start'], $day['end'], $now);

        self::assertSame('working', $summary['state']);
        self::assertFalse($summary['missingClockOut']);
        self::assertSame(7200, $summary['workedSeconds']);
        self::assertSame(AttendanceDuration::seconds($clockIn, $now), $summary['workedSeconds']);
    }

    public function testAClosedHistoricalDayStillMissingItsClockOutStopsAtTheDayEnd(): void
    {
        $day = UtcTime::localDay('2026-10-07', 'Europe/Stockholm');
        $clockIn = new \DateTimeImmutable('2026-10-07 06:42:15', new \DateTimeZone('UTC'));
        $now = $day['end']->modify('+1 hour');
        $summary = AttendanceDaySummary::summarize([
            ['eventType' => 'clock_in', 'occurredAt' => $clockIn],
        ], $day['start'], $day['end'], $now);

        self::assertTrue($summary['missingClockOut']);
        self::assertSame(AttendanceDuration::seconds($clockIn, $day['end']), $summary['workedSeconds']);
    }

    public function testNoEventsAreOff(): void
    {
        $day = UtcTime::localDay('2026-10-07', 'Europe/Stockholm');
        $summary = AttendanceDaySummary::summarize([], $day['start'], $day['end'], $day['start']);

        self::assertSame('off', $summary['state']);
        self::assertSame(0, $summary['workedSeconds']);
        self::assertSame(0, $summary['lunchSeconds']);
        self::assertFalse($summary['missingClockOut']);
        self::assertNull($summary['clockIn']);
        self::assertNull($summary['clockOut']);
    }
}
