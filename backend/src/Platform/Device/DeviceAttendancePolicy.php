<?php

declare(strict_types=1);

namespace App\Platform\Device;

use App\Platform\TenantRegistry\Domain\DeviceStatus;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\Tenant\Attendance\TerminalRejectedException;

/**
 * Attendance is accepted only for an active device whose tenant policy, when configured, matches this call.
 * An empty policy adds no restriction. MAC address and process id are not inputs.
 */
final class DeviceAttendancePolicy
{
    public function __construct(private readonly TenantRegistryStore $store)
    {
    }

    public function assert(string $tenantId, string $deviceId, DeviceCall $call): void
    {
        $device = $this->store->findDevice($deviceId);
        if ($device === null || $device->tenantId() !== $tenantId || $device->publicKey() === null || $device->publicKey() === '') {
            throw new TerminalRejectedException('device_rejected', 'The terminal is not an active enrolled device.', 401);
        }
        if ($device->status() !== DeviceStatus::Active) {
            throw new TerminalRejectedException('device_inactive', 'The terminal is not active.', 403);
        }
        $policy = $this->store->findPolicy($tenantId);
        if ($policy === null) {
            return;
        }
        $rules = $policy->toArray();
        if ($rules['allowedIpRanges'] !== [] && !$this->ipAllowed($call->peerIp, $rules['allowedIpRanges'])) {
            throw new TerminalRejectedException('device_policy', 'The terminal network is not allowed.', 403);
        }
        if ($rules['allowedNetworks'] !== [] && ($call->network === null || !in_array($call->network, $rules['allowedNetworks'], true))) {
            throw new TerminalRejectedException('device_policy', 'The terminal network is not allowed.', 403);
        }
        if ($rules['allowedAppVersions'] !== [] && ($call->appVersion === null || !in_array($call->appVersion, $rules['allowedAppVersions'], true))) {
            throw new TerminalRejectedException('device_policy', 'This terminal version is not allowed.', 403);
        }
    }

    /**
     * @param list<string> $ranges
     */
    private function ipAllowed(?string $ip, array $ranges): bool
    {
        if ($ip === null) {
            return false;
        }
        foreach ($ranges as $range) {
            if (IpRange::contains($ip, $range)) {
                return true;
            }
        }

        return false;
    }
}
