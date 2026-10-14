<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Persistence;

use App\Audit\AuditFacts;
use App\Audit\AuditMetadata;
use App\Identity\Domain\AccountRecord;
use App\Platform\TenantRegistry\Application\TenantQuotaService;
use App\Shared\Time\UtcTime;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

final class TenantAccountStore
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.tenant_connection')]
        private readonly Connection $connection,
        private readonly AuditFacts $facts,
        private readonly TenantQuotaService $quotas,
    ) {
    }

    public function findByEmail(string $email): ?AccountRecord
    {
        $id = $this->connection->fetchOne('SELECT id FROM users WHERE email = ?', [strtolower($email)]);

        return is_string($id) ? $this->find($id) : null;
    }

    public function find(string $id): ?AccountRecord
    {
        $row = $this->connection->fetchAssociative(
            'SELECT u.id, u.email, u.display_name, u.status, u.token_version, c.password_hash, e.id AS employee_id
             FROM users u
             INNER JOIN user_credentials c ON c.user_id = u.id
             LEFT JOIN employees e ON e.user_id = u.id
             WHERE u.id = ?',
            [$id],
        );
        if (!is_array($row)) {
            return null;
        }
        $roles = [];
        foreach ($this->connection->fetchFirstColumn('SELECT role FROM user_roles WHERE user_id = ? ORDER BY role', [$id]) as $role) {
            if (is_string($role)) {
                $roles[] = $role;
            }
        }

        return new AccountRecord(
            (string) $row['id'],
            (string) $row['email'],
            (string) $row['display_name'],
            (string) $row['status'],
            (int) $row['token_version'],
            (string) $row['password_hash'],
            $roles,
            isset($row['employee_id']) && is_string($row['employee_id']) ? $row['employee_id'] : null,
        );
    }

    /**
     * @param list<string> $roles
     */
    public function create(
        string $email,
        string $displayName,
        string $passwordHash,
        array $roles,
        \DateTimeImmutable $now,
    ): AccountRecord {
        $id = Uuid::v7()->toRfc4122();
        $employeeId = Uuid::v7()->toRfc4122();
        $stamp = UtcTime::persist($now);
        $this->connection->transactional(function () use ($id, $employeeId, $email, $displayName, $passwordHash, $roles, $stamp): void {
            $this->quotas->reserveUserAccount();
            $this->connection->insert('users', [
                'id' => $id,
                'email' => strtolower($email),
                'display_name' => $displayName,
                'status' => 'active',
                'token_version' => 1,
                'created_at' => $stamp,
            ]);
            $this->connection->insert('user_credentials', [
                'user_id' => $id,
                'password_hash' => $passwordHash,
                'updated_at' => $stamp,
            ]);
            foreach ($roles as $role) {
                $this->connection->insert('user_roles', [
                    'id' => Uuid::v7()->toRfc4122(),
                    'user_id' => $id,
                    'role' => $role,
                ]);
            }
            $this->connection->insert('employees', [
                'id' => $employeeId,
                'user_id' => $id,
                'employee_number' => strtoupper(str_replace('-', '', $employeeId)),
                'display_name' => $displayName,
                'status' => 'active',
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
        });
        $created = $this->find($id);
        if (!$created instanceof AccountRecord) {
            throw new \RuntimeException('The tenant user could not be created.');
        }

        return $created;
    }

    /**
     * @param list<string> $roles
     */
    public function replaceRoles(string $userId, array $roles): void
    {
        $this->connection->transactional(function () use ($userId, $roles): void {
            $this->connection->delete('user_roles', ['user_id' => $userId]);
            foreach ($roles as $role) {
                $this->connection->insert('user_roles', [
                    'id' => Uuid::v7()->toRfc4122(),
                    'user_id' => $userId,
                    'role' => $role,
                ]);
            }
        });
    }

    public function disable(string $userId, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(
            'UPDATE users SET status = ?, token_version = token_version + 1 WHERE id = ?',
            ['disabled', $userId],
        );
        $this->connection->update('user_credentials', [
            'updated_at' => UtcTime::persist($now),
        ], ['user_id' => $userId]);
    }

    public function assignManager(string $managerEmployeeId, string $employeeId, \DateTimeImmutable $now): void
    {
        $this->connection->insert('manager_assignments', [
            'id' => Uuid::v7()->toRfc4122(),
            'manager_employee_id' => $managerEmployeeId,
            'employee_id' => $employeeId,
            'created_at' => UtcTime::persist($now),
        ]);
    }

    public function manages(string $managerEmployeeId, string $employeeId): bool
    {
        $found = $this->connection->fetchOne(
            'SELECT id FROM manager_assignments WHERE manager_employee_id = ? AND employee_id = ?',
            [$managerEmployeeId, $employeeId],
        );

        return is_string($found);
    }

    /**
     * @return list<array{id: string, displayName: string, userId: ?string}>
     */
    public function employees(?string $managerEmployeeId): array
    {
        if ($managerEmployeeId === null) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, display_name, user_id FROM employees ORDER BY display_name',
            );
        } else {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT e.id, e.display_name, e.user_id
                 FROM employees e
                 INNER JOIN manager_assignments a ON a.employee_id = e.id
                 WHERE a.manager_employee_id = ?
                 ORDER BY e.display_name',
                [$managerEmployeeId],
            );
        }
        $employees = [];
        foreach ($rows as $row) {
            $employees[] = [
                'id' => (string) $row['id'],
                'displayName' => (string) $row['display_name'],
                'userId' => isset($row['user_id']) && is_string($row['user_id']) ? $row['user_id'] : null,
            ];
        }

        return $employees;
    }

    /**
     * @return array{id: string, displayName: string, userId: ?string}|null
     */
    public function employee(string $id): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT id, display_name, user_id FROM employees WHERE id = ?', [$id]);
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'displayName' => (string) $row['display_name'],
            'userId' => isset($row['user_id']) && is_string($row['user_id']) ? $row['user_id'] : null,
        ];
    }

    /**
     * @return array{id: string, employeeId: string, status: string}|null
     */
    public function correction(string $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, employee_id, status FROM attendance_correction_requests WHERE id = ?',
            [$id],
        );
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'employeeId' => (string) $row['employee_id'],
            'status' => (string) $row['status'],
        ];
    }

    /**
     * @return array{
     *     records: list<array{
     *         id: string,
     *         employeeId: string,
     *         employeeName: string|null,
     *         requestedByUserId: string,
     *         reason: string,
     *         comment: string|null,
     *         supportingInformation: string|null,
     *         localDate: string|null,
     *         localTime: string|null,
     *         requestedAction: string|null,
     *         requestedOccurredAt: string|null,
     *         originalEvents: list<array{id: string, eventType: string, occurredAt: string, origin: string}>,
     *         status: string,
     *         decidedByUserId: string|null,
     *         decisionComment: string|null,
     *         createdAt: string,
     *         decidedAt: string|null
     *     }>,
     *     page: array{limit: int, offset: int, total: int}
     * }
     */
    public function listCorrections(?string $employeeId, ?string $managerEmployeeId, int $limit = 50, int $offset = 0): array
    {
        $this->boundedPage($limit, $offset);
        $where = [];
        $params = [];
        if ($employeeId !== null) {
            $where[] = 'c.employee_id = ?';
            $params[] = $employeeId;
        }
        if ($managerEmployeeId !== null) {
            $where[] = 'c.employee_id IN (SELECT employee_id FROM manager_assignments WHERE manager_employee_id = ?)';
            $params[] = $managerEmployeeId;
        }
        $sql = 'SELECT c.id, c.employee_id, e.display_name, c.requested_by_user_id, c.reason, c.comment, c.supporting_information, c.local_date, c.local_time, c.requested_action, c.requested_occurred_at, c.original_events, c.status, c.decided_by_user_id, c.decision_comment, c.created_at, c.decided_at
            FROM attendance_correction_requests c
            LEFT JOIN employees e ON e.id = c.employee_id';
        if ($where !== []) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }
        $countSql = 'SELECT COUNT(*) FROM attendance_correction_requests c';
        if ($where !== []) {
            $countSql .= ' WHERE '.implode(' AND ', $where);
        }
        $total = (int) $this->connection->fetchOne($countSql, $params);
        $sql .= ' ORDER BY c.created_at DESC, c.id DESC LIMIT '.$limit.' OFFSET '.$offset;
        $corrections = [];
        foreach ($this->connection->fetchAllAssociative($sql, $params) as $row) {
            $id = $row['id'] ?? null;
            $owner = $row['employee_id'] ?? null;
            $requester = $row['requested_by_user_id'] ?? null;
            $reason = $row['reason'] ?? null;
            $status = $row['status'] ?? null;
            $createdAt = $row['created_at'] ?? null;
            if (!is_string($id) || !is_string($owner) || !is_string($requester) || !is_string($reason) || !is_string($status) || !is_string($createdAt)) {
                throw new \InvalidArgumentException('The stored correction request is invalid.');
            }
            $requestedAt = $this->nullableInstant($row['requested_occurred_at'] ?? null);
            $decidedAt = $this->nullableInstant($row['decided_at'] ?? null);
            $name = $row['display_name'] ?? null;
            $corrections[] = [
                'id' => $id,
                'employeeId' => $owner,
                'employeeName' => is_string($name) && $name !== '' ? $name : null,
                'requestedByUserId' => $requester,
                'reason' => $reason,
                'comment' => $this->nullableText($row['comment'] ?? null),
                'supportingInformation' => $this->nullableText($row['supporting_information'] ?? null),
                'localDate' => $this->nullableText($row['local_date'] ?? null),
                'localTime' => $this->nullableText($row['local_time'] ?? null),
                'requestedAction' => $this->nullableText($row['requested_action'] ?? null),
                'requestedOccurredAt' => $requestedAt,
                'originalEvents' => $this->originalEvents($row['original_events'] ?? null),
                'status' => $status,
                'decidedByUserId' => $this->nullableText($row['decided_by_user_id'] ?? null),
                'decisionComment' => $this->nullableText($row['decision_comment'] ?? null),
                'createdAt' => UtcTime::toIso(UtcTime::parseStored($createdAt)),
                'decidedAt' => $decidedAt,
            ];
        }

        return [
            'records' => $corrections,
            'page' => ['limit' => $limit, 'offset' => $offset, 'total' => $total],
        ];
    }

    public function decideCorrection(string $id, string $decision, string $userId, ?string $comment, \DateTimeImmutable $now): void
    {
        $this->connection->update('attendance_correction_requests', [
            'status' => $decision,
            'decided_by_user_id' => $userId,
            'decision_comment' => $comment,
            'decided_at' => UtcTime::persist($now),
        ], ['id' => $id]);
    }

    private function nullableText(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function nullableInstant(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? UtcTime::toIso(UtcTime::parseStored($value)) : null;
    }

    /**
     * @return list<array{id: string, eventType: string, occurredAt: string, origin: string}>
     */
    private function originalEvents(mixed $stored): array
    {
        if (!is_string($stored) || $stored === '') {
            return [];
        }
        try {
            $decoded = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }
        $events = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = $item['id'] ?? null;
            $type = $item['eventType'] ?? null;
            $occurred = $item['occurredAt'] ?? null;
            $origin = $item['origin'] ?? null;
            if (!is_string($id) || !is_string($type) || !is_string($occurred) || !is_string($origin)) {
                continue;
            }
            $events[] = [
                'id' => $id,
                'eventType' => $type,
                'occurredAt' => UtcTime::toIso(UtcTime::parseStored($occurred)),
                'origin' => $origin,
            ];
        }

        return $events;
    }

    /**
     * @param array<string, scalar|null> $metadata
     */
    public function audit(
        string $action,
        string $actorType,
        string $actorId,
        string $targetType,
        string $targetId,
        ?string $requestId,
        array $metadata,
        \DateTimeImmutable $now,
    ): void {
        $clean = AuditMetadata::sanitize($metadata);
        $this->connection->insert('audit_log', [
            'id' => Uuid::v7()->toRfc4122(),
            'occurred_at' => UtcTime::persist($now),
            'actor_type' => $actorType,
            'actor_id' => substr($actorId, 0, 36),
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'request_id' => $this->facts->requestId($requestId),
            'metadata' => json_encode($clean, JSON_THROW_ON_ERROR),
            'tenant_id' => $this->facts->tenantId($metadata),
            'result' => AuditMetadata::result($action, $metadata),
            'client_ip' => $this->facts->clientIp(),
            'device_id' => $this->facts->deviceId(),
        ]);
    }

    /**
     * @return array{records: list<array{action: string, actorId: string, metadata: string, occurredAt: string, requestId: ?string, result: ?string, clientIp: ?string, deviceId: ?string, tenantId: ?string, targetType: string, targetId: string}>, page: array{limit: int, offset: int, total: int}}
     */
    public function recentAudit(int $limit = 50, int $offset = 0): array
    {
        $this->boundedPage($limit, $offset);
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM audit_log');
        $rows = $this->connection->fetchAllAssociative(
            'SELECT action, actor_id, metadata, occurred_at, request_id, result, client_ip, device_id, tenant_id, target_type, target_id FROM audit_log ORDER BY occurred_at DESC, id DESC LIMIT '.$limit.' OFFSET '.$offset,
        );
        $entries = [];
        foreach ($rows as $row) {
            $occurred = $row['occurred_at'] ?? null;
            $entries[] = [
                'action' => (string) $row['action'],
                'actorId' => (string) $row['actor_id'],
                'metadata' => (string) $row['metadata'],
                'occurredAt' => is_string($occurred) ? UtcTime::toIso(UtcTime::parseStored($occurred)) : '',
                'requestId' => is_string($row['request_id'] ?? null) ? $row['request_id'] : null,
                'result' => is_string($row['result'] ?? null) ? $row['result'] : null,
                'clientIp' => is_string($row['client_ip'] ?? null) ? $row['client_ip'] : null,
                'deviceId' => is_string($row['device_id'] ?? null) ? $row['device_id'] : null,
                'tenantId' => is_string($row['tenant_id'] ?? null) ? $row['tenant_id'] : null,
                'targetType' => (string) ($row['target_type'] ?? ''),
                'targetId' => (string) ($row['target_id'] ?? ''),
            ];
        }

        return [
            'records' => $entries,
            'page' => ['limit' => $limit, 'offset' => $offset, 'total' => $total],
        ];
    }

    public function addDepartment(string $name, \DateTimeImmutable $now): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->connection->insert('departments', [
            'id' => $id,
            'name' => $name,
            'created_at' => UtcTime::persist($now),
        ]);

        return $id;
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function departments(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, name FROM departments ORDER BY name');
        $departments = [];
        foreach ($rows as $row) {
            $departments[] = ['id' => (string) $row['id'], 'name' => (string) $row['name']];
        }

        return $departments;
    }

    public function addSchedule(string $employeeId, string $name, \DateTimeImmutable $now): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->connection->insert('schedules', [
            'id' => $id,
            'employee_id' => $employeeId,
            'name' => $name,
            'created_at' => UtcTime::persist($now),
        ]);

        return $id;
    }

    /**
     * @return list<array{id: string, employeeId: string, name: string}>
     */
    public function schedules(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, employee_id, name FROM schedules ORDER BY name');
        $schedules = [];
        foreach ($rows as $row) {
            $schedules[] = [
                'id' => (string) $row['id'],
                'employeeId' => (string) $row['employee_id'],
                'name' => (string) $row['name'],
            ];
        }

        return $schedules;
    }

    /**
     * @return list<array{id: string, email: string, displayName: string, status: string, roles: list<string>}>
     */
    public function users(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT u.id, u.email, u.display_name, u.status, r.role
             FROM users u
             LEFT JOIN user_roles r ON r.user_id = u.id
             ORDER BY u.email, r.role',
        );
        /** @var array<string, array{id: string, email: string, displayName: string, status: string, roles: list<string>}> $users */
        $users = [];
        foreach ($rows as $row) {
            $id = (string) $row['id'];
            if (!isset($users[$id])) {
                $users[$id] = [
                    'id' => $id,
                    'email' => (string) $row['email'],
                    'displayName' => (string) $row['display_name'],
                    'status' => (string) $row['status'],
                    'roles' => [],
                ];
            }
            $role = $row['role'] ?? null;
            if (is_string($role) && $role !== '') {
                $users[$id]['roles'][] = $role;
            }
        }

        return array_values($users);
    }

    private function boundedPage(int $limit, int $offset): void
    {
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 100000) {
            throw new \InvalidArgumentException('The page request is invalid.');
        }
    }
}
