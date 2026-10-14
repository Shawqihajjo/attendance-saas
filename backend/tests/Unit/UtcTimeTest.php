<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Shared\Time\TimeDisplay;
use App\Shared\Time\TimeZoneChoice;
use App\Shared\Time\UtcTime;
use App\Tenant\Attendance\AttendanceDuration;
use PHPUnit\Framework\TestCase;

final class UtcTimeTest extends TestCase
{
    public function testStorageAndDisplayUseTheUtcInstant(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Pacific/Auckland');
        try {
            $stockholm = new \DateTimeImmutable('2026-10-07 08:42:15', new \DateTimeZone('Europe/Stockholm'));
            self::assertSame('2026-10-07 06:42:15', UtcTime::persist($stockholm));
            self::assertSame('2026-10-07T06:42:15Z', UtcTime::toIso($stockholm));
            self::assertSame('2026-10-07 08:42:15', UtcTime::present($stockholm, 'Europe/Stockholm'));
            $stored = UtcTime::parseStored('2026-10-07 06:42:15');
            self::assertSame('2026-10-07T06:42:15Z', UtcTime::toIso($stored));
            self::assertSame($stockholm->getTimestamp(), $stored->getTimestamp());
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testStockholmDaylightSavingAndMidnightUseTimestamps(): void
    {
        $springStart = new \DateTimeImmutable('2026-03-29 00:30:00', new \DateTimeZone('UTC'));
        $springEnd = new \DateTimeImmutable('2026-03-29 01:30:00', new \DateTimeZone('UTC'));
        self::assertSame('2026-03-29 01:30:00', UtcTime::present($springStart, 'Europe/Stockholm'));
        self::assertSame('2026-03-29 03:30:00', UtcTime::present($springEnd, 'Europe/Stockholm'));
        self::assertSame(3600, UtcTime::durationSeconds($springStart, $springEnd));
        self::assertSame(AttendanceDuration::seconds($springStart, $springEnd), UtcTime::durationSeconds($springStart, $springEnd));

        $fallStart = new \DateTimeImmutable('2026-10-25 00:30:00', new \DateTimeZone('UTC'));
        $fallEnd = new \DateTimeImmutable('2026-10-25 01:30:00', new \DateTimeZone('UTC'));
        self::assertSame('2026-10-25 02:30:00', UtcTime::present($fallStart, 'Europe/Stockholm'));
        self::assertSame('2026-10-25 02:30:00', UtcTime::present($fallEnd, 'Europe/Stockholm'));
        self::assertSame(3600, UtcTime::durationSeconds($fallStart, $fallEnd));

        $beforeMidnight = new \DateTimeImmutable('2026-01-15 23:30:00', new \DateTimeZone('UTC'));
        self::assertSame('2026-01-16 00:30:00', UtcTime::present($beforeMidnight, 'Europe/Stockholm'));
        $winterDay = UtcTime::localDay('2026-01-16', 'Europe/Stockholm');
        self::assertSame('2026-01-15T23:00:00Z', UtcTime::toIso($winterDay['start']));
        self::assertTrue($beforeMidnight >= $winterDay['start'] && $beforeMidnight < $winterDay['end']);
        $previousDay = UtcTime::localDay('2026-01-15', 'Europe/Stockholm');
        self::assertFalse($beforeMidnight >= $previousDay['start'] && $beforeMidnight < $previousDay['end']);

        $springDay = UtcTime::localDay('2026-03-29', 'Europe/Stockholm');
        self::assertSame(23 * 3600, UtcTime::durationSeconds($springDay['start'], $springDay['end']));
        $fallDay = UtcTime::localDay('2026-10-25', 'Europe/Stockholm');
        self::assertSame(25 * 3600, UtcTime::durationSeconds($fallDay['start'], $fallDay['end']));
    }

    public function testTenantTerminalBrowserAndReportZonesAreIndependent(): void
    {
        $instant = new \DateTimeImmutable('2026-10-07 06:42:15', new \DateTimeZone('UTC'));
        $stockholm = new TimeZoneChoice(TimeDisplay::Tenant, 'Europe/Stockholm');
        $tokyo = new TimeZoneChoice(TimeDisplay::Tenant, 'Asia/Tokyo');
        self::assertSame('Europe/Stockholm', $stockholm->resolved());
        self::assertSame('Asia/Tokyo', $tokyo->resolved());
        self::assertSame('2026-10-07 08:42:15', UtcTime::present($instant, $stockholm->resolved()));
        self::assertSame('2026-10-07 15:42:15', UtcTime::present($instant, $tokyo->resolved()));

        $chicago = new TimeZoneChoice(TimeDisplay::Terminal, 'Europe/Stockholm', 'America/Chicago');
        $auckland = new TimeZoneChoice(TimeDisplay::Terminal, 'Europe/Stockholm', 'Pacific/Auckland');
        self::assertSame('2026-10-07 01:42:15', UtcTime::present($instant, $chicago->resolved()));
        self::assertSame('2026-10-07 19:42:15', UtcTime::present($instant, $auckland->resolved()));

        $browser = new TimeZoneChoice(TimeDisplay::Browser, 'Europe/Stockholm', browserTimeZone: 'Asia/Tokyo');
        self::assertSame('Asia/Tokyo', $browser->resolved());
        self::assertSame('2026-10-07 15:42:15', UtcTime::present($instant, $browser->resolved()));
        self::assertSame('2026-10-07T06:42:15Z', UtcTime::toIso($instant));

        $report = new TimeZoneChoice(TimeDisplay::Report, 'Europe/Stockholm', reportTimeZone: 'America/New_York');
        $defaultReport = new TimeZoneChoice(TimeDisplay::Report, 'Europe/Stockholm');
        self::assertSame('America/New_York', $report->resolved());
        self::assertSame('Europe/Stockholm', $defaultReport->resolved());
        self::assertSame('2026-10-07 02:42:15', UtcTime::present($instant, $report->resolved()));
    }

    public function testFixedOffsetsAreRejected(): void
    {
        foreach (['+01:00', 'UTC+1', 'GMT', 'Etc/GMT-1', 'local'] as $rejected) {
            try {
                UtcTime::requireIana($rejected);
                self::fail($rejected.' should be rejected.');
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame('UTC', UtcTime::requireIana('UTC'));
        self::assertSame('Europe/Stockholm', UtcTime::requireIana('Europe/Stockholm'));
    }
}
