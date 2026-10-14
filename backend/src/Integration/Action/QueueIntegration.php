<?php

declare(strict_types=1);

namespace App\Integration\Action;

use App\Integration\TenantDomainEventMessage;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.integration_action')]
final class QueueIntegration implements TenantIntegrationAction
{
    public function kind(): string
    {
        return 'queue';
    }

    public function deliver(TenantDomainEventMessage $event, array $config): void
    {
        if (($config['name'] ?? '') !== 'attendance') {
            throw new \RuntimeException('The integration queue is not supported.');
        }
    }
}
