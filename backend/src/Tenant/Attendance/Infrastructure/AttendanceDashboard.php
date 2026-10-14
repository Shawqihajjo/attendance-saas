<?php

declare(strict_types=1);

namespace App\Tenant\Attendance\Infrastructure;

use App\Shared\Time\TimeDisplay;
use App\Shared\Time\TimeZoneChoice;
use App\Shared\Time\UtcTime;
use App\Tenant\Attendance\AttendanceDaySummary;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Reads attendance dashboards from the tenant connection already selected for this request.
 */
final class AttendanceDashboard
{
    private const LIST_LIMIT = 20;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.tenant_connection')]
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array{
     *     timezone: string|null,
     *     date: string|null,
     *     state: string,
     *     clockIn: array{occurredAt: string, displayedAt: string}|null,
     *     clockOut: array{occurredAt: string, displayedAt: string}|null,
     *     lunches: list<array{start: array{occurredAt: string, displayedAt: string}, end: array{occurredAt: string, displayedAt: string}|null, seconds: int}>,
     *     workedSeconds: int,
     *     missingClockOut: bool,
     *     history: array{records: list<array{id: string, employeeId: string, eventType: string, deviceId: string|null, occurredAt: string, displayedAt: string, timezone: string}>, page: array{limit: int, offset: int, total: int}}
     * }
     */
    public function blankEmployee(): array
    {
        return [
            'timezone' => null,
            'date' => null,
            'state' => 'off',
            'clockIn' => null,
            'clockOut' => null,
            'lunches' => [],
            'workedSeconds' => 0,
            'missingClockOut' => false,
            'history' => [
                'records' => [],
                'page' => ['limit' => 0, 'offset' => 0, 'total' => 0],
            ],
        ];
    }

    /**
     * @return array{
     *     timezone: string,
     *     date: string,
     *     state: string,
     *     clockIn: array{occurredAt: string, displayedAt: string}|null,
     *     clockOut: array{occurredAt: string, displayedAt: string}|null,
     *     lunches: list<array{start: array{occurredAt: string, displayedAt: string}, end: array{occurredAt: string, displayedAt: string}|null, seconds: int}>,
     *     workedSeconds: int,
     *     missingClockOut: bool,
     *     history: array{records: list<array{id: string, employeeId: string, eventType: string, deviceId: string|null, occurredAt: string, displayedAt: string, timezone: string}>, page: array{limit: int, offset: int, total: int}}
     * }
     */
    public function employee(string $employeeId, TimeZoneChoice $choice, ?string $localDate, ?string $deviceId, \DateTimeImmutable $now, int $limit, int $offset): array
    {
        if (!Uuid::isValid($employeeId)) {
            throw new \InvalidArgumentException('The employee identifier must be a UUID.');
        }
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $zone = $this->zone($choice, $deviceId);
        $date = $this->resolveDate($localDate, $zone, $now);
        $day = UtcTime::localDay($date, $zone);
        $summary = AttendanceDaySummary::summarize(
            $this->dayEventsForEmployee($employeeId, UtcTime::persist($day['start']), UtcTime::persist($day['end'])),
            $day['start'],
            $day['end'],
            $now,
        );
        $lunches = [];
        foreach ($summary['lunches'] as $lunch) {
            $start = $this->stamp($lunch['start'], $zone);
            $end = $this->stamp($lunch['end'], $zone);
            if ($start === null) {
                continue;
            }
            $lunches[] = [
                'start' => $start,
                'end' => $end,
                'seconds' => $lunch['seconds'],
            ];
        }

        return [
            'timezone' => $zone,
            'date' => $date,
            'state' => $summary['state'],
            'clockIn' => $this->stamp($summary['clockIn'], $zone),
            'clockOut' => $this->stamp($summary['clockOut'], $zone),
            'lunches' => $lunches,
            'workedSeconds' => $summary['workedSeconds'],
            'missingClockOut' => $summary['missingClockOut'],
            'history' => $this->history($employeeId, $zone, $limit, $offset),
        ];
    }

    /**
     * @param string|null $managerEmployeeId null is the whole tenant; an empty string is nobody
     *
     * @return array{
     *     timezone: string,
     *     date: string,
     *     counts: array{working: int, onLunch: int, off: int},
     *     working: list<array{id: string, name: string, since: array{occurredAt: string, displayedAt: string}|null}>,
     *     onLunch: list<array{id: string, name: string, since: array{occurredAt: string, displayedAt: string}|null}>,
     *     off: list<array{id: string, name: string, since: array{occurredAt: string, displayedAt: string}|null}>,
     *     exceptions: list<array{id: string, name: string, kind: string, since: array{occurredAt: string, displayedAt: string}|null}>,
     *     exceptionCount: int,
     *     daily: array{records: list<array{employeeId: string, name: string, state: string, clockIn: array{occurredAt: string, displayedAt: string}|null, clockOut: array{occurredAt: string, displayedAt: string}|null, workedSeconds: int, lunchSeconds: int, missingClockOut: bool}>, page: array{limit: int, offset: int, total: int}},
     *     corrections: array{pending: int}
     * }
     */
    public function organization(?string $managerEmployeeId, TimeZoneChoice $choice, ?string $localDate, ?string $deviceId, \DateTimeImmutable $now, int $limit, int $offset): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $zone = $this->zone($choice, $deviceId);
        $date = $this->resolveDate($localDate, $zone, $now);
        if ($managerEmployeeId === '') {
            return $this->emptyOrganization($zone, $date, $limit, $offset);
        }
        if ($managerEmployeeId !== null && !Uuid::isValid($managerEmployeeId)) {
            throw new \InvalidArgumentException('The manager identifier must be a UUID.');
        }
        [$scopeSql, $scopeParams] = $this->scope($managerEmployeeId);
        $day = UtcTime::localDay($date, $zone);
        $people = $this->people($scopeSql, $scopeParams);
        $latest = $this->latestEvents($scopeSql, $scopeParams);
        $events = $this->dayEvents($scopeSql, $scopeParams, UtcTime::persist($day['start']), UtcTime::persist($day['end']));
        $counts = ['working' => 0, 'onLunch' => 0, 'off' => 0];
        $working = [];
        $onLunch = [];
        $off = [];
        foreach ($people as $person) {
            if ($person['status'] !== 'active') {
                continue;
            }
            $event = $latest[$person['id']] ?? null;
            $state = $this->liveState(is_array($event) ? $event['type'] : null);
            $since = is_array($event) ? $this->stamp($event['at'], $zone) : null;
            $bucket = ['id' => $person['id'], 'name' => $person['name'], 'since' => $since];
            if ($state === 'working') {
                ++$counts['working'];
                if (count($working) < self::LIST_LIMIT) {
                    $working[] = $bucket;
                }
            } elseif ($state === 'on_lunch') {
                ++$counts['onLunch'];
                if (count($onLunch) < self::LIST_LIMIT) {
                    $onLunch[] = $bucket;
                }
            } else {
                ++$counts['off'];
                if (count($off) < self::LIST_LIMIT) {
                    $off[] = $bucket;
                }
            }
        }

        $daily = [];
        $exceptions = [];
        foreach ($people as $person) {
            $personEvents = $events[$person['id']] ?? [];
            if ($person['status'] !== 'active' && $personEvents === []) {
                continue;
            }
            $summary = AttendanceDaySummary::summarize($personEvents, $day['start'], $day['end'], $now);
            $daily[] = [
                'employeeId' => $person['id'],
                'name' => $person['name'],
                'state' => $summary['state'],
                'clockIn' => $this->stamp($summary['clockIn'], $zone),
                'clockOut' => $this->stamp($summary['clockOut'], $zone),
                'workedSeconds' => $summary['workedSeconds'],
                'lunchSeconds' => $summary['lunchSeconds'],
                'missingClockOut' => $summary['missingClockOut'],
            ];
            if ($summary['missingClockOut'] && count($exceptions) < self::LIST_LIMIT) {
                $exceptions[] = [
                    'id' => $person['id'],
                    'name' => $person['name'],
                    'kind' => 'missing_clock_out',
                    'since' => $this->stamp($summary['clockIn'], $zone),
                ];
            }
        }
        $exceptionCount = 0;
        foreach ($daily as $row) {
            if ($row['missingClockOut']) {
                ++$exceptionCount;
            }
        }

        return [
            'timezone' => $zone,
            'date' => $date,
            'counts' => $counts,
            'working' => $working,
            'onLunch' => $onLunch,
            'off' => $off,
            'exceptions' => $exceptions,
            'exceptionCount' => $exceptionCount,
            'daily' => [
                'records' => array_slice($daily, $offset, $limit),
                'page' => ['limit' => $limit, 'offset' => $offset, 'total' => count($daily)],
            ],
            'corrections' => ['pending' => $this->pendingCorrections($scopeSql, $scopeParams)],
        ];
    }

    /**
     * @param list<string> $scopeParams
     *
     * @return list<array{id: string, name: string, status: string}>
     */
    private function people(string $scopeSql, array $scopeParams): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, display_name, status FROM employees WHERE id IN ('.$scopeSql.') ORDER BY display_name, id',
            $scopeParams,
        );
        $people = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $name = $row['display_name'] ?? null;
            $status = $row['status'] ?? null;
            if (!is_string($id) || !is_string($name) || !is_string($status)) {
                throw new \InvalidArgumentException('The stored employee is invalid.');
            }
            $people[] = ['id' => $id, 'name' => $name, 'status' => $status];
        }

        return $people;
    }

    /**
     * @param list<string> $scopeParams
     *
     * @return array<string, array{type: string, at: \DateTimeImmutable}>
     */
    private function latestEvents(string $scopeSql, array $scopeParams): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT employee_id, event_type, occurred_at FROM (
                SELECT employee_id, event_type, occurred_at, id,
                       ROW_NUMBER() OVER (PARTITION BY employee_id ORDER BY occurred_at DESC, id DESC) AS rn
                FROM attendance_facts
                WHERE employee_id IN ('.$scopeSql.')
            ) ranked WHERE rn = 1',
            $scopeParams,
        );
        $latest = [];
        foreach ($rows as $row) {
            $employeeId = $row['employee_id'] ?? null;
            $type = $row['event_type'] ?? null;
            $occurred = $row['occurred_at'] ?? null;
            if (!is_string($employeeId) || !is_string($type) || !is_string($occurred)) {
                throw new \InvalidArgumentException('The stored attendance time is not UTC.');
            }
            $latest[$employeeId] = ['type' => $type, 'at' => UtcTime::parseStored($occurred)];
        }

        return $latest;
    }

    /**
     * @return list<array{eventType: string, occurredAt: \DateTimeImmutable}>
     */
    private function dayEventsForEmployee(string $employeeId, string $dayStart, string $dayEnd): array
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
            [$dayStart, $employeeId, $employeeId, $dayEnd],
        );

        return $this->eventList($rows);
    }

    /**
     * @param list<string> $scopeParams
     *
     * @return array<string, list<array{eventType: string, occurredAt: \DateTimeImmutable}>>
     */
    private function dayEvents(string $scopeSql, array $scopeParams, string $dayStart, string $dayEnd): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ev.employee_id, ev.event_type, ev.occurred_at FROM attendance_facts ev
             LEFT JOIN (
                SELECT employee_id, MAX(occurred_at) AS closed_at
                FROM attendance_facts
                WHERE event_type = \'clock_out\' AND occurred_at < ? AND employee_id IN ('.$scopeSql.')
                GROUP BY employee_id
             ) prior ON prior.employee_id = ev.employee_id
             WHERE ev.occurred_at < ?
               AND ev.occurred_at > COALESCE(prior.closed_at, \'1970-01-01 00:00:00\')
               AND ev.employee_id IN ('.$scopeSql.')
             ORDER BY ev.employee_id, ev.occurred_at, ev.id',
            array_merge([$dayStart], $scopeParams, [$dayEnd], $scopeParams),
        );
        $grouped = [];
        foreach ($rows as $row) {
            $employeeId = $row['employee_id'] ?? null;
            $type = $row['event_type'] ?? null;
            $occurred = $row['occurred_at'] ?? null;
            if (!is_string($employeeId) || !is_string($type) || !is_string($occurred)) {
                throw new \InvalidArgumentException('The stored attendance time is not UTC.');
            }
            $grouped[$employeeId][] = ['eventType' => $type, 'occurredAt' => UtcTime::parseStored($occurred)];
        }

        return $grouped;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{eventType: string, occurredAt: \DateTimeImmutable}>
     */
    private function eventList(array $rows): array
    {
        $events = [];
        foreach ($rows as $row) {
            $type = $row['event_type'] ?? null;
            $occurred = $row['occurred_at'] ?? null;
            if (!is_string($type) || !is_string($occurred)) {
                throw new \InvalidArgumentException('The stored attendance time is not UTC.');
            }
            $events[] = ['eventType' => $type, 'occurredAt' => UtcTime::parseStored($occurred)];
        }

        return $events;
    }

    /**
     * @return array{records: list<array{id: string, employeeId: string, eventType: string, deviceId: string|null, occurredAt: string, displayedAt: string, timezone: string}>, page: array{limit: int, offset: int, total: int}}
     */
    private function history(string $employeeId, string $zone, int $limit, int $offset): array
    {
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM attendance_facts WHERE employee_id = ?', [$employeeId]);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, employee_id, event_type, occurred_at, device_id FROM attendance_facts WHERE employee_id = ? ORDER BY occurred_at DESC, id DESC LIMIT '.$limit.' OFFSET '.$offset,
            [$employeeId],
        );
        $records = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $owner = $row['employee_id'] ?? null;
            $type = $row['event_type'] ?? null;
            $occurred = $row['occurred_at'] ?? null;
            if (!is_string($id) || !is_string($owner) || !is_string($type) || !is_string($occurred)) {
                throw new \InvalidArgumentException('The stored attendance time is not UTC.');
            }
            $instant = UtcTime::parseStored($occurred);
            $records[] = [
                'id' => $id,
                'employeeId' => $owner,
                'eventType' => $type,
                'deviceId' => is_string($row['device_id'] ?? null) ? $row['device_id'] : null,
                'occurredAt' => UtcTime::toIso($instant),
                'displayedAt' => UtcTime::present($instant, $zone),
                'timezone' => $zone,
            ];
        }

        return [
            'records' => $records,
            'page' => ['limit' => $limit, 'offset' => $offset, 'total' => $total],
        ];
    }

    /**
     * @param list<string> $scopeParams
     */
    private function pendingCorrections(string $scopeSql, array $scopeParams): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM attendance_correction_requests WHERE status IN (\'requested\', \'pending\') AND employee_id IN ('.$scopeSql.')',
            $scopeParams,
        );
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function scope(?string $managerEmployeeId): array
    {
        if ($managerEmployeeId === null) {
            return ['SELECT id FROM employees', []];
        }

        return ['SELECT employee_id FROM manager_assignments WHERE manager_employee_id = ?', [$managerEmployeeId]];
    }

    private function liveState(?string $eventType): string
    {
        return match ($eventType) {
            'clock_in', 'lunch_end' => 'working',
            'lunch_start' => 'on_lunch',
            default => 'off',
        };
    }

    /**
     * @return array{occurredAt: string, displayedAt: string}|null
     */
    private function stamp(?\DateTimeImmutable $instant, string $zone): ?array
    {
        if (!$instant instanceof \DateTimeImmutable) {
            return null;
        }

        return [
            'occurredAt' => UtcTime::toIso($instant),
            'displayedAt' => UtcTime::present($instant, $zone),
        ];
    }

    private function zone(TimeZoneChoice $choice, ?string $deviceId): string
    {
        if ($choice->display !== TimeDisplay::Terminal) {
            return $choice->resolved();
        }
        if ($deviceId === null || !Uuid::isValid($deviceId)) {
            throw new \InvalidArgumentException('A terminal is required for that timezone.');
        }
        $stored = $this->connection->fetchOne('SELECT timezone FROM devices WHERE id = ?', [$deviceId]);
        if (!is_string($stored) || $stored === '') {
            throw new \InvalidArgumentException('The terminal was not found.');
        }

        return UtcTime::requireIana($stored);
    }

    private function resolveDate(?string $localDate, string $zone, \DateTimeImmutable $now): string
    {
        if ($localDate === null) {
            return substr(UtcTime::present($now, $zone), 0, 10);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $localDate)) {
            throw new \InvalidArgumentException('The local date must be YYYY-MM-DD.');
        }

        return $localDate;
    }

    /**
     * @return array{
     *     timezone: string,
     *     date: string,
     *     counts: array{working: int, onLunch: int, off: int},
     *     working: list<array{id: string, name: string, since: array{occurredAt: string, displayedAt: string}|null}>,
     *     onLunch: list<array{id: string, name: string, since: array{occurredAt: string, displayedAt: string}|null}>,
     *     off: list<array{id: string, name: string, since: array{occurredAt: string, displayedAt: string}|null}>,
     *     exceptions: list<array{id: string, name: string, kind: string, since: array{occurredAt: string, displayedAt: string}|null}>,
     *     exceptionCount: int,
     *     daily: array{records: list<array{employeeId: string, name: string, state: string, clockIn: array{occurredAt: string, displayedAt: string}|null, clockOut: array{occurredAt: string, displayedAt: string}|null, workedSeconds: int, lunchSeconds: int, missingClockOut: bool}>, page: array{limit: int, offset: int, total: int}},
     *     corrections: array{pending: int}
     * }
     */
    private function emptyOrganization(string $zone, string $date, int $limit, int $offset): array
    {
        return [
            'timezone' => $zone,
            'date' => $date,
            'counts' => ['working' => 0, 'onLunch' => 0, 'off' => 0],
            'working' => [],
            'onLunch' => [],
            'off' => [],
            'exceptions' => [],
            'exceptionCount' => 0,
            'daily' => [
                'records' => [],
                'page' => ['limit' => $limit, 'offset' => $offset, 'total' => 0],
            ],
            'corrections' => ['pending' => 0],
        ];
    }
}
