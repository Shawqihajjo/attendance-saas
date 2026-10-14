<?php

declare(strict_types=1);

namespace App\Tenant\Attendance\Infrastructure;

use App\Identity\Infrastructure\Persistence\TenantAccountStore;
use App\Platform\Device\DeviceAttendancePolicy;
use App\Platform\Device\DeviceCall;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\Shared\Time\UtcTime;
use App\Tenant\Attendance\AllowedAttendanceActions;
use App\Tenant\Attendance\AttendanceAction;
use App\Tenant\Attendance\AttendanceRejectedException;
use App\Tenant\Attendance\AttendanceSource;
use App\Tenant\Attendance\FingerprintMatcher;
use App\Tenant\Attendance\RecordAttendance;
use App\Tenant\Attendance\TerminalRejectedException;
use App\Tenant\Attendance\VerificationMethod;
use App\Tenant\Directory\AttendanceIdentifierIssuer;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Opens a terminal attempt from an attendance code, then accepts only the actions
 * the current attendance state allows. The caller does not supply the current state.
 */
final class TerminalAttemptService
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.tenant_connection')]
        private readonly Connection $connection,
        private readonly AttendanceIdentifierIssuer $identifiers,
        private readonly AttendanceLedger $ledger,
        private readonly FingerprintMatcher $fingerprints,
        private readonly PhotoStore $photos,
        private readonly TenantRegistryStore $registry,
        private readonly TenantAccountStore $audit,
        private readonly DeviceAttendancePolicy $devicePolicy,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function open(string $platformDeviceId, string $tenantId, string $code, \DateTimeImmutable $now, ?string $requestId, ?DeviceCall $call = null): array
    {
        $this->devicePolicy->assert($tenantId, $platformDeviceId, $call ?? new DeviceCall(null, null, null));
        $projection = $this->projection($platformDeviceId);
        $normalized = strtoupper(str_replace(['-', ' '], '', trim($code)));
        if (preg_match('/^[A-Z0-9]{10}$/', $normalized) !== 1) {
            $this->reject($platformDeviceId, $platformDeviceId, 'unknown_code', 'The attendance code was not recognized.', $now, $requestId);
        }
        $row = $this->connection->fetchAssociative(
            'SELECT e.id, e.display_name, e.employee_number, e.status
             FROM employee_codes c
             INNER JOIN employees e ON e.id = c.employee_id
             WHERE c.code = ?',
            [$this->identifiers->hash($code)],
        );
        if (!is_array($row) || !is_string($row['id'])) {
            $this->reject($platformDeviceId, $platformDeviceId, 'unknown_code', 'The attendance code was not recognized.', $now, $requestId);
        }
        if (($row['status'] ?? null) !== 'active') {
            $this->reject($platformDeviceId, (string) $row['id'], 'employee_inactive', 'This employee cannot record attendance.', $now, $requestId);
        }
        $attemptId = Uuid::v7()->toRfc4122();
        $expires = $now->modify('+2 minutes');
        $this->connection->insert('attendance_attempts', [
            'id' => $attemptId,
            'employee_id' => $row['id'],
            'device_id' => $projection['id'],
            'status' => 'open',
            'created_at' => UtcTime::persist($now),
            'expires_at' => UtcTime::persist($expires),
        ]);
        $this->audit->audit('terminal.attempt_opened', 'device', $platformDeviceId, 'attendance_attempt', $attemptId, $requestId, [
            'employeeId' => $row['id'],
        ], $now);

        return [
            'attemptId' => $attemptId,
            'expiresAt' => UtcTime::toIso($expires),
            'terminalTimezone' => $projection['timezone'],
            'employee' => [
                'displayName' => (string) $row['display_name'],
                'employeeNumber' => is_string($row['employee_number']) ? $row['employee_number'] : '',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyFingerprint(string $platformDeviceId, string $tenantId, string $attemptId, string $vendor, string $sample, \DateTimeImmutable $now, ?string $requestId): array
    {
        $attempt = $this->attempt($platformDeviceId, $attemptId, $now);
        if (($attempt['status'] ?? null) !== 'open' && ($attempt['status'] ?? null) !== 'verified') {
            throw new TerminalRejectedException('attempt_closed', 'This terminal attempt is already finished.');
        }
        $verification = $this->fingerprints->verify((string) $attempt['employee_id'], $vendor, $sample);
        if (!$verification->accepted) {
            $this->audit->audit('terminal.fingerprint_failed', 'device', $platformDeviceId, 'attendance_attempt', $attemptId, $requestId, [
                'employeeId' => $attempt['employee_id'],
                'reason' => $verification->reason,
            ], $now);
            throw new TerminalRejectedException($verification->reason, 'Fingerprint verification failed.');
        }
        $this->connection->update('attendance_attempts', [
            'status' => 'verified',
            'verification_id' => $verification->verificationId,
        ], ['id' => $attemptId]);
        $state = $this->ledger->currentState((string) $attempt['employee_id']);

        return [
            'verified' => true,
            'state' => $state->value,
            'allowedActions' => AllowedAttendanceActions::forState($state, $this->photoRequired($tenantId)),
        ];
    }

    /**
     * @return array{photoId: string}
     */
    public function storePhoto(string $platformDeviceId, string $tenantId, string $attemptId, string $contentType, string $bytes, \DateTimeImmutable $now): array
    {
        $attempt = $this->attempt($platformDeviceId, $attemptId, $now);
        if (($attempt['status'] ?? null) !== 'verified' || !is_string($attempt['verification_id']) || $attempt['verification_id'] === '') {
            throw new TerminalRejectedException('fingerprint_required', 'Fingerprint verification is required before a photograph.');
        }
        $photoId = $this->photos->store(
            $tenantId,
            (string) $attempt['employee_id'],
            $contentType,
            $bytes,
            $now,
            $this->registry->findSettings($tenantId)?->photoRetentionDays(),
        );
        $this->connection->update('attendance_attempts', ['photo_id' => $photoId], ['id' => $attemptId]);

        return ['photoId' => $photoId];
    }

    /**
     * @return array<string, mixed>
     */
    public function commit(string $platformDeviceId, string $tenantId, string $attemptId, string $actionName, string $idempotencyKey, \DateTimeImmutable $now, ?string $requestId, ?DeviceCall $call = null): array
    {
        $this->devicePolicy->assert($tenantId, $platformDeviceId, $call ?? new DeviceCall(null, null, null));
        $attempt = $this->attempt($platformDeviceId, $attemptId, $now);
        if (($attempt['status'] ?? null) === 'committed' && is_string($attempt['event_id'])) {
            return $this->committed($attempt, $actionName);
        }
        if (($attempt['status'] ?? null) !== 'verified' || !is_string($attempt['verification_id']) || $attempt['verification_id'] === '') {
            throw new TerminalRejectedException('fingerprint_required', 'Fingerprint verification is required before attendance is recorded.');
        }
        $action = AttendanceAction::fromString($actionName);
        $photoRequired = $this->photoRequired($tenantId);
        $allowed = AllowedAttendanceActions::forState($this->ledger->currentState((string) $attempt['employee_id']), $photoRequired);
        $permitted = null;
        foreach ($allowed as $candidate) {
            if ($candidate['action'] === $action->value) {
                $permitted = $candidate;
            }
        }
        if ($permitted === null) {
            throw new TerminalRejectedException('illegal_transition', 'The terminal cannot record that attendance action.', 409);
        }
        $photoId = is_string($attempt['photo_id']) && $attempt['photo_id'] !== '' ? $attempt['photo_id'] : null;
        if ($permitted['photoRequired'] && $photoId === null) {
            throw new TerminalRejectedException('photo_required', 'Clock-in requires a photograph.');
        }
        try {
            $commit = $this->ledger->commit(new RecordAttendance(
                (string) $attempt['employee_id'],
                $action,
                VerificationMethod::Fingerprint,
                (string) $attempt['device_id'],
                $photoId,
                $idempotencyKey,
                $photoRequired,
                AttendanceSource::Terminal,
            ), $now);
        } catch (AttendanceRejectedException $exception) {
            $this->audit->audit('terminal.commit_rejected', 'device', $platformDeviceId, 'attendance_attempt', $attemptId, $requestId, [
                'employeeId' => $attempt['employee_id'],
                'reason' => $exception->reason(),
            ], $now);
            throw new TerminalRejectedException($exception->reason(), $exception->getMessage(), 409);
        }
        $this->connection->update('attendance_attempts', [
            'status' => 'committed',
            'event_id' => $commit->eventId,
        ], ['id' => $attemptId]);

        return [
            'eventId' => $commit->eventId,
            'action' => $commit->action->value,
            'state' => $commit->state->value,
            'occurredAt' => UtcTime::toIso($commit->occurredAt),
            'replayed' => $commit->replayed,
        ];
    }

    /**
     * @param array<string, mixed> $attempt
     *
     * @return array<string, mixed>
     */
    private function committed(array $attempt, string $actionName): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT event_type, occurred_at FROM attendance_events WHERE id = ? AND employee_id = ?',
            [$attempt['event_id'], $attempt['employee_id']],
        );
        if (!is_array($row) || !is_string($row['occurred_at']) || !is_string($row['event_type'])) {
            throw new TerminalRejectedException('attempt_closed', 'This terminal attempt is already finished.');
        }
        if ($row['event_type'] !== $actionName) {
            throw new TerminalRejectedException('idempotency_conflict', 'This terminal attempt was already committed.', 409);
        }
        $state = $this->ledger->currentState((string) $attempt['employee_id']);

        return [
            'eventId' => $attempt['event_id'],
            'action' => $row['event_type'],
            'state' => $state->value,
            'occurredAt' => UtcTime::toIso(UtcTime::parseStored($row['occurred_at'])),
            'replayed' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attempt(string $platformDeviceId, string $attemptId, \DateTimeImmutable $now): array
    {
        if (!Uuid::isValid($attemptId)) {
            throw new TerminalRejectedException('attempt_not_found', 'The terminal attempt was not found.', 404);
        }
        $projection = $this->projection($platformDeviceId);
        $attempt = $this->connection->fetchAssociative(
            'SELECT id, employee_id, device_id, status, expires_at, verification_id, photo_id, event_id
             FROM attendance_attempts WHERE id = ?',
            [$attemptId],
        );
        if (!is_array($attempt) || ($attempt['device_id'] ?? null) !== $projection['id']) {
            throw new TerminalRejectedException('attempt_not_found', 'The terminal attempt was not found.', 404);
        }
        $expires = $attempt['expires_at'] ?? null;
        if (!is_string($expires) || UtcTime::parseStored($expires) < UtcTime::instant($now)) {
            throw new TerminalRejectedException('attempt_expired', 'The terminal attempt expired.');
        }

        return $attempt;
    }

    /**
     * @return array{id: string, timezone: string}
     */
    private function projection(string $platformDeviceId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, timezone FROM devices WHERE platform_device_id = ?',
            [$platformDeviceId],
        );
        if (!is_array($row) || !is_string($row['id']) || !is_string($row['timezone'])) {
            throw new TerminalRejectedException('terminal_not_ready', 'This terminal is not ready for attendance.', 409);
        }

        return ['id' => $row['id'], 'timezone' => $row['timezone']];
    }

    private function photoRequired(string $tenantId): bool
    {
        $settings = $this->registry->findSettings($tenantId);

        return $settings !== null && $settings->photoRequired();
    }

    private function reject(string $actorId, string $targetId, string $reason, string $message, \DateTimeImmutable $now, ?string $requestId): never
    {
        $this->audit->audit('terminal.attempt_rejected', 'device', $actorId, 'device', $targetId, $requestId, [
            'reason' => $reason,
        ], $now);
        throw new TerminalRejectedException($reason, $message);
    }
}
