<?php

declare(strict_types=1);

namespace App\Audit;

/**
 * Metadata stored beside an audit row. Secret material is dropped before it is written.
 */
final class AuditMetadata
{
    /**
     * @param array<mixed, mixed> $metadata
     *
     * @return array<string, scalar|null>
     */
    public static function sanitize(array $metadata): array
    {
        $clean = [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || self::forbidden($key) || !self::scalar($value)) {
                continue;
            }
            if (is_string($value)) {
                $value = preg_replace('/[\\x00-\\x1F\\x7F]/', '', $value) ?? '';
                if ($value === '' || self::looksSecret($value)) {
                    continue;
                }
            }
            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function result(string $action, array $metadata): string
    {
        $explicit = $metadata['result'] ?? null;
        if ($explicit === 'failure' || $explicit === 'success') {
            return $explicit;
        }
        if (preg_match('/failed|rejected|blocked|denied|expired/i', $action) === 1) {
            return 'failure';
        }

        return 'success';
    }

    private static function forbidden(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $key));
        if (in_array($normalized, ['code', 'image', 'bytes', 'pid', 'process_id', 'tenantid', 'tenant_id', 'result'], true)) {
            return true;
        }
        foreach (['password', 'secret', 'token', 'private', 'public_key', 'publickey', 'fingerprint', 'biometric', 'ciphertext', 'seed', 'authorization', 'cookie', 'session', 'signature', 'photograph', 'sample', 'template'] as $part) {
            if (str_contains($normalized, $part)) {
                return true;
            }
        }

        return false;
    }

    private static function looksSecret(string $value): bool
    {
        return preg_match('/-----BEGIN |eyJ[A-Za-z0-9_-]{20,}\\./', $value) === 1;
    }

    private static function scalar(mixed $value): bool
    {
        return is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null;
    }
}
