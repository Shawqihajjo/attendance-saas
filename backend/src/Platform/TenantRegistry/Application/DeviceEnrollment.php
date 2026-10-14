<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Application;

use App\Integration\DomainEventPublisher;
use App\Platform\TenantRegistry\Domain\DeviceStatus;
use App\Platform\TenantRegistry\Domain\TenantInputValidator;
use App\Platform\TenantRegistry\Domain\TenantLifecycleException;
use App\Platform\TenantRegistry\Domain\TenantStatus;
use App\Platform\TenantRegistry\Infrastructure\Persistence\DeviceRegistration;
use App\Platform\TenantRegistry\Infrastructure\Persistence\Tenant;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\Shared\Clock\UtcClock;
use App\TenantContext\Domain\TenantNotProvisionedException;
use Symfony\Component\Uid\Uuid;

final class DeviceEnrollment
{
    public function __construct(
        private readonly TenantRegistryStore $store,
        private readonly TenantInputValidator $validator,
        private readonly PlatformAuditRecorder $audit,
        private readonly UtcClock $clock,
        private readonly DomainEventPublisher $events,
        private readonly TenantQuotaService $quotas,
    ) {
    }

    public function register(string $slug, string $displayName, string $actorId): DeviceRegistration
    {
        $tenant = $this->store->findTenantBySlug($this->validator->slug($slug));
        if (!$tenant instanceof Tenant || $tenant->status() !== TenantStatus::Active) {
            throw new TenantNotProvisionedException($slug);
        }
        $displayName = $this->validator->legalName($displayName);
        $actorId = $this->validator->actorId($actorId);
        $device = new DeviceRegistration(Uuid::v7()->toRfc4122(), $tenant->id(), $displayName, $this->clock->now());
        $this->store->persist($device);
        $this->audit->record('device.registered', 'device', $device->id(), $actorId, [
            'tenant_id' => $tenant->id(),
            'status' => DeviceStatus::Pending->value,
        ]);
        $this->store->flush();
        $this->events->publishForAuthorizedTenant($tenant->id(), 'TerminalRegistered', $device->id(), [
            'deviceId' => $device->id(),
            'status' => $device->status()->value,
        ], $device->createdAt());

        return $device;
    }

    public function activate(string $deviceId, string $actorId): DeviceRegistration
    {
        if (!Uuid::isValid($deviceId)) {
            throw new TenantLifecycleException('Device was not found.');
        }
        $device = $this->store->findDevice($deviceId);
        if (!$device instanceof DeviceRegistration) {
            throw new TenantLifecycleException('Device was not found.');
        }
        if ($device->status() === DeviceStatus::Active) {
            return $device;
        }
        $this->quotas->claimActiveDevice($device, function () use ($device, $actorId): void {
            $device->activate($this->clock->now());
            $this->audit->record('device.activated', 'device', $device->id(), $this->validator->actorId($actorId), [
                'tenant_id' => $device->tenantId(),
                'status' => $device->status()->value,
            ]);
            $this->store->flush();
        });

        return $device;
    }

    public function activeTenantId(string $deviceId): string
    {
        if (!Uuid::isValid($deviceId)) {
            throw new TenantNotProvisionedException($deviceId);
        }
        $device = $this->store->findDevice($deviceId);
        if (!$device instanceof DeviceRegistration || $device->status() !== DeviceStatus::Active) {
            throw new TenantNotProvisionedException($deviceId);
        }

        return $device->tenantId();
    }
}
