<?php

declare(strict_types=1);

namespace App\Platform\Device;

use App\Platform\TenantRegistry\Infrastructure\Persistence\DeviceRegistration;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\Shared\Time\UtcTime;
use App\TenantContext\Domain\TenantDatabaseSwitcher;

final class DeviceHeartbeatRecorder
{
    public function __construct(
        private readonly TenantRegistryStore $store,
        private readonly DeviceProjection $projection,
        private readonly DeviceSignalParser $signals,
        private readonly TenantDatabaseSwitcher $switcher,
    ) {
    }

    /**
     * @param array<string, mixed> $reported
     *
     * @return array{observedAt: string, status: string}
     */
    public function record(DeviceRegistration $device, array $reported, ?string $peerIp, \DateTimeImmutable $now): array
    {
        $parsed = $this->signals->parse($reported);
        $device->recordHeartbeat(
            $parsed['hostname'],
            $parsed['osInfo'],
            $parsed['appVersion'],
            $parsed['macAddress'],
            $parsed['networkName'],
            $peerIp,
            $now,
        );
        $this->store->flush();
        $this->switcher->switchTo($device->tenantId());
        $timezone = $this->projection->timezoneFor($device->id()) ?? ($this->store->findSettings($device->tenantId())?->timezone() ?? 'Europe/Stockholm');
        $this->projection->save($device, $timezone);
        $fingerprint = $reported['fingerprintAdapter'] ?? null;
        $camera = $reported['cameraAdapter'] ?? null;
        $this->projection->recordHeartbeat(
            $device->id(),
            $now,
            $peerIp,
            $parsed['macAddress'],
            $parsed['hostname'],
            $parsed['osInfo'],
            $parsed['appVersion'],
            $parsed['networkName'],
            is_string($fingerprint) ? substr(trim($fingerprint), 0, 64) : null,
            is_string($camera) ? substr(trim($camera), 0, 64) : null,
        );

        return [
            'observedAt' => UtcTime::toIso($now),
            'status' => $device->status()->value,
        ];
    }
}
