<?php

declare(strict_types=1);

namespace App\Integration;

use App\Identity\Infrastructure\Persistence\TenantAccountStore;
use App\Shared\Time\UtcTime;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

final class IntegrationDeliveryRecorder
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.tenant_connection')]
        private readonly Connection $connection,
        private readonly TenantAccountStore $accounts,
    ) {
    }

    public function record(TenantDomainEventMessage $message, string $kind, string $status, ?string $error): void
    {
        $now = new \DateTimeImmutable('now', UtcTime::zone());
        $this->connection->insert('integration_deliveries', [
            'id' => Uuid::v7()->toRfc4122(),
            'integration_type' => $kind,
            'status' => $status,
            'created_at' => UtcTime::persist($now),
            'event_name' => $message->name,
            'event_id' => $message->eventId,
            'tenant_id' => $message->tenantId,
            'attempt' => 1,
            'error_message' => $this->safe($error),
        ]);
        if ($status === 'delivered') {
            return;
        }
        $this->accounts->audit(
            $status === 'dead' ? 'integration.delivery_dead' : 'integration.delivery_failed',
            'system',
            $message->tenantId,
            'domain_event',
            $message->eventId,
            null,
            [
                'event' => $message->name,
                'kind' => $kind,
                'status' => $status,
            ],
            $now,
        );
    }

    private function safe(?string $error): ?string
    {
        if ($error === null || $error === '') {
            return null;
        }
        $error = preg_replace('/[\\x00-\\x1F\\x7F]/', '', $error) ?? '';
        if ($error === '' || preg_match('/password|ciphertext|secret|token/i', $error) === 1) {
            return 'Integration delivery failed.';
        }

        return substr($error, 0, 200);
    }
}
