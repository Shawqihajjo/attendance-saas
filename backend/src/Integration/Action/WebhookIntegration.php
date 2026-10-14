<?php

declare(strict_types=1);

namespace App\Integration\Action;

use App\Integration\TenantDomainEventMessage;
use App\Integration\WebhookClient;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.integration_action')]
final class WebhookIntegration implements TenantIntegrationAction
{
    public function __construct(private readonly WebhookClient $client)
    {
    }

    public function kind(): string
    {
        return 'webhook';
    }

    public function deliver(TenantDomainEventMessage $event, array $config): void
    {
        $url = $config['url'] ?? '';
        if ($url === '') {
            throw new \RuntimeException('Webhook delivery failed.');
        }
        $this->client->post($url, json_encode([
            'eventId' => $event->eventId,
            'tenantId' => $event->tenantId,
            'name' => $event->name,
            'occurredAt' => $event->occurredAt,
            'subjectId' => $event->subjectId,
            'payload' => $event->payload,
        ], JSON_THROW_ON_ERROR));
    }
}
