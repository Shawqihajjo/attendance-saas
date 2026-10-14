<?php

declare(strict_types=1);

namespace App\Tenant\Attendance\Infrastructure;

use App\Identity\Infrastructure\Persistence\TenantAccountStore;
use App\Integration\DomainEventPublisher;
use App\Tenant\Attendance\AttendanceAction;
use App\Tenant\Attendance\AttendanceCommit;
use App\Tenant\Attendance\AttendanceMachine;
use App\Tenant\Attendance\AttendanceRejectedException;
use App\Tenant\Attendance\AttendanceState;
use App\Tenant\Attendance\RecordAttendance;
use App\Shared\Time\UtcTime;
use App\Tenant\Attendance\VerificationPolicy;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Appends an attendance event only after the employee's persisted history allows that transition.
 * The caller supplies the requested action. It does not supply the current state.
 */
final class AttendanceLedger
{
    /** @var resource|null */
    private $sqliteLock = null;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.tenant_connection')]
        private readonly Connection $connection,
        private readonly VerificationPolicy $verification,
        private readonly TenantAccountStore $accounts,
        private readonly DomainEventPublisher $events,
    ) {
    }

    public function currentState(string $employeeId): AttendanceState
    {
        if (!Uuid::isValid($employeeId)) {
            throw new AttendanceRejectedException('invalid_reference', 'The employee identifier must be a UUID.');
        }

        return $this->derive($employeeId);
    }

    public function commit(RecordAttendance $command, \DateTimeImmutable $now): AttendanceCommit
    {
        $sqlite = $this->connection->getDatabasePlatform() instanceof SQLitePlatform;
        $this->prepareSqlite();
        if ($sqlite) {
            $this->acquireSqliteFileLock();
        }
        $utc = UtcTime::instant($now);
        try {
            try {
                $result = $this->connection->transactional(function () use ($command, $utc): AttendanceCommit {
                    return $this->commitLocked($command, $utc);
                });
            } catch (AttendanceRejectedException $exception) {
                $this->recordRejection($command, $exception, $utc);
                throw $exception;
            }
        } finally {
            $this->releaseSqliteFileLock();
        }
        if (!$result->replayed) {
            $this->events->publish($this->eventName($result->action), $command->employeeId, [
                'eventId' => $result->eventId,
                'employeeId' => $command->employeeId,
                'action' => $result->action->value,
                'occurredAt' => UtcTime::toIso($result->occurredAt),
            ], $result->occurredAt);
        }

        return $result;
    }

    private function commitLocked(RecordAttendance $command, \DateTimeImmutable $utc): AttendanceCommit
    {
        $this->lock($command->employeeId);
        $existing = $this->existingEvent($command->deviceId, $command->idempotencyKey);
        if (is_array($existing)) {
            return $this->replay($existing, $command);
        }

        $this->assertEmployeeActive($command->employeeId);
        $state = $this->derive($command->employeeId);
        $sessionId = $this->openSessionId($command->employeeId, $state);
        $next = $command->action->next($state);
        $this->verification->assertAccepted($command->verification);
        $photoId = $this->photoReference($command);

        $eventId = Uuid::v7()->toRfc4122();
        $stamp = UtcTime::persist($utc);
        if ($command->action === AttendanceAction::ClockIn) {
            $sessionId = Uuid::v7()->toRfc4122();
        }
        if (!is_string($sessionId)) {
            throw new AttendanceRejectedException('attendance_projection_conflict', 'The open attendance session is missing.');
        }

        try {
            if ($command->action === AttendanceAction::ClockIn) {
                $this->connection->insert('attendance_sessions', [
                    'id' => $sessionId,
                    'employee_id' => $command->employeeId,
                    'state' => $next->value,
                    'opened_at' => $stamp,
                    'closed_at' => null,
                    'open_employee_id' => $command->employeeId,
                ]);
            }
            $this->connection->insert('attendance_events', [
                'id' => $eventId,
                'session_id' => $sessionId,
                'employee_id' => $command->employeeId,
                'event_type' => $command->action->value,
                'occurred_at' => $stamp,
                'verification_method' => $command->verification->value,
                'device_id' => $command->deviceId,
                'photo_id' => $photoId,
                'source' => $command->source->value,
                'idempotency_key' => $command->idempotencyKey,
                'created_at' => $stamp,
                'metadata' => $this->metadata($command->metadata),
            ]);
            if ($command->action !== AttendanceAction::ClockIn) {
                $this->connection->update('attendance_sessions', [
                    'state' => $next->value,
                    'closed_at' => $next === AttendanceState::Off ? $stamp : null,
                    'open_employee_id' => $next === AttendanceState::Off ? null : $command->employeeId,
                ], ['id' => $sessionId]);
            }
        } catch (UniqueConstraintViolationException) {
            $winner = $this->existingEvent($command->deviceId, $command->idempotencyKey);
            if (is_array($winner)) {
                return $this->replay($winner, $command);
            }
            throw new AttendanceRejectedException(
                'illegal_transition',
                'Another terminal already changed this employee.',
            );
        }

        $this->accounts->audit(
            'attendance.committed',
            'device',
            $command->deviceId,
            'attendance_event',
            $eventId,
            null,
            [
                'employeeId' => $command->employeeId,
                'action' => $command->action->value,
                'state' => $next->value,
                'verification' => $command->verification->value,
                'photoId' => $photoId,
            ],
            $utc,
        );

        return new AttendanceCommit($eventId, $sessionId, $command->action, $next, $utc, false);
    }

    private function prepareSqlite(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            return;
        }
        $this->connection->executeStatement('PRAGMA busy_timeout = 5000');
    }

    private function acquireSqliteFileLock(): void
    {
        $path = $this->connection->getParams()['path'] ?? null;
        if (!is_string($path) || $path === '') {
            throw new \RuntimeException('The tenant database path is missing.');
        }
        $handle = fopen(sys_get_temp_dir().'/attendance-'.hash('sha256', $path).'.lock', 'c');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new \RuntimeException('The attendance lock could not be acquired.');
        }
        $this->sqliteLock = $handle;
    }

    private function releaseSqliteFileLock(): void
    {
        if (!is_resource($this->sqliteLock)) {
            return;
        }
        flock($this->sqliteLock, LOCK_UN);
        fclose($this->sqliteLock);
        $this->sqliteLock = null;
    }

    private function lock(string $employeeId): void
    {
        if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->connection->executeStatement(
                'INSERT INTO attendance_guards (employee_id) VALUES (?) ON CONFLICT(employee_id) DO NOTHING',
                [$employeeId],
            );
            $locked = $this->connection->fetchOne(
                'SELECT employee_id FROM attendance_guards WHERE employee_id = ?',
                [$employeeId],
            );
        } else {
            $this->connection->executeStatement(
                'INSERT INTO attendance_guards (employee_id) VALUES (?) ON DUPLICATE KEY UPDATE employee_id = employee_id',
                [$employeeId],
            );
            $locked = $this->connection->fetchOne(
                'SELECT employee_id FROM attendance_guards WHERE employee_id = ? FOR UPDATE',
                [$employeeId],
            );
        }
        if (!is_string($locked) || $locked === '') {
            throw new \RuntimeException('The attendance lock could not be acquired.');
        }
    }

    private function assertEmployeeActive(string $employeeId): void
    {
        $row = $this->connection->fetchAssociative('SELECT status FROM employees WHERE id = ?', [$employeeId]);
        if (!is_array($row)) {
            throw new AttendanceRejectedException('employee_not_found', 'Employee was not found.');
        }
        if (($row['status'] ?? null) !== 'active') {
            throw new AttendanceRejectedException('employee_inactive', 'The employee is inactive.');
        }
    }

    private function eventName(AttendanceAction $action): string
    {
        return match ($action) {
            AttendanceAction::ClockIn => 'EmployeeClockedIn',
            AttendanceAction::LunchStart => 'LunchStarted',
            AttendanceAction::LunchEnd => 'LunchEnded',
            AttendanceAction::ClockOut => 'EmployeeClockedOut',
        };
    }

    private function derive(string $employeeId): AttendanceState
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT event_type FROM attendance_facts WHERE employee_id = ? ORDER BY occurred_at ASC, id ASC',
            [$employeeId],
        );
        $actions = [];
        foreach ($rows as $row) {
            $type = $row['event_type'] ?? null;
            if (!is_string($type)) {
                throw new AttendanceRejectedException('attendance_history_inconsistent', 'An attendance event has no type.');
            }
            $action = AttendanceAction::tryFrom($type);
            if (!$action instanceof AttendanceAction) {
                throw new AttendanceRejectedException('attendance_history_inconsistent', 'An attendance event has an unknown type.');
            }
            $actions[] = $action;
        }

        return AttendanceMachine::reduce($actions);
    }

    private function openSessionId(string $employeeId, AttendanceState $state): ?string
    {
        $open = $this->connection->fetchAssociative(
            'SELECT id, state FROM attendance_sessions WHERE open_employee_id = ?',
            [$employeeId],
        );
        $expectsOpen = $state !== AttendanceState::Off;
        if ($expectsOpen !== is_array($open)) {
            throw new AttendanceRejectedException(
                'attendance_projection_conflict',
                'The attendance session does not match the event history.',
            );
        }
        if (!is_array($open)) {
            return null;
        }
        if (($open['state'] ?? null) !== $state->value || !is_string($open['id'])) {
            throw new AttendanceRejectedException(
                'attendance_projection_conflict',
                'The attendance session does not match the event history.',
            );
        }

        return $open['id'];
    }

    private function photoReference(RecordAttendance $command): ?string
    {
        if ($command->action === AttendanceAction::ClockIn && $command->photoRequired && $command->photoId === null) {
            throw new AttendanceRejectedException('photo_required', 'Clock-in requires a photograph.');
        }
        if ($command->photoId === null) {
            return null;
        }
        $photo = $this->connection->fetchOne(
            'SELECT id FROM attendance_photos WHERE id = ? AND employee_id = ?',
            [$command->photoId, $command->employeeId],
        );
        if (!is_string($photo)) {
            throw new AttendanceRejectedException('photo_not_found', 'The photograph was not found for this employee.');
        }

        return $photo;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function existingEvent(string $deviceId, string $idempotencyKey): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, session_id, employee_id, event_type, occurred_at FROM attendance_events WHERE device_id = ? AND idempotency_key = ?',
            [$deviceId, $idempotencyKey],
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function replay(array $row, RecordAttendance $command): AttendanceCommit
    {
        $type = $row['event_type'] ?? null;
        $id = $row['id'] ?? null;
        $sessionId = $row['session_id'] ?? null;
        $occurred = $row['occurred_at'] ?? null;
        $employeeId = $row['employee_id'] ?? null;
        if (!is_string($type) || !is_string($id) || !is_string($sessionId) || !is_string($occurred) || !is_string($employeeId)) {
            throw new AttendanceRejectedException('attendance_history_inconsistent', 'The stored attendance event is incomplete.');
        }
        if ($type !== $command->action->value || $employeeId !== $command->employeeId) {
            throw new AttendanceRejectedException(
                'idempotency_conflict',
                'This idempotency key was already used for a different attendance action.',
            );
        }
        try {
            $parsed = UtcTime::parseStored($occurred);
        } catch (\InvalidArgumentException) {
            throw new AttendanceRejectedException('attendance_history_inconsistent', 'The stored attendance time is not UTC.');
        }

        return new AttendanceCommit($id, $sessionId, $command->action, $this->derive($employeeId), $parsed, true);
    }

    /**
     * @param array<string, scalar|null> $metadata
     */
    private function metadata(array $metadata): string
    {
        unset(
            $metadata['password'],
            $metadata['passwordHash'],
            $metadata['password_hash'],
            $metadata['attendanceCode'],
            $metadata['code'],
            $metadata['fingerprint'],
            $metadata['template'],
            $metadata['sample'],
            $metadata['biometric'],
            $metadata['image'],
            $metadata['bytes'],
            $metadata['photograph'],
        );

        return json_encode($metadata, JSON_THROW_ON_ERROR);
    }

    private function recordRejection(RecordAttendance $command, AttendanceRejectedException $exception, \DateTimeImmutable $utc): void
    {
        try {
            $this->accounts->audit(
                'attendance.rejected',
                'device',
                $command->deviceId,
                'employee',
                $command->employeeId,
                null,
                [
                    'action' => $command->action->value,
                    'reason' => $exception->reason(),
                ],
                $utc,
            );
        } catch (\Throwable) {
            // A failed audit record must not turn a rejected transition into a success.
        }
    }
}
