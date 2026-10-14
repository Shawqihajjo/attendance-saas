<?php

declare(strict_types=1);

namespace App\Media;

use Symfony\Component\Uid\Uuid;

final class MediaKey
{
    public static function for(string $tenantId, string $mediaId): string
    {
        self::uuid($tenantId);
        self::uuid($mediaId);

        return $tenantId.'/'.$mediaId;
    }

    public static function assert(string $tenantId, string $key): string
    {
        self::uuid($tenantId);
        $prefix = $tenantId.'/';
        if (!str_starts_with($key, $prefix) || str_contains($key, '..') || str_contains($key, '\\')) {
            throw new MediaRejectedException('The media reference is not usable.');
        }
        $mediaId = substr($key, strlen($prefix));
        self::uuid($mediaId);

        return $mediaId;
    }

    private static function uuid(string $value): void
    {
        if (!Uuid::isValid($value)) {
            throw new MediaRejectedException('The media reference is not usable.');
        }
    }
}
