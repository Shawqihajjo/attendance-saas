<?php

declare(strict_types=1);

namespace App\Tenant\Attendance;

use Symfony\Component\Uid\Uuid;

final readonly class RecordAttendance
{
    /**
     * @param array<string, scalar|null> $metadata
     */
    public function __construct(
        public string $employeeId,
        public AttendanceAction $action,
        public VerificationMethod $verification,
        public string $deviceId,
        public ?string $photoId,
        public string $idempotencyKey,
        public bool $photoRequired,
        public AttendanceSource $source = AttendanceSource::Terminal,
        public array $metadata = [],
    ) {
        if (!Uuid::isValid($employeeId) || !Uuid::isValid($deviceId)) {
            throw new AttendanceRejectedException('invalid_reference', 'Employee and device identifiers must be UUIDs.');
        }
        if ($photoId !== null && !Uuid::isValid($photoId)) {
            throw new AttendanceRejectedException('invalid_reference', 'The photo reference must be a UUID.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,79}$/', $idempotencyKey) !== 1) {
            throw new AttendanceRejectedException('invalid_idempotency_key', 'The idempotency key is not usable.');
        }
    }
}
