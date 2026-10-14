<?php

declare(strict_types=1);

namespace App\Tenant\Directory\Infrastructure;

use App\Identity\Application\AccountInput;
use App\Platform\TenantRegistry\Application\TenantQuotaService;
use App\Platform\TenantRegistry\Domain\InvalidTenantInputException;
use App\Shared\Time\UtcTime;
use App\Tenant\Directory\AttendanceIdentifierIssuer;
use App\Tenant\Directory\EmploymentStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

final class EmployeeDirectory
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.tenant_connection')]
        private readonly Connection $connection,
        private readonly AttendanceIdentifierIssuer $identifiers,
        private readonly AccountInput $input,
        private readonly TenantQuotaService $quotas,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?string $managerEmployeeId): array
    {
        $params = [];
        $scope = '';
        if ($managerEmployeeId !== null) {
            $scope = ' WHERE e.id IN (SELECT employee_id FROM manager_assignments WHERE manager_employee_id = ?)';
            $params[] = $managerEmployeeId;
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT e.id, e.employee_number, e.display_name, e.email, e.phone, e.user_id, e.department_id, e.team_id, e.status, e.created_at, e.updated_at,
                    d.name AS department_name, t.name AS team_name,
                    mgr.manager_employee_id, mgr.manager_name,
                    sch.schedule_id, sch.schedule_name,
                    codes.employee_id AS code_employee_id
             FROM employees e
             LEFT JOIN departments d ON d.id = e.department_id
             LEFT JOIN teams t ON t.id = e.team_id
             LEFT JOIN (
                SELECT employee_id, manager_employee_id, manager_name FROM (
                    SELECT a.employee_id, a.manager_employee_id, m.display_name AS manager_name,
                           ROW_NUMBER() OVER (PARTITION BY a.employee_id ORDER BY a.created_at DESC, a.id DESC) AS rn
                    FROM manager_assignments a
                    INNER JOIN employees m ON m.id = a.manager_employee_id
                ) ranked_managers WHERE rn = 1
             ) mgr ON mgr.employee_id = e.id
             LEFT JOIN (
                SELECT employee_id, schedule_id, schedule_name FROM (
                    SELECT s.employee_id, s.id AS schedule_id, s.name AS schedule_name,
                           ROW_NUMBER() OVER (PARTITION BY s.employee_id ORDER BY s.created_at DESC, s.id DESC) AS rn
                    FROM schedules s
                ) ranked_schedules WHERE rn = 1
             ) sch ON sch.employee_id = e.id
             LEFT JOIN employee_codes codes ON codes.employee_id = e.id'
            .$scope
            .' ORDER BY e.display_name, e.id',
            $params,
        );
        $employees = [];
        foreach ($rows as $row) {
            $employees[] = $this->listed($row);
        }

        return $employees;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, employee_number, display_name, email, phone, user_id, department_id, team_id, status, created_at, updated_at
             FROM employees WHERE id = ?',
            [$id],
        );
        if (!is_array($row)) {
            return null;
        }
        $departmentId = $this->nullableString($row['department_id']);
        $teamId = $this->nullableString($row['team_id']);
        $manager = $this->connection->fetchAssociative(
            'SELECT a.manager_employee_id, e.display_name
             FROM manager_assignments a
             INNER JOIN employees e ON e.id = a.manager_employee_id
             WHERE a.employee_id = ?
             ORDER BY a.created_at DESC',
            [$id],
        );
        $schedule = $this->connection->fetchAssociative(
            'SELECT id, name FROM schedules WHERE employee_id = ? ORDER BY created_at DESC',
            [$id],
        );
        $code = $this->connection->fetchOne('SELECT employee_id FROM employee_codes WHERE employee_id = ?', [$id]);
        $createdAt = $this->instant((string) $row['created_at']);
        $updatedAt = $this->nullableString($row['updated_at']);

        return [
            'id' => (string) $row['id'],
            'employeeNumber' => $this->nullableString($row['employee_number']),
            'name' => (string) $row['display_name'],
            'displayName' => (string) $row['display_name'],
            'email' => $this->nullableString($row['email']),
            'phone' => $this->nullableString($row['phone']),
            'userId' => $this->nullableString($row['user_id']),
            'departmentId' => $departmentId,
            'departmentName' => $departmentId === null ? null : $this->nameOf('departments', $departmentId),
            'teamId' => $teamId,
            'teamName' => $teamId === null ? null : $this->nameOf('teams', $teamId),
            'managerEmployeeId' => is_array($manager) ? (string) $manager['manager_employee_id'] : null,
            'managerName' => is_array($manager) ? (string) $manager['display_name'] : null,
            'status' => (string) $row['status'],
            'schedule' => is_array($schedule) ? ['id' => (string) $schedule['id'], 'name' => (string) $schedule['name']] : null,
            'attendanceIdentifierSet' => is_string($code),
            'createdAt' => $createdAt,
            'updatedAt' => $updatedAt ?? $createdAt,
        ];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function create(array $body, \DateTimeImmutable $now): array
    {
        $number = $this->employeeNumber($this->requiredString($body, 'employeeNumber'));
        $name = $this->input->displayName($this->requiredString($body, 'name'));
        $email = $this->optionalEmail($body, 'email');
        $phone = $this->optionalPhone($body, 'phone');
        [$departmentId, $teamId] = $this->placement($body, true);
        $managerId = $this->optionalManager($body, true, null);
        $scheduleName = $this->optionalName($body, 'scheduleName', true);
        $id = Uuid::v7()->toRfc4122();
        $issued = $this->identifiers->issue();
        $stamp = UtcTime::persist($now);
        try {
            $this->connection->transactional(function () use ($id, $number, $name, $email, $phone, $departmentId, $teamId, $managerId, $scheduleName, $issued, $stamp): void {
                $this->quotas->reserveEmployee();
                $this->connection->insert('employees', [
                    'id' => $id,
                    'employee_number' => $number,
                    'display_name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'department_id' => $departmentId,
                    'team_id' => $teamId,
                    'status' => EmploymentStatus::Active->value,
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ]);
                $this->connection->insert('employee_codes', [
                    'employee_id' => $id,
                    'code' => $issued['hash'],
                    'created_at' => $stamp,
                ]);
                if ($managerId !== null) {
                    $this->insertManager($managerId, $id, $stamp);
                }
                if ($scheduleName !== null) {
                    $this->insertSchedule($id, $scheduleName, $stamp);
                }
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new UnprocessableEntityHttpException('Employee number is already in use.', $exception);
        }
        $profile = $this->requireProfile($id);
        $profile['attendanceCode'] = $issued['code'];

        return $profile;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function update(string $id, array $body, \DateTimeImmutable $now): array
    {
        if ($this->find($id) === null) {
            throw new NotFoundHttpException('Employee was not found.');
        }
        $stamp = UtcTime::persist($now);
        $sets = ['updated_at' => $stamp];
        if (array_key_exists('employeeNumber', $body)) {
            $sets['employee_number'] = $this->employeeNumber($this->requiredString($body, 'employeeNumber'));
        }
        if (array_key_exists('name', $body)) {
            $sets['display_name'] = $this->input->displayName($this->requiredString($body, 'name'));
        }
        if (array_key_exists('email', $body)) {
            $sets['email'] = $this->optionalEmail($body, 'email');
        }
        if (array_key_exists('phone', $body)) {
            $sets['phone'] = $this->optionalPhone($body, 'phone');
        }
        if (array_key_exists('departmentId', $body) || array_key_exists('teamId', $body)) {
            [$departmentId, $teamId] = $this->placement($body, false, $id);
            $sets['department_id'] = $departmentId;
            $sets['team_id'] = $teamId;
        }
        $managerId = array_key_exists('managerEmployeeId', $body) ? $this->optionalManager($body, false, $id) : null;
        $scheduleName = array_key_exists('scheduleName', $body) ? $this->optionalName($body, 'scheduleName', false) : null;
        if (count($sets) === 1 && !array_key_exists('managerEmployeeId', $body) && !array_key_exists('scheduleName', $body)) {
            throw new UnprocessableEntityHttpException('The request body is invalid.');
        }
        try {
            $this->connection->transactional(function () use ($id, $sets, $body, $managerId, $scheduleName, $stamp): void {
                $this->connection->update('employees', $sets, ['id' => $id]);
                if (array_key_exists('managerEmployeeId', $body)) {
                    $this->connection->delete('manager_assignments', ['employee_id' => $id]);
                    if ($managerId !== null) {
                        $this->insertManager($managerId, $id, $stamp);
                    }
                }
                if (array_key_exists('scheduleName', $body)) {
                    $this->connection->delete('schedules', ['employee_id' => $id]);
                    if ($scheduleName !== null) {
                        $this->insertSchedule($id, $scheduleName, $stamp);
                    }
                }
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new UnprocessableEntityHttpException('Employee number is already in use.', $exception);
        }
        return $this->requireProfile($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function changeStatus(string $id, string $status, \DateTimeImmutable $now): array
    {
        if ($this->find($id) === null) {
            throw new NotFoundHttpException('Employee was not found.');
        }
        try {
            $resolved = EmploymentStatus::fromString($status);
        } catch (\InvalidArgumentException $exception) {
            throw new UnprocessableEntityHttpException('Employment status must be active or inactive.', $exception);
        }
        $this->connection->transactional(function () use ($id, $resolved, $now): void {
            if ($resolved === EmploymentStatus::Active) {
                $this->quotas->lockGuard();
                $status = $this->connection->fetchOne('SELECT status FROM employees WHERE id = ?', [$id]);
                if ($status !== EmploymentStatus::Active->value) {
                    $this->quotas->assertCapacity('employees', 1);
                }
            }
            $this->connection->update('employees', [
                'status' => $resolved->value,
                'updated_at' => UtcTime::persist($now),
            ], ['id' => $id]);
        });
        return $this->requireProfile($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function assignChief(string $employeeId, string $managerEmployeeId, \DateTimeImmutable $now): array
    {
        if ($this->find($employeeId) === null) {
            throw new NotFoundHttpException('Employee was not found.');
        }
        $this->assertManager($managerEmployeeId, $employeeId);
        $stamp = UtcTime::persist($now);
        $this->connection->transactional(function () use ($employeeId, $managerEmployeeId, $stamp): void {
            $this->connection->delete('manager_assignments', ['employee_id' => $employeeId]);
            $this->insertManager($managerEmployeeId, $employeeId, $stamp);
            $this->connection->update('employees', ['updated_at' => $stamp], ['id' => $employeeId]);
        });
        return $this->requireProfile($employeeId);
    }

    /**
     * @return array<string, mixed>
     */
    public function resetAttendanceIdentifier(string $id, \DateTimeImmutable $now): array
    {
        if ($this->find($id) === null) {
            throw new NotFoundHttpException('Employee was not found.');
        }
        $issued = $this->identifiers->issue();
        $stamp = UtcTime::persist($now);
        $this->connection->transactional(function () use ($id, $issued, $stamp): void {
            $this->connection->delete('employee_codes', ['employee_id' => $id]);
            $this->connection->insert('employee_codes', [
                'employee_id' => $id,
                'code' => $issued['hash'],
                'created_at' => $stamp,
            ]);
            $this->connection->update('employees', ['updated_at' => $stamp], ['id' => $id]);
        });
        $profile = $this->requireProfile($id);
        $profile['attendanceCode'] = $issued['code'];

        return $profile;
    }

    public function addTeam(string $name, ?string $departmentId, \DateTimeImmutable $now): string
    {
        if ($departmentId !== null) {
            $this->requireRow('departments', $departmentId, 'Department was not found.');
        }
        $id = Uuid::v7()->toRfc4122();
        $this->connection->insert('teams', [
            'id' => $id,
            'department_id' => $departmentId,
            'name' => $name,
            'created_at' => UtcTime::persist($now),
        ]);

        return $id;
    }

    /**
     * @return list<array{id: string, name: string, departmentId: ?string}>
     */
    public function teams(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, name, department_id FROM teams ORDER BY name');
        $teams = [];
        foreach ($rows as $row) {
            $teams[] = [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
                'departmentId' => $this->nullableString($row['department_id']),
            ];
        }

        return $teams;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function placement(array $body, bool $creating, ?string $employeeId = null): array
    {
        $current = $employeeId === null ? null : $this->find($employeeId);
        $departmentId = $creating ? null : (is_array($current) ? $this->nullableString($current['departmentId']) : null);
        $teamId = $creating ? null : (is_array($current) ? $this->nullableString($current['teamId']) : null);
        if (array_key_exists('departmentId', $body) || $creating) {
            $departmentId = $this->optionalUuid($body, 'departmentId');
        }
        if (array_key_exists('teamId', $body) || $creating) {
            $teamId = $this->optionalUuid($body, 'teamId');
        }
        if ($departmentId !== null) {
            $this->requireRow('departments', $departmentId, 'Department was not found.');
        }
        if ($teamId !== null) {
            $teamDepartment = $this->connection->fetchOne('SELECT department_id FROM teams WHERE id = ?', [$teamId]);
            if ($teamDepartment === false) {
                throw new NotFoundHttpException('Team was not found.');
            }
            $teamDepartmentId = $this->nullableString($teamDepartment);
            if ($teamDepartmentId !== null && $departmentId !== null && $teamDepartmentId !== $departmentId) {
                throw new UnprocessableEntityHttpException('The team belongs to another department.');
            }
            if ($teamDepartmentId !== null) {
                $departmentId = $teamDepartmentId;
            }
        }

        return [$departmentId, $teamId];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function optionalManager(array $body, bool $creating, ?string $employeeId): ?string
    {
        if (!$creating && !array_key_exists('managerEmployeeId', $body)) {
            return null;
        }
        $managerId = $this->optionalUuid($body, 'managerEmployeeId');
        if ($managerId !== null) {
            $this->assertManager($managerId, $employeeId ?? '');
        }

        return $managerId;
    }

    private function assertManager(string $managerId, string $employeeId): void
    {
        if ($managerId === $employeeId) {
            throw new UnprocessableEntityHttpException('An employee cannot be their own manager.');
        }
        $status = $this->connection->fetchOne('SELECT status FROM employees WHERE id = ?', [$managerId]);
        if (!is_string($status)) {
            throw new NotFoundHttpException('Employee was not found.');
        }
        if ($status !== EmploymentStatus::Active->value) {
            throw new UnprocessableEntityHttpException('The manager must be an active employee.');
        }
    }

    private function insertManager(string $managerId, string $employeeId, string $stamp): void
    {
        $this->connection->insert('manager_assignments', [
            'id' => Uuid::v7()->toRfc4122(),
            'manager_employee_id' => $managerId,
            'employee_id' => $employeeId,
            'created_at' => $stamp,
        ]);
    }

    private function insertSchedule(string $employeeId, string $name, string $stamp): void
    {
        $this->connection->insert('schedules', [
            'id' => Uuid::v7()->toRfc4122(),
            'employee_id' => $employeeId,
            'name' => $name,
            'created_at' => $stamp,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function optionalEmail(array $body, string $key): ?string
    {
        $value = $body[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new UnprocessableEntityHttpException('The request body is invalid.');
        }

        return $this->input->email($value);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function optionalPhone(array $body, string $key): ?string
    {
        $value = $body[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new UnprocessableEntityHttpException('The request body is invalid.');
        }
        $value = trim($value);
        if (!preg_match('/^\+?[0-9][0-9 \-]{5,38}$/', $value)) {
            throw new InvalidTenantInputException('Phone number is invalid.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function optionalName(array $body, string $key, bool $creating): ?string
    {
        if (!$creating && !array_key_exists($key, $body)) {
            return null;
        }
        $value = $body[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new UnprocessableEntityHttpException('The request body is invalid.');
        }

        return $this->input->displayName($value);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function optionalUuid(array $body, string $key): ?string
    {
        if (!array_key_exists($key, $body) || $body[$key] === null || $body[$key] === '') {
            return null;
        }
        if (!is_string($body[$key]) || !Uuid::isValid($body[$key])) {
            throw new UnprocessableEntityHttpException('The request body is invalid.');
        }

        return $body[$key];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function requiredString(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value)) {
            throw new UnprocessableEntityHttpException('The request body is invalid.');
        }

        return $value;
    }

    private function employeeNumber(string $number): string
    {
        $number = strtoupper(trim($number));
        if (!preg_match('/^[A-Z0-9][A-Z0-9-]{0,31}$/', $number)) {
            throw new InvalidTenantInputException('Employee number is invalid.');
        }

        return $number;
    }

    private function requireRow(string $table, string $id, string $message): void
    {
        $found = $this->connection->fetchOne('SELECT id FROM '.$table.' WHERE id = ?', [$id]);
        if (!is_string($found)) {
            throw new NotFoundHttpException($message);
        }
    }

    private function nameOf(string $table, string $id): ?string
    {
        $name = $this->connection->fetchOne('SELECT name FROM '.$table.' WHERE id = ?', [$id]);

        return is_string($name) ? $name : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireProfile(string $id): array
    {
        $profile = $this->find($id);
        if (!is_array($profile)) {
            throw new NotFoundHttpException('Employee was not found.');
        }

        return $profile;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function listed(array $row): array
    {
        $id = $row['id'] ?? null;
        $name = $row['display_name'] ?? null;
        $created = $row['created_at'] ?? null;
        $status = $row['status'] ?? null;
        if (!is_string($id) || !is_string($name) || !is_string($created) || !is_string($status)) {
            throw new \InvalidArgumentException('The stored employee is invalid.');
        }
        $departmentId = $this->nullableString($row['department_id']);
        $teamId = $this->nullableString($row['team_id']);
        $departmentName = $row['department_name'] ?? null;
        $teamName = $row['team_name'] ?? null;
        $managerId = $this->nullableString($row['manager_employee_id'] ?? null);
        $managerName = $row['manager_name'] ?? null;
        $scheduleId = $this->nullableString($row['schedule_id'] ?? null);
        $scheduleName = $row['schedule_name'] ?? null;
        $createdAt = $this->instant($created);

        return [
            'id' => $id,
            'employeeNumber' => $this->nullableString($row['employee_number']),
            'name' => $name,
            'displayName' => $name,
            'email' => $this->nullableString($row['email']),
            'phone' => $this->nullableString($row['phone']),
            'userId' => $this->nullableString($row['user_id']),
            'departmentId' => $departmentId,
            'departmentName' => is_string($departmentName) ? $departmentName : null,
            'teamId' => $teamId,
            'teamName' => is_string($teamName) ? $teamName : null,
            'managerEmployeeId' => $managerId,
            'managerName' => is_string($managerName) ? $managerName : null,
            'status' => $status,
            'schedule' => $scheduleId !== null && is_string($scheduleName) ? ['id' => $scheduleId, 'name' => $scheduleName] : null,
            'attendanceIdentifierSet' => is_string($row['code_employee_id'] ?? null),
            'createdAt' => $createdAt,
            'updatedAt' => $this->nullableString($row['updated_at']) ?? $createdAt,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function instant(string $value): string
    {
        return UtcTime::toIso(UtcTime::parseStored($value));
    }
}
