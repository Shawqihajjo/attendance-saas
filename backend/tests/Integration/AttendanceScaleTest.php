<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Identity\Infrastructure\Persistence\TenantAccountStore;
use App\Platform\TenantRegistry\Application\ProvisionTenant;
use App\Platform\TenantRegistry\Domain\ProvisionTenantRequest;
use App\Shared\Time\TimeDisplay;
use App\Shared\Time\TimeZoneChoice;
use App\Tenant\Attendance\Infrastructure\AttendanceDashboard;
use App\Tenant\Attendance\Infrastructure\AttendanceTimeReport;
use App\Tenant\Directory\Infrastructure\EmployeeDirectory;
use App\Tests\Support\CountingStatement;
use App\Tests\Support\QueryCounter;
use App\TenantContext\Domain\TenantDatabaseSwitcher;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

#[Group('integration')]
final class AttendanceScaleTest extends KernelTestCase
{
    public function testRosterAndDashboardStayConstantWhilePagesAndIndexesBoundTheLedger(): void
    {
        self::bootKernel();
        $platform = self::getContainer()->get('doctrine.dbal.platform_connection');
        self::assertInstanceOf(Connection::class, $platform);
        $platformIndexes = $platform->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'index'");
        self::assertContains('idx_platform_audit_occurred', $platformIndexes);

        $suffix = bin2hex(random_bytes(3));
        $provision = self::getContainer()->get(ProvisionTenant::class);
        self::assertInstanceOf(ProvisionTenant::class, $provision);
        $tenant = $provision->execute(new ProvisionTenantRequest(
            'scale'.$suffix,
            'Scale AB',
            'scale'.$suffix.'.localhost',
            'Europe/Stockholm',
            'sv',
            'cli',
        ));
        $switcher = self::getContainer()->get(TenantDatabaseSwitcher::class);
        self::assertInstanceOf(TenantDatabaseSwitcher::class, $switcher);
        $switcher->switchTo($tenant->id());
        $connection = self::getContainer()->get('doctrine.dbal.tenant_connection');
        $directory = self::getContainer()->get(EmployeeDirectory::class);
        $accounts = self::getContainer()->get(TenantAccountStore::class);
        $report = self::getContainer()->get(AttendanceTimeReport::class);
        $dashboard = self::getContainer()->get(AttendanceDashboard::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(EmployeeDirectory::class, $directory);
        self::assertInstanceOf(TenantAccountStore::class, $accounts);
        self::assertInstanceOf(AttendanceTimeReport::class, $report);
        self::assertInstanceOf(AttendanceDashboard::class, $dashboard);
        $native = $connection->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $native);
        $native->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [CountingStatement::class, []]);

        $indexes = $connection->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'index'");
        foreach ([
            'idx_attendance_device_time',
            'idx_adjustment_employee_type_time',
            'idx_correction_employee_day',
            'idx_correction_created',
            'idx_manager_assignment_employee',
            'idx_schedule_employee_time',
            'idx_attempt_device_status',
            'idx_heartbeat_device_time',
            'idx_audit_occurred',
            'idx_attendance_event_employee',
            'idx_attendance_employee_type_time',
            'idx_attendance_occurred',
        ] as $index) {
            self::assertContains($index, $indexes);
        }

        $departmentId = Uuid::v7()->toRfc4122();
        $teamId = Uuid::v7()->toRfc4122();
        $managerId = Uuid::v7()->toRfc4122();
        $connection->insert('departments', ['id' => $departmentId, 'name' => 'Floor', 'created_at' => '2026-05-04 06:00:00']);
        $connection->insert('teams', ['id' => $teamId, 'department_id' => $departmentId, 'name' => 'Line', 'created_at' => '2026-05-04 06:00:00']);
        $this->employee($connection, $managerId, 'Mina', $departmentId, $teamId);
        $watched = [];
        for ($i = 0; $i < 6; ++$i) {
            $watched[] = $this->employee($connection, Uuid::v7()->toRfc4122(), 'Person '.$i, $departmentId, $teamId);
        }
        $connection->insert('manager_assignments', [
            'id' => Uuid::v7()->toRfc4122(),
            'manager_employee_id' => $managerId,
            'employee_id' => $watched[0],
            'created_at' => '2026-05-04 07:00:00',
        ]);
        $connection->insert('schedules', [
            'id' => Uuid::v7()->toRfc4122(),
            'employee_id' => $watched[0],
            'name' => 'Days',
            'created_at' => '2026-05-04 07:00:00',
        ]);
        $connection->insert('employee_codes', [
            'employee_id' => $watched[0],
            'code' => hash('sha256', $watched[0]),
            'created_at' => '2026-05-04 07:00:00',
        ]);

        QueryCounter::arm();
        $listed = $directory->list(null);
        self::assertSame(1, QueryCounter::read());
        self::assertCount(7, $listed);
        $match = null;
        foreach ($listed as $row) {
            if ($row['id'] === $watched[0]) {
                $match = $row;
            }
        }
        self::assertIsArray($match);
        self::assertSame('Floor', $match['departmentName']);
        self::assertSame('Line', $match['teamName']);
        self::assertSame($managerId, $match['managerEmployeeId']);
        self::assertSame('Mina', $match['managerName']);
        self::assertSame('Days', $match['schedule']['name']);
        self::assertTrue($match['attendanceIdentifierSet']);
        QueryCounter::arm();
        $assigned = $directory->list($managerId);
        self::assertSame(1, QueryCounter::read());
        self::assertSame([$watched[0]], array_column($assigned, 'id'));

        $userId = Uuid::v7()->toRfc4122();
        $connection->insert('users', [
            'id' => $userId,
            'email' => 'scale@example.com',
            'display_name' => 'Scale',
            'status' => 'active',
            'token_version' => 1,
            'created_at' => '2026-05-04 06:00:00',
        ]);
        foreach (['employee', 'hr'] as $role) {
            $connection->insert('user_roles', [
                'id' => Uuid::v7()->toRfc4122(),
                'user_id' => $userId,
                'role' => $role,
            ]);
        }
        QueryCounter::arm();
        $users = $accounts->users();
        self::assertSame(1, QueryCounter::read());
        self::assertSame(['employee', 'hr'], $users[0]['roles']);

        $deviceId = Uuid::v7()->toRfc4122();
        $connection->insert('devices', [
            'id' => $deviceId,
            'platform_device_id' => Uuid::v7()->toRfc4122(),
            'display_name' => 'Gate',
            'timezone' => 'Europe/Stockholm',
            'created_at' => '2026-05-04 06:00:00',
        ]);
        foreach (['2026-10-01 08:00:00', '2026-10-07 06:00:00', '2026-10-07 08:00:00'] as $index => $occurred) {
            $connection->insert('attendance_events', [
                'id' => Uuid::v7()->toRfc4122(),
                'session_id' => Uuid::v7()->toRfc4122(),
                'employee_id' => $watched[0],
                'event_type' => 'clock_in',
                'occurred_at' => $occurred,
                'device_id' => $deviceId,
                'idempotency_key' => 'scale-'.$index,
            ]);
        }
        $choice = new TimeZoneChoice(TimeDisplay::Tenant, 'Europe/Stockholm');
        $page = $report->present($choice, null, null, null, $watched[0], 2, 0);
        self::assertSame(3, $page['page']['total']);
        self::assertSame(['2026-10-07T06:00:00Z', '2026-10-07T08:00:00Z'], array_column($page['records'], 'occurredAt'));
        $day = $report->present($choice, null, '2026-10-07', null, $watched[0], 50, 0);
        self::assertSame(2, $day['page']['total']);
        self::assertSame(['2026-10-07T06:00:00Z', '2026-10-07T08:00:00Z'], array_column($day['records'], 'occurredAt'));

        foreach ([0, 1, 2] as $index) {
            $connection->insert('attendance_correction_requests', [
                'id' => Uuid::v7()->toRfc4122(),
                'employee_id' => $watched[0],
                'requested_by_user_id' => $userId,
                'reason' => 'Scale '.$index,
                'status' => 'requested',
                'local_date' => '2026-10-07',
                'created_at' => sprintf('2026-10-07 09:0%d:00', $index),
            ]);
        }
        $corrections = $accounts->listCorrections($watched[0], null, 2, 0);
        self::assertSame(3, $corrections['page']['total']);
        self::assertCount(2, $corrections['records']);

        QueryCounter::arm();
        $board = $dashboard->organization(
            null,
            $choice,
            '2026-10-07',
            null,
            new \DateTimeImmutable('2026-10-07 12:00:00', new \DateTimeZone('UTC')),
            25,
            0,
        );
        $queries = QueryCounter::read();
        self::assertLessThanOrEqual(8, $queries);
        self::assertGreaterThan(0, $queries);
        self::assertSame(7, $board['counts']['working'] + $board['counts']['onLunch'] + $board['counts']['off']);
    }

    private function employee(Connection $connection, string $id, string $name, string $departmentId, string $teamId): string
    {
        $connection->insert('employees', [
            'id' => $id,
            'employee_number' => strtoupper(str_replace('-', '', $id)),
            'display_name' => $name,
            'department_id' => $departmentId,
            'team_id' => $teamId,
            'status' => 'active',
            'created_at' => '2026-05-04 06:00:00',
            'updated_at' => '2026-05-04 06:00:00',
        ]);

        return $id;
    }
}
