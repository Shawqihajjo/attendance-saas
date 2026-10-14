<?php

declare(strict_types=1);

namespace App\Integration;

use App\TenantContext\Domain\TenantContextHolder;
use App\TenantContext\Domain\TenantStampMismatchException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'async')]
final class DeliverTenantIntegration
{
    public function __construct(
        private readonly TenantContextHolder $context,
        private readonly TenantIntegrationRegistry $registry,
        private readonly TenantIntegrationCatalog $catalog,
        private readonly IntegrationDeliveryRecorder $deliveries,
    ) {
    }

    public function __invoke(TenantDomainEventMessage $message): void
    {
        $current = $this->context->current();
        if ($current === null || $current->tenantId !== $message->tenantId) {
            throw new TenantStampMismatchException();
        }
        foreach ($this->registry->enabled($current->tenantId, $message->name) as $integration) {
            if ($integration['tenantId'] !== $current->tenantId) {
                throw new TenantStampMismatchException();
            }
            try {
                $this->catalog->deliver($integration['kind'], $message, $integration['config']);
                $this->deliveries->record($message, $integration['kind'], 'delivered', null);
            } catch (TenantStampMismatchException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                $this->deliveries->record($message, $integration['kind'], 'failed', $exception->getMessage());
                throw $exception;
            }
        }
    }
}
