<?php

declare(strict_types=1);

namespace App\Platform\Device;

use App\Integration\DomainEventPublisher;
use App\Platform\TenantRegistry\Application\PlatformAuditRecorder;
use App\Platform\TenantRegistry\Application\TenantQuotaService;
use App\Platform\TenantRegistry\Domain\DeviceStatus;
use App\Platform\TenantRegistry\Domain\InvalidTenantInputException;
use App\Platform\TenantRegistry\Domain\TenantInputValidator;
use App\Platform\TenantRegistry\Domain\TenantLifecycleException;
use App\Platform\TenantRegistry\Infrastructure\Persistence\DeviceRegistration;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantDevicePolicy;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\Shared\Clock\UtcClock;
use App\Shared\Time\UtcTime;
use App\TenantContext\Domain\TenantDatabaseSwitcher;
use Symfony\Component\Uid\Uuid;

final class DeviceAdministration
{
    public function __construct(
        private readonly TenantRegistryStore $store,
        private readonly TenantInputValidator $validator,
        private readonly DeviceEnrollmentIssuer $enrollments,
        private readonly DeviceCredentialStore $credentials,
        private readonly DeviceProjection $projection,
        private readonly TenantDatabaseSwitcher $switcher,
        private readonly PlatformAuditRecorder $audit,
        private readonly UtcClock $clock,
        private readonly DomainEventPublisher $events,
        private readonly TenantQuotaService $quotas,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function register(
        string $tenantId,
        string $displayName,
        ?string $location,
        ?string $timezone,
        ?string $certificateRef,
        bool $activationRequired,
        string $actorId,
    ): array {
        $now = $this->clock->now();
        try {
            $displayName = $this->validator->legalName($displayName);
            $timezone = $this->validator->timezone($timezone ?? $this->tenantTimezone($tenantId));
            $actorId = $this->validator->actorId($actorId);
        } catch (InvalidTenantInputException $exception) {
            throw new DeviceManagementException('validation_failed', $exception->getMessage(), 422);
        }
        $device = new DeviceRegistration(Uuid::v7()->toRfc4122(), $tenantId, $displayName, $now);
        $device->describe($this->location($location), $this->certificate($certificateRef), $activationRequired, $now);
        $this->store->persist($device);
        $issued = $this->enrollments->issue($device, 'enroll', $now);
        $this->audit->record('device.registered', 'device', $device->id(), $actorId, [
            'status' => DeviceStatus::Pending->value,
            'timezone' => $timezone,
        ], 'tenant_user');
        $this->store->flush();
        $this->projection->save($device, $timezone);
        $view = $this->present($device, $timezone);
        $this->events->publish('TerminalRegistered', $device->id(), [
            'deviceId' => $device->id(),
            'status' => $device->status()->value,
        ], $now);
        $view['enrollmentToken'] = $issued['token'];
        $view['expiresAt'] = UtcTime::toIso($issued['expiresAt']);

        return $view;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(string $tenantId): array
    {
        $this->switcher->switchTo($tenantId);
        $timezones = $this->projection->timezones();
        $devices = [];
        foreach ($this->store->devicesForTenant($tenantId) as $device) {
            $devices[] = $this->present($device, $timezones[$device->id()] ?? null);
        }

        return $devices;
    }

    /**
     * @return array<string, mixed>
     */
    public function activate(string $tenantId, string $deviceId, string $actorId): array
    {
        $device = $this->requireOwned($tenantId, $deviceId);
        if ($device->status() === DeviceStatus::Active) {
            return $this->present($device, $this->timezoneOf($device));
        }
        if ($device->publicKey() === null || $device->publicKey() === '') {
            throw new DeviceManagementException('device_not_registered', 'The terminal has not finished registration.', 409);
        }
        $this->quotas->claimActiveDevice($device, function () use ($device, $actorId): void {
            try {
                $device->activate($this->clock->now());
            } catch (TenantLifecycleException $exception) {
                throw new DeviceManagementException('device_inactive', $exception->getMessage(), 409);
            }
            $this->audit->record('device.activated', 'device', $device->id(), $this->actor($actorId), [
                'status' => $device->status()->value,
            ], 'tenant_user');
            $this->store->flush();
        });
        $this->projection->save($device, $this->timezoneOf($device));

        return $this->present($device, $this->timezoneOf($device));
    }

    /**
     * @return array<string, mixed>
     */
    public function suspend(string $tenantId, string $deviceId, string $actorId): array
    {
        $device = $this->requireOwned($tenantId, $deviceId);
        try {
            $device->suspend($this->clock->now());
        } catch (TenantLifecycleException $exception) {
            throw new DeviceManagementException('device_inactive', $exception->getMessage(), 409);
        }
        $this->finish($device, 'device.suspended', $actorId);
        $this->events->publish('TerminalSuspended', $device->id(), [
            'deviceId' => $device->id(),
            'status' => $device->status()->value,
        ], $this->clock->now());

        return $this->present($device, $this->timezoneOf($device));
    }

    /**
     * @return array<string, mixed>
     */
    public function revoke(string $tenantId, string $deviceId, string $actorId): array
    {
        $device = $this->requireOwned($tenantId, $deviceId);
        $now = $this->clock->now();
        try {
            $device->revoke($now);
        } catch (TenantLifecycleException $exception) {
            throw new DeviceManagementException('device_inactive', $exception->getMessage(), 409);
        }
        $this->credentials->invalidateUnusedTokens($device->id(), $now);
        $this->finish($device, 'device.revoked', $actorId);

        return $this->present($device, $this->timezoneOf($device));
    }

    /**
     * @return array<string, mixed>
     */
    public function rotate(string $tenantId, string $deviceId, string $actorId): array
    {
        $device = $this->requireOwned($tenantId, $deviceId);
        if ($device->status() === DeviceStatus::Revoked) {
            throw new DeviceManagementException('device_inactive', 'This device is already revoked.', 409);
        }
        $purpose = $device->publicKey() === null ? 'enroll' : 'rotate';
        $issued = $this->enrollments->issue($device, $purpose, $this->clock->now());
        $this->audit->record('device.credential_rotation_started', 'device', $device->id(), $this->actor($actorId), [
            'status' => $device->status()->value,
            'purpose' => $purpose,
        ], 'tenant_user');
        $this->store->flush();
        $view = $this->present($device, $this->timezoneOf($device));
        $view['enrollmentToken'] = $issued['token'];
        $view['expiresAt'] = UtcTime::toIso($issued['expiresAt']);

        return $view;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function audit(string $tenantId, string $deviceId): array
    {
        $this->requireOwned($tenantId, $deviceId);
        $entries = [];
        foreach ($this->store->auditsFor($deviceId) as $entry) {
            $metadata = json_decode($entry->metadata(), true);
            $status = is_array($metadata) && is_string($metadata['status'] ?? null) ? $metadata['status'] : null;
            $entries[] = [
                'occurredAt' => UtcTime::toIso($entry->occurredAt()),
                'action' => $entry->action(),
                'actorType' => $entry->actorType(),
                'actorId' => $entry->actorId(),
                'status' => $status,
            ];
        }

        return $entries;
    }

    /**
     * @return array{allowedNetworks: list<string>, allowedIpRanges: list<string>, allowedAppVersions: list<string>}
     */
    public function policy(string $tenantId): array
    {
        return $this->store->findPolicy($tenantId)?->toArray() ?? [
            'allowedNetworks' => [],
            'allowedIpRanges' => [],
            'allowedAppVersions' => [],
        ];
    }

    /**
     * @param list<string> $networks
     * @param list<string> $ipRanges
     * @param list<string> $appVersions
     *
     * @return array{allowedNetworks: list<string>, allowedIpRanges: list<string>, allowedAppVersions: list<string>}
     */
    public function replacePolicy(string $tenantId, array $networks, array $ipRanges, array $appVersions, string $actorId): array
    {
        $networks = $this->names($networks, 120);
        $appVersions = $this->names($appVersions, 64);
        $ranges = [];
        foreach ($ipRanges as $range) {
            $range = trim($range);
            if ($range === '') {
                continue;
            }
            if (!IpRange::valid($range)) {
                throw new DeviceManagementException('validation_failed', 'An IP range is invalid.');
            }
            $ranges[] = $range;
        }
        if (count($ranges) > 20) {
            throw new DeviceManagementException('validation_failed', 'Too many IP ranges were provided.');
        }
        $now = $this->clock->now();
        $policy = $this->store->findPolicy($tenantId);
        if (!$policy instanceof TenantDevicePolicy) {
            $policy = new TenantDevicePolicy($tenantId, $now);
            $this->store->persist($policy);
        }
        $policy->replace($networks, array_values(array_unique($ranges)), $appVersions, $now);
        $this->audit->record('device.policy_changed', 'tenant', $tenantId, $this->actor($actorId), [
            'networks' => count($networks),
            'ipRanges' => count($ranges),
            'appVersions' => count($appVersions),
        ], 'tenant_user');
        $this->store->flush();

        return $policy->toArray();
    }

    private function finish(DeviceRegistration $device, string $action, string $actorId): void
    {
        $this->audit->record($action, 'device', $device->id(), $this->actor($actorId), [
            'status' => $device->status()->value,
        ], 'tenant_user');
        $this->store->flush();
        $this->projection->save($device, $this->timezoneOf($device));
    }

    private function requireOwned(string $tenantId, string $deviceId): DeviceRegistration
    {
        if (!Uuid::isValid($deviceId)) {
            throw new DeviceManagementException('device_not_found', 'Device was not found.', 404);
        }
        $device = $this->store->findDevice($deviceId);
        if (!$device instanceof DeviceRegistration || $device->tenantId() !== $tenantId) {
            throw new DeviceManagementException('device_not_found', 'Device was not found.', 404);
        }

        return $device;
    }

    private function timezoneOf(DeviceRegistration $device): string
    {
        $this->switcher->switchTo($device->tenantId());

        return $this->projection->timezoneFor($device->id()) ?? $this->tenantTimezone($device->tenantId());
    }

    private function tenantTimezone(string $tenantId): string
    {
        return $this->store->findSettings($tenantId)?->timezone() ?? 'Europe/Stockholm';
    }

    private function actor(string $actorId): string
    {
        try {
            return $this->validator->actorId($actorId);
        } catch (InvalidTenantInputException $exception) {
            throw new DeviceManagementException('validation_failed', $exception->getMessage(), 422);
        }
    }

    private function location(?string $location): ?string
    {
        if ($location === null) {
            return null;
        }
        $location = trim($location);
        if ($location === '') {
            return null;
        }
        if (strlen($location) > 200 || preg_match('/[\\x00-\\x1F\\x7F]/', $location)) {
            throw new DeviceManagementException('validation_failed', 'Location is invalid.');
        }

        return $location;
    }

    private function certificate(?string $certificateRef): ?string
    {
        if ($certificateRef === null || trim($certificateRef) === '') {
            return null;
        }
        $certificateRef = trim($certificateRef);
        if (preg_match('/^[A-Za-z0-9_.:\/-]{1,128}$/', $certificateRef) !== 1) {
            throw new DeviceManagementException('validation_failed', 'The certificate reference is invalid.');
        }

        return $certificateRef;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function names(array $values, int $max): array
    {
        if (count($values) > 20) {
            throw new DeviceManagementException('validation_failed', 'Too many policy values were provided.');
        }
        $names = [];
        foreach ($values as $value) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            if (strlen($value) > $max || preg_match('/[\\x00-\\x1F\\x7F]/', $value)) {
                throw new DeviceManagementException('validation_failed', 'A policy value is invalid.');
            }
            $names[$value] = $value;
        }

        return array_values($names);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DeviceRegistration $device, ?string $timezone): array
    {
        return [
            'id' => $device->id(),
            'deviceUuid' => $device->id(),
            'displayName' => $device->displayName(),
            'location' => $device->location(),
            'status' => $device->status()->value,
            'timezone' => $timezone,
            'credentialRef' => $device->credentialRef(),
            'certificateRef' => $device->certificateRef(),
            'macAddress' => $device->macAddress(),
            'lastPeerIp' => $device->lastPeerIp(),
            'networkName' => $device->networkName(),
            'hostname' => $device->hostname(),
            'osInfo' => $device->osInfo(),
            'appVersion' => $device->appVersion(),
            'lastHeartbeatAt' => $this->iso($device->lastHeartbeatAt()),
            'createdAt' => UtcTime::toIso($device->createdAt()),
            'updatedAt' => UtcTime::toIso($device->updatedAt()),
            'registeredAt' => $this->iso($device->registeredAt()),
            'suspendedAt' => $this->iso($device->suspendedAt()),
            'revokedAt' => $this->iso($device->revokedAt()),
            'activationRequired' => $device->activationRequired(),
        ];
    }

    private function iso(?\DateTimeImmutable $value): ?string
    {
        return $value instanceof \DateTimeImmutable ? UtcTime::toIso($value) : null;
    }
}
