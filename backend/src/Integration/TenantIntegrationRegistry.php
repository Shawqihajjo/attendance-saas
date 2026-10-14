<?php

declare(strict_types=1);

namespace App\Integration;

use App\Platform\TenantRegistry\Infrastructure\Security\SodiumCredentialCipher;
use App\Shared\Time\UtcTime;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

final class TenantIntegrationRegistry
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.platform_connection')]
        private readonly Connection $connection,
        private readonly SodiumCredentialCipher $cipher,
        private readonly IntegrationConfig $config,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public function register(string $tenantId, string $kind, string $eventName, array $config, \DateTimeImmutable $now): string
    {
        if (!Uuid::isValid($tenantId)) {
            throw new \InvalidArgumentException('A tenant identifier must be a UUID.');
        }
        $validated = $this->config->validate($kind, $eventName, $config);
        $id = Uuid::v7()->toRfc4122();
        $this->connection->insert('tenant_integrations', [
            'id' => $id,
            'tenant_id' => $tenantId,
            'kind' => $kind,
            'event_name' => $eventName,
            'status' => 'enabled',
            'config_ciphertext' => $this->cipher->encrypt(json_encode($validated, JSON_THROW_ON_ERROR)),
            'key_id' => $this->cipher->keyId(),
            'created_at' => UtcTime::persist($now),
        ]);

        return $id;
    }

    /**
     * @return list<array{id: string, kind: string, eventName: string, status: string}>
     */
    public function list(string $tenantId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, kind, event_name, status FROM tenant_integrations WHERE tenant_id = ? ORDER BY created_at',
            [$tenantId],
        );
        $items = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $kind = $row['kind'] ?? null;
            $eventName = $row['event_name'] ?? null;
            $status = $row['status'] ?? null;
            if (!is_string($id) || !is_string($kind) || !is_string($eventName) || !is_string($status)) {
                continue;
            }
            $items[] = ['id' => $id, 'kind' => $kind, 'eventName' => $eventName, 'status' => $status];
        }

        return $items;
    }

    /**
     * @return list<array{id: string, tenantId: string, kind: string, config: array<string, string>}>
     */
    public function enabled(string $tenantId, string $eventName): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, tenant_id, kind, config_ciphertext FROM tenant_integrations WHERE tenant_id = ? AND status = \'enabled\' AND (event_name = ? OR event_name = \'*\')',
            [$tenantId, $eventName],
        );
        $items = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $owner = $row['tenant_id'] ?? null;
            $kind = $row['kind'] ?? null;
            $cipher = $row['config_ciphertext'] ?? null;
            if (!is_string($id) || !is_string($owner) || $owner !== $tenantId || !is_string($kind) || !is_string($cipher)) {
                throw new \RuntimeException('The integration registration is invalid.');
            }
            $decoded = json_decode($this->cipher->decrypt($cipher), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new \RuntimeException('The integration registration is invalid.');
            }
            $config = [];
            foreach ($decoded as $key => $value) {
                if (!is_string($key) || !is_string($value)) {
                    throw new \RuntimeException('The integration registration is invalid.');
                }
                $config[$key] = $value;
            }
            $items[] = ['id' => $id, 'tenantId' => $owner, 'kind' => $kind, 'config' => $config];
        }

        return $items;
    }
}
