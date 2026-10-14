<?php

declare(strict_types=1);

namespace App\Platform\Device;

use App\Platform\TenantRegistry\Infrastructure\Persistence\DeviceKey;
use App\Platform\TenantRegistry\Infrastructure\Persistence\DeviceRegistration;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\Shared\Time\UtcTime;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

final class DeviceCredentialStore
{
    public function __construct(
        private readonly TenantRegistryStore $store,
        #[Autowire(service: 'doctrine.dbal.platform_connection')]
        private readonly Connection $platform,
    ) {
    }

    public function assertPublicKey(string $publicKey): void
    {
        $this->decode($publicKey);
    }

    public function install(DeviceRegistration $device, string $publicKey, \DateTimeImmutable $now): string
    {
        $encoded = base64_encode($this->decode($publicKey));
        foreach ($this->store->currentKeys($device->id()) as $key) {
            $key->retire($now);
        }
        $id = Uuid::v7()->toRfc4122();
        $this->store->persist(new DeviceKey($id, $device->id(), 'ed25519', $encoded, $now));
        $device->publishKey($encoded, $now, $id);

        return $id;
    }

    private function decode(string $publicKey): string
    {
        $decoded = base64_decode($publicKey, true);
        if (!is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new DeviceManagementException('enrollment_rejected', 'The device public key is invalid.');
        }

        return $decoded;
    }

    public function invalidateUnusedTokens(string $deviceId, \DateTimeImmutable $now): void
    {
        $this->platform->executeStatement(
            'UPDATE device_enrollment_tokens SET used_at = ? WHERE device_id = ? AND used_at IS NULL',
            [UtcTime::persist($now), $deviceId],
        );
    }
}
