<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Infrastructure\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class SodiumCredentialCipher
{
    public function __construct(
        #[Autowire('%app.tenant_db_credentials_key%')]
        private readonly string $keyBase64,
        #[Autowire('%app.tenant_db_credentials_key_id%')]
        private readonly string $keyId,
    ) {
    }

    public function keyId(): string
    {
        return $this->keyId;
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key());

        return base64_encode($nonce.$cipher);
    }

    public function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);
        $minimum = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
        if (!is_string($raw) || strlen($raw) < $minimum) {
            throw new \RuntimeException('Tenant database credential could not be decrypted.');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key());
        if (!is_string($plain)) {
            throw new \RuntimeException('Tenant database credential could not be decrypted.');
        }

        return $plain;
    }

    private function key(): string
    {
        $key = base64_decode($this->keyBase64, true);
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \LogicException('TENANT_DB_CREDENTIALS_KEY must be a base64-encoded 32-byte key.');
        }

        return $key;
    }
}
