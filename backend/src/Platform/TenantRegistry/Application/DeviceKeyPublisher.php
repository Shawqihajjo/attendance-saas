<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Application;

use App\Platform\TenantRegistry\Domain\TenantInputValidator;
use App\Platform\TenantRegistry\Domain\TenantLifecycleException;
use App\Platform\TenantRegistry\Infrastructure\Persistence\DeviceRegistration;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\Shared\Clock\UtcClock;
use App\Shared\Time\UtcTime;
use App\TenantContext\Domain\TenantDatabaseSwitcher;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

final class DeviceKeyPublisher
{
    public function __construct(
        private readonly TenantRegistryStore $store,
        private readonly TenantInputValidator $validator,
        private readonly TenantDatabaseSwitcher $switcher,
        #[Autowire(service: 'doctrine.dbal.tenant_connection')]
        private readonly Connection $tenant,
        private readonly PlatformAuditRecorder $audit,
        private readonly UtcClock $clock,
    ) {
    }

    public function publish(string $deviceId, string $publicKey, string $timezone, string $actorId): DeviceRegistration
    {
        if (!Uuid::isValid($deviceId)) {
            throw new TenantLifecycleException('Device was not found.');
        }
        $device = $this->store->findDevice($deviceId);
        if (!$device instanceof DeviceRegistration) {
            throw new TenantLifecycleException('Device was not found.');
        }
        $decoded = base64_decode($publicKey, true);
        if (!is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new TenantLifecycleException('The device public key is invalid.');
        }
        $timezone = $this->validator->timezone($timezone);
        $now = $this->clock->now();
        $device->publishKey(base64_encode($decoded), $now);
        $this->store->flush();
        $this->switcher->switchTo($device->tenantId());
        $projection = $this->tenant->fetchOne('SELECT id FROM devices WHERE platform_device_id = ?', [$device->id()]);
        if (!is_string($projection)) {
            $this->tenant->insert('devices', [
                'id' => Uuid::v7()->toRfc4122(),
                'platform_device_id' => $device->id(),
                'display_name' => $device->displayName(),
                'timezone' => $timezone,
                'created_at' => UtcTime::persist($now),
            ]);
        }
        $this->audit->record('device.key_published', 'device', $device->id(), $this->validator->actorId($actorId), [
            'tenant_id' => $device->tenantId(),
            'timezone' => $timezone,
        ]);

        return $device;
    }
}
