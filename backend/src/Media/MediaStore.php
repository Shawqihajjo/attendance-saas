<?php

declare(strict_types=1);

namespace App\Media;

/**
 * Stores attendance photograph bytes. The key is an opaque tenant-scoped reference.
 * A later S3-compatible adapter can replace the local implementation without changing attendance records.
 */
interface MediaStore
{
    public function name(): string;

    public function write(string $tenantId, string $mediaId, string $bytes): string;

    public function read(string $tenantId, string $key): string;

    public function erase(string $tenantId, string $key): void;
}
