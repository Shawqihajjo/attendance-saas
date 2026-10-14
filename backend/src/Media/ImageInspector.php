<?php

declare(strict_types=1);

namespace App\Media;

/**
 * Accepts a JPEG or PNG whose declared type matches its signature.
 * Active-content markers are rejected. This is not a full malware scanner.
 */
final class ImageInspector
{
    public const MAX_BYTES = 2_000_000;

    public function inspect(string $contentType, string $bytes, bool $complete = true): string
    {
        if (str_contains($contentType, "\0") || strlen($bytes) < 8 || strlen($bytes) > self::MAX_BYTES) {
            throw new MediaRejectedException('The photograph is empty or too large.');
        }
        $type = strtolower(trim($contentType));
        if ($type === '' || !str_contains($type, '/')) {
            throw new MediaRejectedException('The photograph is empty or too large.');
        }
        if (preg_match('/<\?php|<script|<html|javascript:/i', $bytes) === 1) {
            throw new MediaRejectedException('The photograph content is not an image.');
        }
        $jpeg = str_starts_with($bytes, "\xFF\xD8\xFF") && (!$complete || str_ends_with($bytes, "\xFF\xD9"));
        $png = str_starts_with($bytes, "\x89PNG\r\n\x1a\n") && (!$complete || substr($bytes, 12, 4) === 'IHDR');
        if ($type === 'image/jpeg' && $jpeg) {
            return 'image/jpeg';
        }
        if ($type === 'image/png' && $png) {
            return 'image/png';
        }

        throw new MediaRejectedException('The photograph must be a JPEG or PNG image.');
    }
}
