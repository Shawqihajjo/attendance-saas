<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Platform\TenantRegistry\Application\ProvisionTenant;
use App\Platform\TenantRegistry\Domain\ProvisionTenantRequest;
use App\Tenant\Attendance\AttendanceAction;
use App\Tenant\Attendance\AttendanceCommit;
use App\Tenant\Attendance\AttendanceDuration;
use App\Tenant\Attendance\AttendanceRejectedException;
use App\Tenant\Attendance\AttendanceState;
use App\Tenant\Attendance\Infrastructure\AttendanceLedger;
use App\Tenant\Attendance\RecordAttendance;
use App\Tenant\Attendance\VerificationMethod;
use App\TenantContext\Domain\TenantDatabaseSwitcher;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

#[Group('integration')]
final class AttendanceLedgerTest extends KernelTestCase
{
    public function testAcceptedEventsFollowTheStateMachineAndInvalidOnesDoNot(): void
    {
        [$ledger, $connection] = $this->openTenant();
        $employeeId = $this->employee($connection, 'active');
        $deviceId = Uuid::v7()->toRfc4122();
        $photoId = $this->photo($connection, $employeeId);
        $at = new \DateTimeImmutable('2026-05-04 07:00:00', new \DateTimeZone('UTC'));

        $clockIn = $ledger->commit($this->command($employeeId, $deviceId, AttendanceAction::ClockIn, $photoId, 'clock-in-01'), $at);
        self::assertFalse($clockIn->replayed);
        self::assertSame(AttendanceState::Working, $clockIn->state);
        self::assertSame(AttendanceState::Working, $ledger->currentState($employeeId));

        $secondEmployee = $this->employee($connection, 'active');
        $noPhoto = $this->reject(fn () => $ledger->commit(
            $this->command($secondEmployee, $deviceId, AttendanceAction::ClockIn, null, 'clock-in-03'),
            $at,
        ));
        self::assertSame('photo_required', $noPhoto->reason());
        self::assertSame(0, $this->countEvents($connection, $secondEmployee));

        $codeOnly = $this->reject(fn () => $ledger->commit(
            $this->command($secondEmployee, $deviceId, AttendanceAction::ClockIn, $photoId, 'clock-in-04', verification: VerificationMethod::Code),
            $at,
        ));
        self::assertSame('verification_insufficient', $codeOnly->reason());

        $inactiveId = $this->employee($connection, 'inactive');
        $inactive = $this->reject(fn () => $ledger->commit(
            $this->command($inactiveId, $deviceId, AttendanceAction::ClockIn, $photoId, 'clock-in-05'),
            $at,
        ));
        self::assertSame('employee_inactive', $inactive->reason());

        foreach ([AttendanceAction::ClockOut, AttendanceAction::LunchStart, AttendanceAction::LunchEnd, AttendanceAction::ClockIn] as $index => $action) {
            $target = $action === AttendanceAction::ClockIn ? $employeeId : $secondEmployee;
            $reason = $this->reject(fn () => $ledger->commit(
                $this->command($target, $deviceId, $action, $photoId, 'illegal-0'.$index),
                $at,
            ))->reason();
            self::assertSame('illegal_transition', $reason);
        }

        $lunch = $ledger->commit($this->command($employeeId, $deviceId, AttendanceAction::LunchStart, null, 'lunch-start'), $at->modify('+4 hours'));
        self::assertSame(AttendanceState::OnLunch, $lunch->state);
        $stillLunch = $this->reject(fn () => $ledger->commit(
            $this->command($employeeId, $deviceId, AttendanceAction::LunchStart, null, 'lunch-again'),
            $at->modify('+5 hours'),
        ));
        self::assertSame('illegal_transition', $stillLunch->reason());
        $leaveDuringLunch = $this->reject(fn () => $ledger->commit(
            $this->command($employeeId, $deviceId, AttendanceAction::ClockOut, null, 'clock-out-lunch'),
            $at->modify('+5 hours'),
        ));
        self::assertSame('illegal_transition', $leaveDuringLunch->reason());

        $back = $ledger->commit($this->command($employeeId, $deviceId, AttendanceAction::LunchEnd, null, 'lunch-end'), $at->modify('+5 hours'));
        self::assertSame(AttendanceState::Working, $back->state);
        $out = $ledger->commit($this->command($employeeId, $deviceId, AttendanceAction::ClockOut, null, 'clock-out'), $at->modify('+8 hours'));
        self::assertSame(AttendanceState::Off, $out->state);
        self::assertSame(AttendanceState::Off, $ledger->currentState($employeeId));
        self::assertSame(4, $this->countEvents($connection, $employeeId));
        self::assertSame(0, $this->openSessions($connection, $employeeId));

        $connection->executeStatement(
            'UPDATE attendance_sessions SET state = ? WHERE id = ?',
            ['off', $clockIn->sessionId],
        );
        $this->reopen($connection, $employeeId, $clockIn->sessionId);
        $conflict = $this->reject(fn () => $ledger->commit(
            $this->command($employeeId, $deviceId, AttendanceAction::LunchStart, null, 'after-conflict'),
            $at->modify('+9 hours'),
        ));
        self::assertSame('attendance_projection_conflict', $conflict->reason());
    }

    public function testARepeatedIdempotencyKeyReturnsTheOriginalEvent(): void
    {
        [$ledger, $connection] = $this->openTenant();
        $employeeId = $this->employee($connection, 'active');
        $deviceId = Uuid::v7()->toRfc4122();
        $photoId = $this->photo($connection, $employeeId);
        $at = new \DateTimeImmutable('2026-05-04 07:00:00', new \DateTimeZone('UTC'));
        $command = $this->command($employeeId, $deviceId, AttendanceAction::ClockIn, $photoId, 'same-key-01', metadata: [
            'attendanceCode' => 'SHOULD-NOT-BE-STORED',
            'note' => 'front door',
        ]);

        $first = $ledger->commit($command, $at);
        $second = $ledger->commit($command, $at->modify('+1 minute'));
        self::assertTrue($second->replayed);
        self::assertSame($first->eventId, $second->eventId);
        self::assertSame(1, $this->countEvents($connection, $employeeId));
        $stored = $connection->fetchOne('SELECT metadata FROM attendance_events WHERE id = ?', [$first->eventId]);
        self::assertIsString($stored);
        self::assertStringNotContainsString('SHOULD-NOT-BE-STORED', $stored);
        self::assertStringContainsString('front door', $stored);

        $conflict = $this->reject(fn () => $ledger->commit(
            $this->command($employeeId, $deviceId, AttendanceAction::LunchStart, null, 'same-key-01'),
            $at,
        ));
        self::assertSame('idempotency_conflict', $conflict->reason());
        self::assertSame(1, $this->countEvents($connection, $employeeId));
        self::assertSame(1, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'attendance.committed'",
        ));
    }

    public function testStoredTimesStayUtcAcrossMidnightAndDaylightSaving(): void
    {
        [$ledger, $connection] = $this->openTenant();
        $employeeId = $this->employee($connection, 'active');
        $deviceId = Uuid::v7()->toRfc4122();
        $photoId = $this->photo($connection, $employeeId);
        $stockholm = new \DateTimeZone('Europe/Stockholm');
        $clockInLocal = new \DateTimeImmutable('2026-01-16 00:30:00', $stockholm);
        $clockOutUtc = new \DateTimeImmutable('2026-01-16 00:30:00', new \DateTimeZone('UTC'));

        $opened = $ledger->commit(
            $this->command($employeeId, $deviceId, AttendanceAction::ClockIn, $photoId, 'midnight-in'),
            $clockInLocal,
        );
        $closed = $ledger->commit(
            $this->command($employeeId, $deviceId, AttendanceAction::ClockOut, null, 'midnight-out'),
            $clockOutUtc,
        );
        self::assertSame($opened->sessionId, $closed->sessionId);
        self::assertSame('2026-01-15 23:30:00', $connection->fetchOne(
            'SELECT occurred_at FROM attendance_events WHERE id = ?',
            [$opened->eventId],
        ));
        self::assertSame('2026-01-16 00:30:00', $connection->fetchOne(
            'SELECT occurred_at FROM attendance_events WHERE id = ?',
            [$closed->eventId],
        ));
        self::assertSame(3600, AttendanceDuration::seconds($opened->occurredAt, $closed->occurredAt));
        self::assertSame('UTC', $opened->occurredAt->getTimezone()->getName());

        $springEmployee = $this->employee($connection, 'active');
        $springPhoto = $this->photo($connection, $springEmployee);
        $springIn = $ledger->commit(
            $this->command($springEmployee, $deviceId, AttendanceAction::ClockIn, $springPhoto, 'spring-in'),
            new \DateTimeImmutable('2026-03-29 00:30:00', new \DateTimeZone('UTC')),
        );
        $springOut = $ledger->commit(
            $this->command($springEmployee, $deviceId, AttendanceAction::ClockOut, null, 'spring-out'),
            new \DateTimeImmutable('2026-03-29 01:30:00', new \DateTimeZone('UTC')),
        );
        self::assertSame(3600, AttendanceDuration::seconds($springIn->occurredAt, $springOut->occurredAt));
        self::assertSame('03:30', $springOut->occurredAt->setTimezone($stockholm)->format('H:i'));

        $fallEmployee = $this->employee($connection, 'active');
        $fallPhoto = $this->photo($connection, $fallEmployee);
        $fallIn = $ledger->commit(
            $this->command($fallEmployee, $deviceId, AttendanceAction::ClockIn, $fallPhoto, 'fall-clock-in'),
            new \DateTimeImmutable('2026-10-25 00:30:00', new \DateTimeZone('UTC')),
        );
        $fallOut = $ledger->commit(
            $this->command($fallEmployee, $deviceId, AttendanceAction::ClockOut, null, 'fall-clock-out'),
            new \DateTimeImmutable('2026-10-25 01:30:00', new \DateTimeZone('UTC')),
        );
        self::assertSame(
            $fallIn->occurredAt->setTimezone($stockholm)->format('H:i'),
            $fallOut->occurredAt->setTimezone($stockholm)->format('H:i'),
        );
        self::assertSame(3600, AttendanceDuration::seconds($fallIn->occurredAt, $fallOut->occurredAt));
    }

    public function testTwoTerminalsCannotAppendConflictingClockIns(): void
    {
        [$ledger, $connection, $tenantId] = $this->openTenant(true);
        $employeeId = $this->employee($connection, 'active');
        $photoId = $this->photo($connection, $employeeId);
        $deviceA = Uuid::v7()->toRfc4122();
        $deviceB = Uuid::v7()->toRfc4122();
        $connection->executeQuery('PRAGMA journal_mode = WAL');
        $connection->executeStatement('PRAGMA busy_timeout = 5000');

        $first = $this->spawn($tenantId, $employeeId, $deviceA, 'race-key-a', $photoId);
        $second = $this->spawn($tenantId, $employeeId, $deviceB, 'race-key-b', $photoId);
        $firstResult = $this->finish($first);
        $secondResult = $this->finish($second);
        $outcomes = [$firstResult['output'], $secondResult['output']];
        sort($outcomes);
        self::assertSame(
            ['accepted', 'rejected illegal_transition'],
            $outcomes,
            $firstResult['error'].$secondResult['error'],
        );
        self::assertSame(1, $this->countEvents($connection, $employeeId));
        self::assertSame(AttendanceState::Working, $ledger->currentState($employeeId));
    }

    /**
     * @param array<string, scalar|null> $metadata
     */
    private function command(
        string $employeeId,
        string $deviceId,
        AttendanceAction $action,
        ?string $photoId,
        string $key,
        VerificationMethod $verification = VerificationMethod::Fingerprint,
        array $metadata = [],
    ): RecordAttendance {
        return new RecordAttendance($employeeId, $action, $verification, $deviceId, $photoId, $key, true, metadata: $metadata);
    }

    private function reject(callable $action): AttendanceRejectedException
    {
        try {
            $action();
        } catch (AttendanceRejectedException $exception) {
            return $exception;
        }
        self::fail('The attendance transition was accepted.');
    }

    /**
     * @return array{AttendanceLedger, Connection}|array{AttendanceLedger, Connection, string}
     */
    private function openTenant(bool $withId = false): array
    {
        self::bootKernel();
        $suffix = bin2hex(random_bytes(3));
        $provision = self::getContainer()->get(ProvisionTenant::class);
        self::assertInstanceOf(ProvisionTenant::class, $provision);
        $tenant = $provision->execute(new ProvisionTenantRequest(
            'att'.$suffix,
            'Example AB',
            'att'.$suffix.'.localhost',
            'Europe/Stockholm',
            'sv',
            'cli',
        ));
        $switcher = self::getContainer()->get(TenantDatabaseSwitcher::class);
        self::assertInstanceOf(TenantDatabaseSwitcher::class, $switcher);
        $switcher->switchTo($tenant->id());
        $ledger = self::getContainer()->get(AttendanceLedger::class);
        $connection = self::getContainer()->get('doctrine.dbal.tenant_connection');
        self::assertInstanceOf(AttendanceLedger::class, $ledger);
        self::assertInstanceOf(Connection::class, $connection);
        if ($withId) {
            return [$ledger, $connection, $tenant->id()];
        }

        return [$ledger, $connection];
    }

    private function employee(Connection $connection, string $status): string
    {
        $id = Uuid::v7()->toRfc4122();
        $connection->insert('employees', [
            'id' => $id,
            'employee_number' => strtoupper(str_replace('-', '', $id)),
            'display_name' => 'Ada',
            'status' => $status,
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

    private function countEvents(Connection $connection, string $employeeId): int
    {
        return (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM attendance_events WHERE employee_id = ?',
            [$employeeId],
        );
    }

    private function openSessions(Connection $connection, string $employeeId): int
    {
        return (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM attendance_sessions WHERE employee_id = ? AND closed_at IS NULL',
            [$employeeId],
        );
    }

    private function reopen(Connection $connection, string $employeeId, string $sessionId): void
    {
        $connection->executeStatement(
            'UPDATE attendance_sessions SET state = ?, closed_at = NULL, open_employee_id = ? WHERE id = ?',
            ['off', $employeeId, $sessionId],
        );
        $connection->executeStatement('DELETE FROM attendance_events WHERE session_id = ? AND event_type <> ?', [
            $sessionId,
            AttendanceAction::ClockIn->value,
        ]);
    }

    /**
     * @return array{process: resource, pipes: array<int, resource>}
     */
    private function spawn(string $tenantId, string $employeeId, string $deviceId, string $key, string $photoId): array
    {
        $pipes = [];
        $process = proc_open([
            PHP_BINARY,
            dirname(__DIR__).'/bin/attendance-commit.php',
            $tenantId,
            $employeeId,
            $deviceId,
            $key,
            $photoId,
        ], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        /** @var array<int, resource> $pipes */
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 20);
        stream_set_timeout($pipes[2], 20);

        return ['process' => $process, 'pipes' => $pipes];
    }

    /**
     * @param array{process: resource, pipes: array<int, resource>} $spawned
     *
     * @return array{output: string, error: string}
     */
    private function finish(array $spawned): array
    {
        $output = stream_get_contents($spawned['pipes'][1]);
        $error = stream_get_contents($spawned['pipes'][2]);
        fclose($spawned['pipes'][1]);
        fclose($spawned['pipes'][2]);
        proc_close($spawned['process']);

        return [
            'output' => trim(is_string($output) ? $output : ''),
            'error' => is_string($error) ? $error : '',
        ];
    }
}
