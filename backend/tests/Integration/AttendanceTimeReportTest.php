<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Platform\TenantRegistry\Application\ProvisionTenant;
use App\Platform\TenantRegistry\Domain\ProvisionTenantRequest;
use App\Shared\Time\TimeDisplay;
use App\Shared\Time\TimeZoneChoice;
use App\Shared\Time\UtcTime;
use App\Tenant\Attendance\AttendanceAction;
use App\Tenant\Attendance\Infrastructure\AttendanceLedger;
use App\Tenant\Attendance\Infrastructure\AttendanceTimeReport;
use App\Tenant\Attendance\RecordAttendance;
use App\Tenant\Attendance\VerificationMethod;
use App\TenantContext\Domain\TenantDatabaseSwitcher;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

#[Group('integration')]
final class AttendanceTimeReportTest extends KernelTestCase
{
    public function testReportsConvertStoredUtcWithoutUsingADisplayString(): void
    {
        [$report, $ledger, $connection] = $this->openTenant();
        $employeeId = $this->employee($connection);
        $photoId = $this->photo($connection, $employeeId);
        $chicago = $this->device($connection, 'America/Chicago');
        $auckland = $this->device($connection, 'Pacific/Auckland');
        $previous = date_default_timezone_get();
        date_default_timezone_set('Pacific/Auckland');
        try {
            $ledger->commit(
                $this->command($employeeId, $chicago, AttendanceAction::ClockIn, $photoId, 'zone-clock-in'),
                new \DateTimeImmutable('2026-10-07 08:42:15', new \DateTimeZone('Europe/Stockholm')),
            );
            $ledger->commit(
                $this->command($employeeId, $auckland, AttendanceAction::ClockOut, null, 'zone-out'),
                new \DateTimeImmutable('2026-10-07 07:42:15', new \DateTimeZone('UTC')),
            );
        } finally {
            date_default_timezone_set($previous);
        }

        $stored = $connection->fetchFirstColumn(
            'SELECT occurred_at FROM attendance_events WHERE employee_id = ? ORDER BY occurred_at',
            [$employeeId],
        );
        self::assertSame(['2026-10-07 06:42:15', '2026-10-07 07:42:15'], $stored);

        $tenant = $report->present(new TimeZoneChoice(TimeDisplay::Tenant, 'Europe/Stockholm'), null, null, null);
        self::assertSame('Europe/Stockholm', $tenant['timezone']);
        self::assertSame('2026-10-07T06:42:15Z', $tenant['records'][0]['occurredAt']);
        self::assertSame('2026-10-07 08:42:15', $tenant['records'][0]['displayedAt']);
        self::assertSame('2026-10-07 09:42:15', $tenant['records'][1]['displayedAt']);
        self::assertSame(3600, UtcTime::durationSeconds(
            UtcTime::parseStored('2026-10-07 06:42:15'),
            UtcTime::parseStored('2026-10-07 07:42:15'),
        ));

        $terminals = $report->present(new TimeZoneChoice(TimeDisplay::Terminal, 'Europe/Stockholm'), null, '2026-10-07', null);
        self::assertNull($terminals['timezone']);
        self::assertSame('America/Chicago', $terminals['records'][0]['timezone']);
        self::assertSame('2026-10-07 01:42:15', $terminals['records'][0]['displayedAt']);
        self::assertSame('Pacific/Auckland', $terminals['records'][1]['timezone']);
        self::assertSame('2026-10-07 20:42:15', $terminals['records'][1]['displayedAt']);

        $chicagoOnly = $report->present(new TimeZoneChoice(TimeDisplay::Terminal, 'Europe/Stockholm'), null, null, $chicago);
        self::assertSame('America/Chicago', $chicagoOnly['timezone']);
        self::assertCount(1, $chicagoOnly['records']);

        $reportZone = $report->present(
            new TimeZoneChoice(TimeDisplay::Report, 'Europe/Stockholm', reportTimeZone: 'America/New_York'),
            null,
            '2026-10-07',
            null,
        );
        self::assertSame('America/New_York', $reportZone['timezone']);
        self::assertSame('2026-10-07 02:42:15', $reportZone['records'][0]['displayedAt']);
        self::assertSame('2026-10-07T06:42:15Z', $reportZone['records'][0]['occurredAt']);

        $browser = $report->present(
            new TimeZoneChoice(TimeDisplay::Browser, 'Europe/Stockholm', browserTimeZone: 'Asia/Tokyo'),
            null,
            null,
            null,
        );
        self::assertSame('Asia/Tokyo', $browser['timezone']);
        self::assertSame('2026-10-07 15:42:15', $browser['records'][0]['displayedAt']);
        self::assertSame('2026-10-07T06:42:15Z', $browser['records'][0]['occurredAt']);

        $boundaryEmployee = $this->employee($connection);
        $boundaryPhoto = $this->photo($connection, $boundaryEmployee);
        $ledger->commit(
            $this->command($boundaryEmployee, $chicago, AttendanceAction::ClockIn, $boundaryPhoto, 'boundary-in'),
            new \DateTimeImmutable('2026-10-07 04:30:00', new \DateTimeZone('UTC')),
        );
        $tenantDay = $report->present(new TimeZoneChoice(TimeDisplay::Tenant, 'Europe/Stockholm'), null, '2026-10-07', null);
        self::assertContains('2026-10-07 06:30:00', array_column($tenantDay['records'], 'displayedAt'));
        $terminalDay = $report->present(new TimeZoneChoice(TimeDisplay::Terminal, 'Europe/Stockholm'), null, '2026-10-07', null);
        $terminalBoundary = array_values(array_filter(
            $terminalDay['records'],
            static fn (array $row): bool => $row['employeeId'] === $boundaryEmployee,
        ));
        self::assertSame([], $terminalBoundary);
        $previousTerminalDay = $report->present(new TimeZoneChoice(TimeDisplay::Terminal, 'Europe/Stockholm'), null, '2026-10-06', null);
        self::assertSame('2026-10-06 23:30:00', $previousTerminalDay['records'][0]['displayedAt']);
        self::assertSame('America/Chicago', $previousTerminalDay['records'][0]['timezone']);
        self::assertSame('2026-10-07T04:30:00Z', $previousTerminalDay['records'][0]['occurredAt']);

        $midnightEmployee = $this->employee($connection);
        $midnightPhoto = $this->photo($connection, $midnightEmployee);
        $ledger->commit(
            $this->command($midnightEmployee, $chicago, AttendanceAction::ClockIn, $midnightPhoto, 'midnight-in'),
            new \DateTimeImmutable('2026-01-15 23:30:00', new \DateTimeZone('UTC')),
        );
        $nextLocalDay = $report->present(new TimeZoneChoice(TimeDisplay::Tenant, 'Europe/Stockholm'), null, '2026-01-16', null);
        $sameUtcDay = $report->present(new TimeZoneChoice(TimeDisplay::Tenant, 'Europe/Stockholm'), null, '2026-01-15', null);
        self::assertSame(['2026-01-16 00:30:00'], array_column($nextLocalDay['records'], 'displayedAt'));
        self::assertSame([], $sameUtcDay['records']);

        $springEmployee = $this->employee($connection);
        $springPhoto = $this->photo($connection, $springEmployee);
        $ledger->commit(
            $this->command($springEmployee, $chicago, AttendanceAction::ClockIn, $springPhoto, 'spring-in'),
            new \DateTimeImmutable('2026-03-29 00:30:00', new \DateTimeZone('UTC')),
        );
        $ledger->commit(
            $this->command($springEmployee, $chicago, AttendanceAction::ClockOut, null, 'spring-out'),
            new \DateTimeImmutable('2026-03-29 01:30:00', new \DateTimeZone('UTC')),
        );
        $spring = $report->present(new TimeZoneChoice(TimeDisplay::Tenant, 'Europe/Stockholm'), null, '2026-03-29', null);
        $springRows = array_values(array_filter(
            $spring['records'],
            static fn (array $row): bool => $row['employeeId'] === $springEmployee,
        ));
        self::assertSame('2026-03-29 01:30:00', $springRows[0]['displayedAt']);
        self::assertSame('2026-03-29 03:30:00', $springRows[1]['displayedAt']);
        self::assertSame(3600, UtcTime::durationSeconds(
            UtcTime::parseStored(str_replace('T', ' ', substr($springRows[0]['occurredAt'], 0, 19))),
            UtcTime::parseStored(str_replace('T', ' ', substr($springRows[1]['occurredAt'], 0, 19))),
        ));
    }

    private function command(
        string $employeeId,
        string $deviceId,
        AttendanceAction $action,
        ?string $photoId,
        string $key,
    ): RecordAttendance {
        return new RecordAttendance($employeeId, $action, VerificationMethod::Fingerprint, $deviceId, $photoId, $key, true);
    }

    /**
     * @return array{AttendanceTimeReport, AttendanceLedger, Connection}
     */
    private function openTenant(): array
    {
        self::bootKernel();
        $suffix = bin2hex(random_bytes(3));
        $provision = self::getContainer()->get(ProvisionTenant::class);
        self::assertInstanceOf(ProvisionTenant::class, $provision);
        $tenant = $provision->execute(new ProvisionTenantRequest(
            'time'.$suffix,
            'Example AB',
            'time'.$suffix.'.localhost',
            'Europe/Stockholm',
            'sv',
            'cli',
        ));
        $switcher = self::getContainer()->get(TenantDatabaseSwitcher::class);
        self::assertInstanceOf(TenantDatabaseSwitcher::class, $switcher);
        $switcher->switchTo($tenant->id());
        $report = self::getContainer()->get(AttendanceTimeReport::class);
        $ledger = self::getContainer()->get(AttendanceLedger::class);
        $connection = self::getContainer()->get('doctrine.dbal.tenant_connection');
        self::assertInstanceOf(AttendanceTimeReport::class, $report);
        self::assertInstanceOf(AttendanceLedger::class, $ledger);
        self::assertInstanceOf(Connection::class, $connection);

        return [$report, $ledger, $connection];
    }

    private function employee(Connection $connection): string
    {
        $id = Uuid::v7()->toRfc4122();
        $connection->insert('employees', [
            'id' => $id,
            'employee_number' => strtoupper(str_replace('-', '', $id)),
            'display_name' => 'Ada',
            'status' => 'active',
            'created_at' => '2026-05-04 06:00:00',
            'updated_at' => '2026-05-04 06:00:00',
        ]);

        return $id;
    }

    private function photo(Connection $connection, string $employeeId): string
    {
        $id = Uuid::v7()->toRfc4122();
        $connection->insert('attendance_photos', [
            'id' => $id,
            'employee_id' => $employeeId,
            'storage_key' => 'photos/'.$id,
            'captured_at' => '2026-05-04 06:59:00',
        ]);

        return $id;
    }

    private function device(Connection $connection, string $timezone): string
    {
        $id = Uuid::v7()->toRfc4122();
        $connection->insert('devices', [
            'id' => $id,
            'platform_device_id' => Uuid::v7()->toRfc4122(),
            'display_name' => $timezone,
            'timezone' => $timezone,
            'created_at' => '2026-05-04 06:00:00',
        ]);

        return $id;
    }
}
