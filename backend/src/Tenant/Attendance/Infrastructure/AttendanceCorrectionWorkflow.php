<?php

declare(strict_types=1);

namespace App\Tenant\Attendance\Infrastructure;

use App\Identity\Infrastructure\Persistence\TenantAccountStore;
use App\Integration\DomainEventPublisher;
use App\Platform\TenantRegistry\Domain\InvalidTenantInputException;
use App\Shared\Time\UtcTime;
use App\Tenant\Attendance\AttendanceAction;
use App\Tenant\Attendance\AttendanceMachine;
use App\Tenant\Attendance\AttendanceRejectedException;
use App\Tenant\Attendance\AttendanceState;
use App\Tenant\Attendance\CorrectionConflictException;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Records a correction request and, on approval, an adjustment.
 * attendance_events rows are never updated or deleted.
 */
final class AttendanceCorrectionWorkflow
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.tenant_connection')]
        private readonly Connection $connection,
        private readonly TenantAccountStore $accounts,
        private readonly DomainEventPublisher $events,
    ) {
    }

    /**
     * @return array{id: string, requestedOccurredAt: string}
     */
    public function submit(
        string $employeeId,
        string $userId,
        string $localDate,
        string $localTime,
        string $actionName,
        string $reason,
        ?string $comment,
        ?string $supportingInformation,
        string $timezone,
        \DateTimeImmutable $now,
    ): array {
        $action = AttendanceAction::fromString($actionName);
        $instant = $this->requestedInstant($localDate, $localTime, $timezone);
        if ($instant > UtcTime::instant($now)) {
            throw new InvalidTenantInputException('The requested time cannot be in the future.');
        }
        $open = $this->connection->fetchOne(
            'SELECT id FROM attendance_correction_requests WHERE employee_id = ? AND local_date = ? AND requested_action = ? AND status IN (\'requested\', \'pending\')',
            [$employeeId, $localDate, $action->value],
        );
        if (is_string($open)) {
            throw new CorrectionConflictException('A correction for this date and action is already open.');
        }
        $day = UtcTime::localDay($localDate, $timezone);
        $this->assertLegal($employeeId, $action, $instant, $day['start'], $day['end']);
        $id = Uuid::v7()->toRfc4122();
        $this->connection->insert('attendance_correction_requests', [
            'id' => $id,
            'employee_id' => $employeeId,
            'requested_by_user_id' => $userId,
            'reason' => $reason,
            'comment' => $comment,
            'supporting_information' => $supportingInformation,
            'local_date' => $localDate,
            'local_time' => $localTime,
            'requested_action' => $action->value,
            'requested_occurred_at' => UtcTime::persist($instant),
            'original_events' => $this->snapshot($employeeId, $day['start'], $day['end']),
            'status' => 'requested',
            'created_at' => UtcTime::persist($now),
        ]);
        $this->events->publish('AttendanceCorrectionRequested', $employeeId, [
            'correctionId' => $id,
            'employeeId' => $employeeId,
            'action' => $action->value,
            'occurredAt' => UtcTime::toIso($instant),
        ], $now);

        return ['id' => $id, 'requestedOccurredAt' => UtcTime::toIso($instant)];
    }

    public function decide(
        string $id,
        string $decision,
        string $reviewerId,
        ?string $decisionComment,
        string $timezone,
        \DateTimeImmutable $now,
    ): ?string {
        try {
            $adjustmentId = $this->connection->transactional(function () use ($id, $decision, $reviewerId, $decisionComment, $timezone, $now): ?string {
                $row = $this->connection->fetchAssociative(
                    'SELECT employee_id, status, local_date, requested_action, requested_occurred_at FROM attendance_correction_requests WHERE id = ?',
                    [$id],
                );
                if (!is_array($row) || !in_array($row['status'] ?? null, ['requested', 'pending'], true)) {
                    throw new CorrectionConflictException('This correction request is already decided.');
                }
                $created = null;
                if ($decision === 'approved') {
                    $created = $this->apply($id, $row, $timezone, $now);
                }
                $this->accounts->decideCorrection($id, $decision, $reviewerId, $decisionComment, $now);

                return $created;
            });
        } catch (AttendanceRejectedException) {
            throw new CorrectionConflictException('The requested change is no longer a legal attendance transition.');
        }
        $this->events->publish(
            $decision === 'approved' ? 'AttendanceCorrectionApproved' : 'AttendanceCorrectionRejected',
            $this->employeeOf($id),
            [
                'correctionId' => $id,
                'decision' => $decision,
                'occurredAt' => UtcTime::toIso($now),
            ],
            $now,
        );

        return $adjustmentId;
    }

    private function employeeOf(string $id): string
    {
        $employeeId = $this->connection->fetchOne('SELECT employee_id FROM attendance_correction_requests WHERE id = ?', [$id]);
        if (!is_string($employeeId) || $employeeId === '') {
            throw new \InvalidArgumentException('The correction request was not stored.');
        }

        return $employeeId;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function apply(string $correctionId, array $row, string $timezone, \DateTimeImmutable $now): ?string
    {
        $employeeId = $row['employee_id'] ?? null;
        $localDate = $row['local_date'] ?? null;
        $actionName = $row['requested_action'] ?? null;
        $occurred = $row['requested_occurred_at'] ?? null;
        if (!is_string($employeeId) || !is_string($localDate) || !is_string($actionName) || !is_string($occurred)) {
            return null;
        }
        $action = AttendanceAction::fromString($actionName);
        $instant = UtcTime::parseStored($occurred);
        $day = UtcTime::localDay($localDate, $timezone);
        $this->assertLegal($employeeId, $action, $instant, $day['start'], $day['end']);
        $id = Uuid::v7()->toRfc4122();
        $this->connection->insert('attendance_adjustments', [
            'id' => $id,
            'correction_id' => $correctionId,
            'employee_id' => $employeeId,
            'event_type' => $action->value,
            'occurred_at' => UtcTime::persist($instant),
            'created_at' => UtcTime::persist($now),
        ]);
        $this->reconcileSession($employeeId, $this->currentState($employeeId), $instant);

        return $id;
    }

    private function currentState(string $employeeId): AttendanceState
    {
        $rows = $this->connection->fetchFirstColumn(
            'SELECT event_type FROM attendance_facts WHERE employee_id = ? ORDER BY occurred_at ASC, id ASC',
            [$employeeId],
        );
        $actions = [];
        foreach ($rows as $type) {
            if (!is_string($type)) {
                throw new \InvalidArgumentException('The stored attendance time is not UTC.');
            }
            $actions[] = AttendanceAction::fromString($type);
        }

        return AttendanceMachine::reduce($actions);
    }

    private function reconcileSession(string $employeeId, AttendanceState $state, \DateTimeImmutable $instant): void
    {
        $open = $this->connection->fetchAssociative(
            'SELECT id, state FROM attendance_sessions WHERE open_employee_id = ?',
            [$employeeId],
        );
        if ($state === AttendanceState::Off) {
            if (!is_array($open) || !is_string($open['id'] ?? null)) {
                return;
            }
            $this->connection->update('attendance_sessions', [
                'state' => AttendanceState::Off->value,
                'closed_at' => UtcTime::persist($instant),
                'open_employee_id' => null,
            ], ['id' => $open['id']]);

            return;
        }
        if (!is_array($open)) {
            $this->connection->insert('attendance_sessions', [
                'id' => Uuid::v7()->toRfc4122(),
                'employee_id' => $employeeId,
                'state' => $state->value,
                'opened_at' => UtcTime::persist($instant),
                'closed_at' => null,
                'open_employee_id' => $employeeId,
            ]);

            return;
        }
        $sessionId = $open['id'] ?? null;
        if (!is_string($sessionId)) {
            throw new \InvalidArgumentException('The attendance session is invalid.');
        }
        if (($open['state'] ?? null) !== $state->value) {
            $this->connection->update('attendance_sessions', [
                'state' => $state->value,
            ], ['id' => $sessionId]);
        }
    }

    private function requestedInstant(string $localDate, string $localTime, string $timezone): \DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $localDate) !== 1) {
            throw new InvalidTenantInputException('The date must be YYYY-MM-DD.');
        }
        if (preg_match('/^\d{2}:\d{2}$/', $localTime) === 1) {
            $localTime .= ':00';
        }
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $localTime) !== 1) {
            throw new InvalidTenantInputException('The time must be HH:MM.');
        }
        $zone = new \DateTimeZone(UtcTime::requireIana($timezone));
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $localDate.' '.$localTime, $zone);
        if (!$parsed instanceof \DateTimeImmutable || $parsed->format('Y-m-d H:i:s') !== $localDate.' '.$localTime) {
            throw new InvalidTenantInputException('The date or time is not a real local time.');
        }

        return UtcTime::instant($parsed);
    }

    private function assertLegal(
        string $employeeId,
        AttendanceAction $action,
        \DateTimeImmutable $instant,
        \DateTimeImmutable $dayStart,
        \DateTimeImmutable $dayEnd,
    ): void {
        $sequence = [];
        $placed = false;
        foreach ($this->facts($employeeId, $dayStart, $dayEnd) as $fact) {
            if (!$placed && $instant < $fact['occurredAt']) {
                $sequence[] = $action;
                $placed = true;
            }
            $existing = AttendanceAction::fromString($fact['eventType']);
            $sequence[] = $existing;
        }
        if (!$placed) {
            $sequence[] = $action;
        }
        $state = AttendanceState::Off;
        foreach ($sequence as $step) {
            $state = $step->next($state);
        }
    }

    /**
     * @return list<array{eventType: string, occurredAt: \DateTimeImmutable}>
     */
    private function facts(string $employeeId, \DateTimeImmutable $dayStart, \DateTimeImmutable $dayEnd): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT e.event_type, e.occurred_at FROM attendance_facts e
             LEFT JOIN (
                SELECT employee_id, MAX(occurred_at) AS closed_at
                FROM attendance_facts
                WHERE event_type = \'clock_out\' AND occurred_at < ? AND employee_id = ?
                GROUP BY employee_id
             ) prior ON prior.employee_id = e.employee_id
             WHERE e.employee_id = ?
               AND e.occurred_at < ?
               AND e.occurred_at > COALESCE(prior.closed_at, \'1970-01-01 00:00:00\')
             ORDER BY e.occurred_at, e.id',
            [UtcTime::persist($dayStart), $employeeId, $employeeId, UtcTime::persist($dayEnd)],
        );
        $facts = [];
        foreach ($rows as $row) {
            $type = $row['event_type'] ?? null;
            $occurred = $row['occurred_at'] ?? null;
            if (!is_string($type) || !is_string($occurred)) {
                throw new \InvalidArgumentException('The stored attendance time is not UTC.');
            }
            $facts[] = ['eventType' => $type, 'occurredAt' => UtcTime::parseStored($occurred)];
        }

        return $facts;
    }

    private function snapshot(string $employeeId, \DateTimeImmutable $dayStart, \DateTimeImmutable $dayEnd): string
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT e.id, e.event_type, e.occurred_at, e.origin FROM attendance_facts e
             LEFT JOIN (
                SELECT employee_id, MAX(occurred_at) AS closed_at
                FROM attendance_facts
                WHERE event_type = \'clock_out\' AND occurred_at < ? AND employee_id = ?
                GROUP BY employee_id
             ) prior ON prior.employee_id = e.employee_id
             WHERE e.employee_id = ?
               AND e.occurred_at < ?
               AND e.occurred_at > COALESCE(prior.closed_at, \'1970-01-01 00:00:00\')
             ORDER BY e.occurred_at, e.id',
            [UtcTime::persist($dayStart), $employeeId, $employeeId, UtcTime::persist($dayEnd)],
        );
        $events = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $type = $row['event_type'] ?? null;
            $occurred = $row['occurred_at'] ?? null;
            $origin = $row['origin'] ?? null;
            if (!is_string($id) || !is_string($type) || !is_string($occurred) || !is_string($origin)) {
                throw new \InvalidArgumentException('The stored attendance time is not UTC.');
            }
            $events[] = [
                'id' => $id,
                'eventType' => $type,
                'occurredAt' => $occurred,
                'origin' => $origin,
            ];
        }

        return json_encode($events, JSON_THROW_ON_ERROR);
    }
}
