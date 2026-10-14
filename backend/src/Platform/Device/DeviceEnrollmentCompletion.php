<?php

declare(strict_types=1);

namespace App\Platform\Device;

use App\Platform\TenantRegistry\Application\PlatformAuditRecorder;
use App\Platform\TenantRegistry\Application\TenantQuotaService;
use App\Platform\TenantRegistry\Domain\DeviceStatus;
use App\Platform\TenantRegistry\Domain\TenantLifecycleException;
use App\Platform\TenantRegistry\Infrastructure\Persistence\DeviceRegistration;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\TenantContext\Domain\TenantDatabaseSwitcher;

final class DeviceEnrollmentCompletion
{
    public function __construct(
        private readonly DeviceEnrollmentIssuer $enrollments,
        private readonly DeviceCredentialStore $credentials,
        private readonly DeviceProjection $projection,
        private readonly TenantRegistryStore $store,
        private readonly PlatformAuditRecorder $audit,
        private readonly DeviceSignalParser $signals,
        private readonly TenantDatabaseSwitcher $switcher,
        private readonly TenantQuotaService $quotas,
    ) {
    }

    /**
     * @param array<string, mixed> $reported
     *
     * @return array<string, mixed>
     */
    public function complete(string $tenantId, string $token, string $publicKey, array $reported, ?string $peerIp, \DateTimeImmutable $now): array
    {
        $this->credentials->assertPublicKey($publicKey);
        $device = $this->quotas->withinPlatformTransaction(function () use ($token, $tenantId, $publicKey, $reported, $peerIp, $now): DeviceRegistration {
            $consumed = $this->enrollments->consume($token, $tenantId, $now);
            $device = $this->store->findDevice($consumed['deviceId']);
            if (!$device instanceof DeviceRegistration || $device->tenantId() !== $tenantId) {
                throw new DeviceManagementException('enrollment_rejected', 'The registration code is not valid.');
            }
            $parsed = $this->signals->parse($reported);
            if ($consumed['purpose'] === 'enroll') {
                $this->enroll($device, $publicKey, $parsed, $peerIp, $now);
            } elseif ($consumed['purpose'] === 'rotate') {
                $this->rotate($device, $publicKey, $parsed, $peerIp, $now);
            } else {
                throw new DeviceManagementException('enrollment_rejected', 'The registration code is not valid.');
            }
            $this->store->flush();

            return $device;
        });
        $this->switcher->switchTo($device->tenantId());
        $timezone = $this->projection->timezoneFor($device->id()) ?? ($this->store->findSettings($device->tenantId())?->timezone() ?? 'Europe/Stockholm');
        $this->projection->save($device, $timezone);

        return [
            'deviceId' => $device->id(),
            'status' => $device->status()->value,
            'terminalTimezone' => $timezone,
        ];
    }

    /**
     * @param array{hostname: ?string, osInfo: ?string, appVersion: ?string, macAddress: ?string, networkName: ?string} $parsed
     */
    private function enroll(DeviceRegistration $device, string $publicKey, array $parsed, ?string $peerIp, \DateTimeImmutable $now): void
    {
        if ($device->status() !== DeviceStatus::Pending) {
            throw new DeviceManagementException('enrollment_rejected', 'This terminal is not waiting for registration.', 409);
        }
        $this->credentials->install($device, $publicKey, $now);
        $device->noteSignals($parsed['hostname'], $parsed['osInfo'], $parsed['appVersion'], $parsed['macAddress'], $parsed['networkName'], $peerIp, $now);
        try {
            $device->completeRegistration($now);
            if (!$device->activationRequired()) {
                $this->quotas->claimActiveDevice($device, function () use ($device, $now): void {
                    $device->activate($now);
                });
            }
        } catch (TenantLifecycleException $exception) {
            throw new DeviceManagementException('enrollment_rejected', $exception->getMessage(), 409);
        }
        $this->audit->record('device.enrollment_completed', 'device', $device->id(), $device->id(), [
            'status' => $device->status()->value,
        ], 'device');
    }

    /**
     * @param array{hostname: ?string, osInfo: ?string, appVersion: ?string, macAddress: ?string, networkName: ?string} $parsed
     */
    private function rotate(DeviceRegistration $device, string $publicKey, array $parsed, ?string $peerIp, \DateTimeImmutable $now): void
    {
        if ($device->status() === DeviceStatus::Revoked || $device->publicKey() === null) {
            throw new DeviceManagementException('enrollment_rejected', 'This terminal cannot rotate its credential.', 409);
        }
        $this->credentials->install($device, $publicKey, $now);
        $device->noteSignals($parsed['hostname'], $parsed['osInfo'], $parsed['appVersion'], $parsed['macAddress'], $parsed['networkName'], $peerIp, $now);
        $this->audit->record('device.credential_rotated', 'device', $device->id(), $device->id(), [
            'status' => $device->status()->value,
        ], 'device');
    }
}
