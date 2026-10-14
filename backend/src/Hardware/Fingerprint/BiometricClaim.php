<?php

declare(strict_types=1);

namespace App\Hardware\Fingerprint;

/**
 * Opaque biometric material for one verification call.
 * Logs and JSON see the vendor and the length, not the payload.
 */
final readonly class BiometricClaim implements \JsonSerializable
{
    public function __construct(
        public string $vendor,
        private string $payload,
    ) {
    }

    public function payload(): string
    {
        return $this->payload;
    }

    /**
     * @return array{vendor: string, byteLength: int}
     */
    public function redacted(): array
    {
        return ['vendor' => $this->vendor, 'byteLength' => strlen($this->payload)];
    }

    /**
     * @return array{vendor: string, byteLength: int}
     */
    public function jsonSerialize(): array
    {
        return $this->redacted();
    }

    /**
     * @return array{vendor: string, byteLength: int}
     */
    public function __debugInfo(): array
    {
        return $this->redacted();
    }
}
