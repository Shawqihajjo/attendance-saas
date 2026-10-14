<?php

declare(strict_types=1);

namespace App\Integration\Action;

use App\Integration\TenantDomainEventMessage;
use App\Shared\Time\UtcTime;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

#[AutoconfigureTag('app.integration_action')]
final class NotificationIntegration implements TenantIntegrationAction
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.tenant_connection')]
        private readonly Connection $connection,
    ) {
    }

    public function kind(): string
    {
        return 'notification';
    }

    public function deliver(TenantDomainEventMessage $event, array $config): void
    {
        if (($config['audience'] ?? '') !== 'tenant') {
            throw new \RuntimeException('The notification audience is not supported.');
        }
        $this->connection->insert('integration_notifications', [
            'id' => Uuid::v7()->toRfc4122(),
            'event_name' => $event->name,
            'subject_id' => $event->subjectId,
            'message' => $event->name,
            'created_at' => UtcTime::persist(new \DateTimeImmutable('now', UtcTime::zone())),
        ]);
    }
}
