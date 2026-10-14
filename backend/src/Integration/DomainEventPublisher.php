<?php

declare(strict_types=1);

namespace App\Integration;

use App\Shared\Time\UtcTime;
use App\TenantContext\Domain\TenantContextHolder;
use App\TenantContext\Domain\TenantDatabaseSwitcher;
use App\TenantContext\Domain\TenantStampMismatchException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class DomainEventPublisher
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly TenantContextHolder $context,
        private readonly TenantDatabaseSwitcher $switcher,
        private readonly IntegrationDeliveryRecorder $deliveries,
    ) {
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    public function publishForAuthorizedTenant(string $tenantId, string $name, string $subjectId, array $payload, \DateTimeImmutable $occurredAt): void
    {
        $current = $this->context->current();
        if ($current !== null && $current->tenantId !== $tenantId) {
            throw new TenantStampMismatchException();
        }
        if ($current !== null) {
            $this->publish($name, $subjectId, $payload, $occurredAt);

            return;
        }
        $this->switcher->switchTo($tenantId);
        try {
            $this->publish($name, $subjectId, $payload, $occurredAt);
        } finally {
            $this->switcher->reset();
        }
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    public function publish(string $name, string $subjectId, array $payload, \DateTimeImmutable $occurredAt): void
    {
        $current = $this->context->current();
        if ($current === null) {
            throw new TenantStampMismatchException();
        }
        $message = new TenantDomainEventMessage(
            Uuid::v7()->toRfc4122(),
            $current->tenantId,
            DomainEventName::require($name)->value,
            UtcTime::toIso($occurredAt),
            $subjectId,
            $payload,
        );
        try {
            $this->bus->dispatch($message);
        } catch (TransportException $exception) {
            $this->deliveries->record($message, 'queue', 'failed', $exception->getMessage());
        }
    }
}
