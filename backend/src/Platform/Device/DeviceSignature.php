<?php

declare(strict_types=1);

namespace App\Platform\Device;

final class DeviceSignature
{
    public static function payload(string $timestamp, string $nonce, string $method, string $path, string $bodyHash): string
    {
        return implode("\n", ['v1', $timestamp, $nonce, strtoupper($method), $path, $bodyHash]);
    }

    public static function bodyHash(string $body): string
    {
        return hash('sha256', $body);
    }

    public static function verify(string $payload, string $signature, string $publicKey): bool
    {
        $signatureBytes = base64_decode($signature, true);
        $publicBytes = base64_decode($publicKey, true);
        if (!is_string($signatureBytes) || !is_string($publicBytes)) {
            return false;
        }
        if (strlen($signatureBytes) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($publicBytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached($signatureBytes, $payload, $publicBytes);
        } catch (\SodiumException) {
            return false;
        }
    }
}
