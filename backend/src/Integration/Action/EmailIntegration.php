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
final class EmailIntegration implements TenantIntegrationAction
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.tenant_connection')]
        private readonly Connection $connection,
    ) {
    }

    public function kind(): string
    {
        return 'email';
    }

    public function deliver(TenantDomainEventMessage $event, array $config): void
    {
        $to = $config['to'] ?? '';
        if ($to === '') {
            throw new \RuntimeException('The email recipient is invalid.');
        }
        $this->connection->insert('integration_outbox', [
            'id' => Uuid::v7()->toRfc4122(),
            'kind' => 'email',
            'recipient' => $to,
            'subject' => $event->name,
            'body' => $event->name.' '.$event->subjectId,
            'created_at' => UtcTime::persist(new \DateTimeImmutable('now', UtcTime::zone())),
        ]);
    }
}
