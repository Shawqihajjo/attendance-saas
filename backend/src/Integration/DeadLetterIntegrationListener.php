<?php

declare(strict_types=1);

namespace App\Integration;

use App\TenantContext\Domain\TenantDatabaseSwitcher;
use App\TenantContext\Infrastructure\Messenger\TenantStamp;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

#[AsEventListener]
final class DeadLetterIntegrationListener
{
    public function __construct(
        private readonly TenantDatabaseSwitcher $switcher,
        private readonly IntegrationDeliveryRecorder $deliveries,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }
        $message = $event->getEnvelope()->getMessage();
        $stamp = $event->getEnvelope()->last(TenantStamp::class);
        if (!$message instanceof TenantDomainEventMessage || !$stamp instanceof TenantStamp) {
            return;
        }
        $this->switcher->switchTo($stamp->tenantId);
        try {
            $this->deliveries->record($message, 'rejected', 'dead', $event->getThrowable()->getMessage());
        } finally {
            $this->switcher->reset();
        }
    }
}
