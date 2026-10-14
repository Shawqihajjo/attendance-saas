<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Domain;

final class TenantDatabaseNames
{
    public static function databaseName(): string
    {
        return 'tenant_'.bin2hex(random_bytes(16));
    }

    public static function username(): string
    {
        return 'tu_'.bin2hex(random_bytes(8));
    }

    public static function password(): string
    {
        return bin2hex(random_bytes(24));
    }
}
