<?php

declare(strict_types=1);

namespace App\Platform\Device;

use App\Platform\TenantRegistry\Infrastructure\Persistence\DeviceEnrollmentToken;
use App\Platform\TenantRegistry\Infrastructure\Persistence\DeviceRegistration;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\Shared\Time\UtcTime;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

final class DeviceEnrollmentIssuer
{
    public function __construct(
        private readonly TenantRegistryStore $store,
        private readonly DeviceCredentialStore $credentials,
        #[Autowire(service: 'doctrine.dbal.platform_connection')]
        private readonly Connection $platform,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
    }

    /**
     * @return array{token: string, expiresAt: \DateTimeImmutable}
     */
    public function issue(DeviceRegistration $device, string $purpose, \DateTimeImmutable $now): array
    {
        if ($purpose !== 'enroll' && $purpose !== 'rotate') {
            throw new DeviceManagementException('enrollment_rejected', 'The registration purpose is invalid.');
        }
        $this->credentials->invalidateUnusedTokens($device->id(), $now);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expires = $now->modify('+15 minutes');
        $this->store->persist(new DeviceEnrollmentToken(
            Uuid::v7()->toRfc4122(),
            $device->id(),
            $device->tenantId(),
            $this->hash($token),
            $purpose,
            $expires,
            $now,
        ));

        return ['token' => $token, 'expiresAt' => $expires];
    }

    /**
     * @return array{deviceId: string, tenantId: string, purpose: string}
     */
    public function consume(string $token, string $tenantId, \DateTimeImmutable $now): array
    {
        $token = trim($token);
        if (preg_match('/^[A-Za-z0-9_-]{32,128}$/', $token) !== 1) {
            throw new DeviceManagementException('enrollment_rejected', 'The registration code is not valid.', 422);
        }
        $hash = $this->hash($token);
        $row = $this->platform->fetchAssociative(
            'SELECT device_id, tenant_id, purpose, expires_at FROM device_enrollment_tokens WHERE token_hash = ? AND used_at IS NULL',
            [$hash],
        );
        if (!is_array($row) || !is_string($row['expires_at']) || UtcTime::parseStored($row['expires_at']) < UtcTime::instant($now)) {
            throw new DeviceManagementException('enrollment_rejected', 'The registration code is not valid.', 422);
        }
        if ((string) $row['tenant_id'] !== $tenantId) {
            throw new DeviceManagementException('tenant_mismatch', 'The terminal does not belong to this host.', 403);
        }
        $updated = $this->platform->executeStatement(
            'UPDATE device_enrollment_tokens SET used_at = ? WHERE token_hash = ? AND tenant_id = ? AND used_at IS NULL',
            [UtcTime::persist($now), $hash, $tenantId],
        );
        if ($updated !== 1) {
            throw new DeviceManagementException('enrollment_rejected', 'The registration code is not valid.', 422);
        }

        return [
            'deviceId' => (string) $row['device_id'],
            'tenantId' => (string) $row['tenant_id'],
            'purpose' => (string) $row['purpose'],
        ];
    }

    private function hash(string $token): string
    {
        return hash_hmac('sha256', $token, $this->secret);
    }
}
