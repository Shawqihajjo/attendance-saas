<?php

declare(strict_types=1);

namespace App\Platform\Device;

use App\Platform\TenantRegistry\Infrastructure\Persistence\DeviceRegistration;
use App\Shared\Time\UtcTime;
use App\TenantContext\Domain\TenantDatabaseSwitcher;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

final class DeviceProjection
{
    public function __construct(
        private readonly TenantDatabaseSwitcher $switcher,
        #[Autowire(service: 'doctrine.dbal.tenant_connection')]
        private readonly Connection $tenant,
    ) {
    }

    public function save(DeviceRegistration $device, string $timezone): void
    {
        $this->switcher->switchTo($device->tenantId());
        $existing = $this->tenant->fetchOne('SELECT id FROM devices WHERE platform_device_id = ?', [$device->id()]);
        $values = [
            'display_name' => $device->displayName(),
            'location' => $device->location(),
            'timezone' => $timezone,
            'status' => $device->status()->value,
            'hostname' => $device->hostname(),
            'os_info' => $device->osInfo(),
            'app_version' => $device->appVersion(),
            'mac_address' => $device->macAddress(),
            'network_name' => $device->networkName(),
            'peer_ip' => $device->lastPeerIp(),
            'last_heartbeat_at' => $device->lastHeartbeatAt() instanceof \DateTimeImmutable ? UtcTime::persist($device->lastHeartbeatAt()) : null,
            'updated_at' => UtcTime::persist($device->updatedAt()),
            'suspended_at' => $device->suspendedAt() instanceof \DateTimeImmutable ? UtcTime::persist($device->suspendedAt()) : null,
            'revoked_at' => $device->revokedAt() instanceof \DateTimeImmutable ? UtcTime::persist($device->revokedAt()) : null,
        ];
        if (!is_string($existing)) {
            $this->tenant->insert('devices', $values + [
                'id' => Uuid::v7()->toRfc4122(),
                'platform_device_id' => $device->id(),
                'created_at' => UtcTime::persist($device->createdAt()),
            ]);

            return;
        }
        $this->tenant->update('devices', $values, ['id' => $existing]);
    }

    public function timezoneFor(string $platformDeviceId): ?string
    {
        $timezone = $this->tenant->fetchOne('SELECT timezone FROM devices WHERE platform_device_id = ?', [$platformDeviceId]);

        return is_string($timezone) ? $timezone : null;
    }

    /**
     * @return array<string, string>
     */
    public function timezones(): array
    {
        $rows = $this->tenant->fetchAllAssociative('SELECT platform_device_id, timezone FROM devices');
        $timezones = [];
        foreach ($rows as $row) {
            if (is_string($row['platform_device_id'] ?? null) && is_string($row['timezone'] ?? null)) {
                $timezones[$row['platform_device_id']] = $row['timezone'];
            }
        }

        return $timezones;
    }

    public function recordHeartbeat(
        string $platformDeviceId,
        \DateTimeImmutable $observedAt,
        ?string $peerIp,
        ?string $macAddress,
        ?string $hostname,
        ?string $osInfo,
        ?string $appVersion,
        ?string $network,
        ?string $fingerprintAdapter,
        ?string $cameraAdapter,
    ): void {
        $this->tenant->insert('device_heartbeats', [
            'id' => Uuid::v7()->toRfc4122(),
            'device_id' => $platformDeviceId,
            'observed_at' => UtcTime::persist($observedAt),
            'payload' => json_encode([
                'peerIp' => $peerIp,
                'macAddress' => $macAddress,
                'hostname' => $hostname,
                'osInfo' => $osInfo,
                'appVersion' => $appVersion,
                'network' => $network,
                'fingerprintAdapter' => $fingerprintAdapter,
                'cameraAdapter' => $cameraAdapter,
            ], JSON_THROW_ON_ERROR),
        ]);
    }
}
