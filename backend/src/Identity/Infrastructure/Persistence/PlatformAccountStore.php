<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Persistence;

use App\Identity\Domain\AccessCatalog;
use App\Shared\Time\UtcTime;
use App\Identity\Domain\AccountRecord;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

final class PlatformAccountStore
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.platform_connection')]
        private readonly Connection $connection,
    ) {
    }

    public function findByEmail(string $email): ?AccountRecord
    {
        $id = $this->connection->fetchOne('SELECT id FROM platform_users WHERE email = ?', [strtolower($email)]);

        return is_string($id) ? $this->find($id) : null;
    }

    public function find(string $id): ?AccountRecord
    {
        $row = $this->connection->fetchAssociative(
            'SELECT u.id, u.email, u.display_name, u.status, c.password_hash, c.token_version
             FROM platform_users u
             INNER JOIN platform_credentials c ON c.user_id = u.id
             WHERE u.id = ?',
            [$id],
        );
        if (!is_array($row)) {
            return null;
        }
        $roles = [];
        foreach ($this->connection->fetchFirstColumn('SELECT role FROM platform_memberships WHERE user_id = ?', [$id]) as $role) {
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
            null,
        );
    }

    public function createAdmin(string $email, string $displayName, string $passwordHash, \DateTimeImmutable $now): string
    {
        $id = Uuid::v7()->toRfc4122();
        $stamp = UtcTime::persist($now);
        $this->connection->transactional(function () use ($id, $email, $displayName, $passwordHash, $stamp): void {
            $this->connection->insert('platform_users', [
                'id' => $id,
                'email' => strtolower($email),
                'display_name' => $displayName,
                'status' => 'active',
                'created_at' => $stamp,
            ]);
            $this->connection->insert('platform_credentials', [
                'user_id' => $id,
                'password_hash' => $passwordHash,
                'token_version' => 1,
                'updated_at' => $stamp,
            ]);
            $this->connection->insert('platform_memberships', [
                'id' => Uuid::v7()->toRfc4122(),
                'user_id' => $id,
                'role' => AccessCatalog::PLATFORM_ADMIN,
                'created_at' => $stamp,
            ]);
        });

        return $id;
    }
}
