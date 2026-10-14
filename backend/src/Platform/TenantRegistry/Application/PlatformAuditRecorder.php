<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Application;

use App\Audit\AuditFacts;
use App\Audit\AuditMetadata;
use App\Platform\TenantRegistry\Infrastructure\Persistence\PlatformAuditEntry;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\Shared\Clock\UtcClock;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

final class PlatformAuditRecorder
{
    public function __construct(
        private readonly TenantRegistryStore $store,
        private readonly RequestStack $requests,
        private readonly UtcClock $clock,
        private readonly AuditFacts $facts,
    ) {
    }

    /**
     * @param array<string, scalar|null> $metadata
     */
    public function record(string $action, string $targetType, string $targetId, string $actorId, array $metadata, ?string $actorType = null): void
    {
        $clean = AuditMetadata::sanitize($metadata);
        $request = $this->requests->getCurrentRequest();
        $requestId = $request?->attributes->get('request_id');
        $this->store->persist(new PlatformAuditEntry(
            Uuid::v7()->toRfc4122(),
            $this->clock->now(),
            $actorType ?? ($actorId === 'cli' ? 'system' : 'platform_user'),
            substr($actorId, 0, 36),
            $action,
            $targetType,
            $targetId,
            is_string($requestId) && $requestId !== '' ? substr($requestId, 0, 64) : $this->facts->requestId(null),
            $this->facts->clientIp(),
            json_encode($clean, JSON_THROW_ON_ERROR),
            $this->facts->tenantId($metadata),
            AuditMetadata::result($action, $metadata),
            $this->facts->deviceId(),
        ));
        $this->store->flush();
    }
}
