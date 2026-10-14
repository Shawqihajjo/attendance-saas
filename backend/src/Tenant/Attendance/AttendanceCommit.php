<?php

declare(strict_types=1);

namespace App\Tenant\Attendance;

final readonly class AttendanceCommit
{
    public function __construct(
        public string $eventId,
        public string $sessionId,
        public AttendanceAction $action,
        public AttendanceState $state,
        public \DateTimeImmutable $occurredAt,
        public bool $replayed,
    ) {
    }
}
