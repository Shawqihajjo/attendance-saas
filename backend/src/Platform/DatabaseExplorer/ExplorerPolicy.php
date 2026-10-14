<?php

declare(strict_types=1);

namespace App\Platform\DatabaseExplorer;

/**
 * Tables and columns the explorer must not return. Matching is exact and case-insensitive.
 */
final class ExplorerPolicy
{
    /** @var list<string> */
    private const HIDDEN_TABLES = [
        'tenant_database_connections',
        'platform_credentials',
        'user_credentials',
        'device_enrollment_tokens',
        'device_keys',
        'tenant_integrations',
        'fingerprint_enrollments',
        'employee_codes',
        'doctrine_migration_versions',
    ];

    /** @var list<string> */
    private const HIDDEN_COLUMNS = [
        'password',
        'password_hash',
        'password_ciphertext',
        'token',
        'token_hash',
        'ciphertext',
        'config_ciphertext',
        'private_key',
        'public_key',
        'template_reference',
        'storage_key',
        'secret',
    ];

    public function tableVisible(string $table): bool
    {
        if (!self::identifier($table) || str_starts_with(strtolower($table), 'sqlite_')) {
            return false;
        }

        return !in_array(strtolower($table), self::HIDDEN_TABLES, true);
    }

    public function columnVisible(string $column): bool
    {
        if (!self::identifier($column)) {
            return false;
        }

        return !in_array(strtolower($column), self::HIDDEN_COLUMNS, true);
    }

    public static function identifier(string $name): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name) === 1;
    }
}
