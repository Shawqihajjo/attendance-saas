<?php

declare(strict_types=1);

namespace App\Tenant\Attendance\Infrastructure;

use App\Shared\Time\TimeDisplay;
use App\Shared\Time\TimeZoneChoice;
use App\Shared\Time\UtcTime;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Reads stored UTC attendance instants and formats them for one display timezone.
 * A caller-supplied clock time is not accepted.
 */
final class AttendanceTimeReport
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.tenant_connection')]
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param string|null $managerEmployeeId null lists the tenant; an empty string lists nobody
     *
     * @return array{display: string, timezone: string|null, records: list<array{id: string, employeeId: string, eventType: string, deviceId: string|null, occurredAt: string, displayedAt: string, timezone: string}>, page: array{limit: int, offset: int, total: int}}
     */
    public function present(TimeZoneChoice $choice, ?string $managerEmployeeId, ?string $localDate, ?string $deviceId, ?string $employeeId = null, int $limit = 100, int $offset = 0): array
    {
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 100000) {
            throw new \InvalidArgumentException('The page request is invalid.');
        }
        if ($deviceId !== null && !Uuid::isValid($deviceId)) {
            throw new \InvalidArgumentException('The terminal identifier must be a UUID.');
        }
        if ($employeeId !== null && !Uuid::isValid($employeeId)) {
            throw new \InvalidArgumentException('The employee identifier must be a UUID.');
        }
        $shared = $choice->display === TimeDisplay::Terminal
            ? ($deviceId !== null ? $this->deviceZone($deviceId) : null)
            : $choice->resolved();
        $page = ['limit' => $limit, 'offset' => $offset, 'total' => 0];
        if ($managerEmployeeId === '') {
            return [
                'display' => $choice->display->value,
                'timezone' => $shared,
                'records' => [],
                'page' => $page,
            ];
        }

        $where = [];
        $params = [];
        if ($managerEmployeeId !== null) {
            $where[] = 'employee_id IN (SELECT employee_id FROM manager_assignments WHERE manager_employee_id = ?)';
            $params[] = $managerEmployeeId;
        }
        if ($deviceId !== null) {
            $where[] = 'device_id = ?';
            $params[] = $deviceId;
        }
        if ($employeeId !== null) {
            $where[] = 'employee_id = ?';
            $params[] = $employeeId;
        }
        if ($localDate !== null && $shared !== null) {
            $day = UtcTime::localDay($localDate, $shared);
            $where[] = 'occurred_at >= ? AND occurred_at < ?';
            $params[] = UtcTime::persist($day['start']);
            $params[] = UtcTime::persist($day['end']);
        } elseif ($localDate !== null) {
            [$start, $end] = $this->wideBounds($localDate, $localDate);
            $where[] = 'occurred_at >= ? AND occurred_at < ?';
            $params[] = $start;
            $params[] = $end;
        }
        $sql = 'SELECT id, employee_id, event_type, occurred_at, device_id FROM attendance_facts';
        if ($where !== []) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }

        if ($localDate !== null && $shared === null) {
            $records = $this->facts($sql.' ORDER BY occurred_at, id', $params, $shared, $choice, $localDate);

            return [
                'display' => $choice->display->value,
                'timezone' => $shared,
                'records' => array_slice($records, $offset, $limit),
                'page' => ['limit' => $limit, 'offset' => $offset, 'total' => count($records)],
            ];
        }

        $countSql = 'SELECT COUNT(*) FROM attendance_facts';
        if ($where !== []) {
            $countSql .= ' WHERE '.implode(' AND ', $where);
        }
        $total = (int) $this->connection->fetchOne($countSql, $params);
        $order = $localDate === null ? ' ORDER BY occurred_at DESC, id DESC' : ' ORDER BY occurred_at, id';
        $records = $this->facts($sql.$order.' LIMIT '.$limit.' OFFSET '.$offset, $params, $shared, $choice, null);
        if ($localDate === null) {
            $records = array_reverse($records);
        }

        return [
            'display' => $choice->display->value,
            'timezone' => $shared,
            'records' => $records,
            'page' => ['limit' => $limit, 'offset' => $offset, 'total' => $total],
        ];
    }

    /**
     * @param list<string> $params
     *
     * @return list<array{id: string, employeeId: string, eventType: string, deviceId: string|null, occurredAt: string, displayedAt: string, timezone: string}>
     */
    private function facts(string $sql, array $params, ?string $shared, TimeZoneChoice $choice, ?string $localDate): array
    {
        /** @var array<string, string> $deviceZones */
        $deviceZones = [];
        /** @var array<string, array{start: \DateTimeImmutable, end: \DateTimeImmutable}> $days */
        $days = [];
        $records = [];
        foreach ($this->connection->fetchAllAssociative($sql, $params) as $row) {
            $occurred = $row['occurred_at'] ?? null;
            $id = $row['id'] ?? null;
            $owner = $row['employee_id'] ?? null;
            $eventType = $row['event_type'] ?? null;
            if (!is_string($occurred) || !is_string($id) || !is_string($owner) || !is_string($eventType)) {
                throw new \InvalidArgumentException('The stored attendance time is not UTC.');
            }
            $rowDevice = is_string($row['device_id'] ?? null) ? $row['device_id'] : null;
            $zone = $shared ?? ($rowDevice !== null ? $this->rememberedDeviceZone($rowDevice, $deviceZones) : UtcTime::requireIana($choice->tenantTimeZone));
            $instant = UtcTime::parseStored($occurred);
            if ($localDate !== null && !$this->insideLocalDay($instant, $localDate, $zone, $days)) {
                continue;
            }
            $records[] = [
                'id' => $id,
                'employeeId' => $owner,
                'eventType' => $eventType,
                'deviceId' => $rowDevice,
                'occurredAt' => UtcTime::toIso($instant),
                'displayedAt' => UtcTime::present($instant, $zone),
                'timezone' => $zone,
            ];
        }

        return $records;
    }

    /**
     * @param array<string, string> $deviceZones
     */
    private function rememberedDeviceZone(?string $deviceId, array &$deviceZones): string
    {
        if ($deviceId === null) {
            throw new \InvalidArgumentException('The terminal was not found.');
        }
        if (!isset($deviceZones[$deviceId])) {
            $deviceZones[$deviceId] = $this->deviceZone($deviceId);
        }

        return $deviceZones[$deviceId];
    }

    private function deviceZone(string $deviceId): string
    {
        $stored = $this->connection->fetchOne('SELECT timezone FROM devices WHERE id = ?', [$deviceId]);
        if (!is_string($stored) || $stored === '') {
            throw new \InvalidArgumentException('The terminal was not found.');
        }

        return UtcTime::requireIana($stored);
    }

    /**
     * @param array<string, array{start: \DateTimeImmutable, end: \DateTimeImmutable}> $days
     */
    private function insideLocalDay(\DateTimeImmutable $instant, string $localDate, string $zone, array &$days): bool
    {
        if (!isset($days[$zone])) {
            $days[$zone] = UtcTime::localDay($localDate, $zone);
        }
        $day = $days[$zone];

        return $instant >= $day['start'] && $instant < $day['end'];
    }

    /**
     * @param string|null $managerEmployeeId null lists the tenant; an empty string lists nobody
     *
     * @return array{
     *     display: string,
     *     timezone: string|null,
     *     records: list<array{id: string, employeeId: string, employeeName: string|null, eventType: string, deviceId: string|null, occurredAt: string, displayedAt: string, timezone: string}>,
     *     page: array{limit: int, offset: int, total: int}
     * }
     */
    public function search(TimeZoneChoice $choice, ?string $managerEmployeeId, AttendanceReportQuery $query): array
    {
        $shared = $choice->display === TimeDisplay::Terminal
            ? ($query->deviceId !== null ? $this->deviceZone($query->deviceId) : null)
            : $choice->resolved();
        if ($managerEmployeeId === '') {
            return [
                'display' => $choice->display->value,
                'timezone' => $shared,
                'records' => [],
                'page' => ['limit' => $query->limit, 'offset' => $query->offset, 'total' => 0],
            ];
        }

        $from = $query->from ?? $query->localDate;
        $to = $query->to ?? $query->localDate;
        $bounds = null;
        if ($from !== null && $to !== null) {
            $bounds = $shared !== null
                ? [UtcTime::persist(UtcTime::localDay($from, $shared)['start']), UtcTime::persist(UtcTime::localDay($to, $shared)['end'])]
                : $this->wideBounds($from, $to);
        }
        $statusZone = $shared ?? UtcTime::requireIana($choice->tenantTimeZone);
        [$where, $params] = $this->constraints($managerEmployeeId, $query, $bounds, $statusZone);
        $sql = 'SELECT ev.id, ev.employee_id, ev.event_type, ev.occurred_at, ev.device_id, emp.display_name FROM attendance_facts ev LEFT JOIN employees emp ON emp.id = ev.employee_id';
        if ($where !== []) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }
        $sql .= ' ORDER BY ev.occurred_at DESC, ev.id DESC';

        if ($shared !== null || $from === null) {
            $countSql = 'SELECT COUNT(*) FROM attendance_facts ev';
            if ($where !== []) {
                $countSql .= ' WHERE '.implode(' AND ', $where);
            }
            $total = (int) $this->connection->fetchOne($countSql, $params);
            $rows = $this->connection->fetchAllAssociative($sql.' LIMIT '.$query->limit.' OFFSET '.$query->offset, $params);
            $records = [];
            $deviceZones = [];
            foreach ($rows as $row) {
                $records[] = $this->searchRecord($row, $shared, $deviceZones, $choice->tenantTimeZone);
            }

            return [
                'display' => $choice->display->value,
                'timezone' => $shared,
                'records' => $records,
                'page' => ['limit' => $query->limit, 'offset' => $query->offset, 'total' => $total],
            ];
        }

        if (!is_string($to)) {
            throw new \InvalidArgumentException('A date range needs both dates.');
        }
        /** @var array<string, string> $deviceZones */
        $deviceZones = [];
        $records = [];
        foreach ($this->connection->fetchAllAssociative($sql, $params) as $row) {
            $record = $this->searchRecord($row, null, $deviceZones, $choice->tenantTimeZone);
            $local = substr($record['displayedAt'], 0, 10);
            if ($local < $from || $local > $to) {
                continue;
            }
            $records[] = $record;
        }

        return [
            'display' => $choice->display->value,
            'timezone' => null,
            'records' => array_slice($records, $query->offset, $query->limit),
            'page' => ['limit' => $query->limit, 'offset' => $query->offset, 'total' => count($records)],
        ];
    }

    /**
     * @param array{0: string, 1: string}|null $bounds
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function constraints(?string $managerEmployeeId, AttendanceReportQuery $query, ?array $bounds, string $statusZone): array
    {
        $where = [];
        $params = [];
        if ($managerEmployeeId !== null) {
            $where[] = 'ev.employee_id IN (SELECT employee_id FROM manager_assignments WHERE manager_employee_id = ?)';
            $params[] = $managerEmployeeId;
        }
        if ($query->employeeId !== null) {
            $where[] = 'ev.employee_id = ?';
            $params[] = $query->employeeId;
        }
        if ($query->teamId !== null) {
            $where[] = 'ev.employee_id IN (SELECT id FROM employees WHERE team_id = ?)';
            $params[] = $query->teamId;
        }
        if ($query->departmentId !== null) {
            $where[] = 'ev.employee_id IN (SELECT id FROM employees WHERE department_id = ?)';
            $params[] = $query->departmentId;
        }
        if ($query->deviceId !== null) {
            $where[] = 'ev.device_id = ?';
            $params[] = $query->deviceId;
        }
        if ($query->eventType !== null) {
            $where[] = 'ev.event_type = ?';
            $params[] = $query->eventType;
        }
        if ($bounds !== null) {
            $where[] = 'ev.occurred_at >= ? AND ev.occurred_at < ?';
            $params[] = $bounds[0];
            $params[] = $bounds[1];
        }
        if ($query->status !== null) {
            $types = match ($query->status) {
                'working' => ['clock_in', 'lunch_end'],
                'on_lunch' => ['lunch_start'],
                default => ['clock_out'],
            };
            $cutoff = '';
            $endDate = $query->to ?? $query->localDate ?? $query->from;
            if ($endDate !== null) {
                $cutoff = 'WHERE occurred_at < ?';
                $params[] = UtcTime::persist(UtcTime::localDay($endDate, $statusZone)['end']);
            }
            $placeholders = implode(', ', array_fill(0, count($types), '?'));
            $where[] = 'ev.employee_id IN (SELECT employee_id FROM (SELECT employee_id, event_type, ROW_NUMBER() OVER (PARTITION BY employee_id ORDER BY occurred_at DESC, id DESC) AS rn FROM attendance_facts '.$cutoff.') ranked WHERE rn = 1 AND event_type IN ('.$placeholders.'))';
            foreach ($types as $type) {
                $params[] = $type;
            }
        }

        return [$where, $params];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function wideBounds(string $from, string $to): array
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $from.' 00:00:00', UtcTime::zone());
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $to.' 00:00:00', UtcTime::zone());
        if (!$start instanceof \DateTimeImmutable || !$end instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException('The local date must be YYYY-MM-DD.');
        }

        return [
            UtcTime::persist($start->modify('-14 hours')),
            UtcTime::persist($end->modify('+1 day')->modify('+14 hours')),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $deviceZones
     *
     * @return array{id: string, employeeId: string, employeeName: string|null, eventType: string, deviceId: string|null, occurredAt: string, displayedAt: string, timezone: string}
     */
    private function searchRecord(array $row, ?string $shared, array &$deviceZones, string $tenantTimeZone): array
    {
        $occurred = $row['occurred_at'] ?? null;
        $id = $row['id'] ?? null;
        $employeeId = $row['employee_id'] ?? null;
        $eventType = $row['event_type'] ?? null;
        if (!is_string($occurred) || !is_string($id) || !is_string($employeeId) || !is_string($eventType)) {
            throw new \InvalidArgumentException('The stored attendance time is not UTC.');
        }
        $deviceId = is_string($row['device_id'] ?? null) ? $row['device_id'] : null;
        $zone = $shared ?? ($deviceId !== null ? $this->rememberedDeviceZone($deviceId, $deviceZones) : UtcTime::requireIana($tenantTimeZone));
        $instant = UtcTime::parseStored($occurred);
        $name = $row['display_name'] ?? null;

        return [
            'id' => $id,
            'employeeId' => $employeeId,
            'employeeName' => is_string($name) ? $name : null,
            'eventType' => $eventType,
            'deviceId' => $deviceId,
            'occurredAt' => UtcTime::toIso($instant),
            'displayedAt' => UtcTime::present($instant, $zone),
            'timezone' => $zone,
        ];
    }
}
