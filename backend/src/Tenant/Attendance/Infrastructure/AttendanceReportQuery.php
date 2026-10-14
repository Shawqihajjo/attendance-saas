<?php

declare(strict_types=1);

namespace App\Tenant\Attendance\Infrastructure;

use Symfony\Component\Uid\Uuid;

/**
 * Filters for one attendance report. A tenant id is not a filter.
 */
final readonly class AttendanceReportQuery
{
    private const EVENT_TYPES = ['clock_in', 'clock_out', 'lunch_start', 'lunch_end'];

    private const STATUSES = ['off', 'working', 'on_lunch'];

    public function __construct(
        public ?string $employeeId,
        public ?string $teamId,
        public ?string $departmentId,
        public ?string $deviceId,
        public ?string $localDate,
        public ?string $from,
        public ?string $to,
        public ?string $eventType,
        public ?string $status,
        public int $limit,
        public int $offset,
    ) {
        $this->uuid($employeeId, 'The employee identifier must be a UUID.');
        $this->uuid($teamId, 'The team identifier must be a UUID.');
        $this->uuid($departmentId, 'The department identifier must be a UUID.');
        $this->uuid($deviceId, 'The terminal identifier must be a UUID.');
        if ($eventType !== null && !in_array($eventType, self::EVENT_TYPES, true)) {
            throw new \InvalidArgumentException('The event type is invalid.');
        }
        if ($status !== null && !in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('The attendance status is invalid.');
        }
        foreach ([$localDate, $from, $to] as $date) {
            if ($date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new \InvalidArgumentException('The local date must be YYYY-MM-DD.');
            }
        }
        if (($from === null) !== ($to === null)) {
            throw new \InvalidArgumentException('A date range needs both dates.');
        }
        if ($from !== null && $to !== null) {
            if ($localDate !== null) {
                throw new \InvalidArgumentException('Use either a date or a date range.');
            }
            if ($from > $to) {
                throw new \InvalidArgumentException('The date range is invalid.');
            }
            $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $from, new \DateTimeZone('UTC'));
            $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $to, new \DateTimeZone('UTC'));
            if (!$start instanceof \DateTimeImmutable || !$end instanceof \DateTimeImmutable) {
                throw new \InvalidArgumentException('The local date must be YYYY-MM-DD.');
            }
            if ($start->diff($end)->days > 366) {
                throw new \InvalidArgumentException('The date range is too long.');
            }
        }
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 100000) {
            throw new \InvalidArgumentException('The page request is invalid.');
        }
    }

    private function uuid(?string $value, string $message): void
    {
        if ($value !== null && !Uuid::isValid($value)) {
            throw new \InvalidArgumentException($message);
        }
    }
}
