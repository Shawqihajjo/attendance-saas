<?php

declare(strict_types=1);

namespace App\Integration\Action;

use App\Identity\Infrastructure\Persistence\TenantAccountStore;
use App\Integration\TenantDomainEventMessage;
use App\Shared\Time\UtcTime;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.integration_action')]
final class InternalIntegration implements TenantIntegrationAction
{
    public function __construct(private readonly TenantAccountStore $accounts)
    {
    }

    public function kind(): string
    {
        return 'internal';
    }

    public function deliver(TenantDomainEventMessage $event, array $config): void
    {
        if (($config['handler'] ?? '') !== 'audit') {
            throw new \RuntimeException('The internal integration is not supported.');
        }
        $this->accounts->audit(
            'integration.internal',
            'system',
            $event->tenantId,
            'domain_event',
            $event->eventId,
            null,
            [
                'event' => $event->name,
                'subjectId' => $event->subjectId,
            ],
            new \DateTimeImmutable('now', UtcTime::zone()),
        );
    }
}
