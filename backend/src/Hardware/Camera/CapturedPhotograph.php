<?php

declare(strict_types=1);

namespace App\Hardware\Camera;

/**
 * Photograph bytes for one attempt. Logs and JSON see the type and the length.
 */
final readonly class CapturedPhotograph implements \JsonSerializable
{
    public function __construct(
        public string $contentType,
        private string $bytes,
    ) {
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    /**
     * @return array{contentType: string, byteLength: int}
     */
    public function redacted(): array
    {
        return ['contentType' => $this->contentType, 'byteLength' => strlen($this->bytes)];
    }

    /**
     * @return array{contentType: string, byteLength: int}
     */
    public function jsonSerialize(): array
    {
        return $this->redacted();
    }

    /**
     * @return array{contentType: string, byteLength: int}
     */
    public function __debugInfo(): array
    {
        return $this->redacted();
    }
}
