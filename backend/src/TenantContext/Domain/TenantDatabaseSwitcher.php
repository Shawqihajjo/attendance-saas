<?php

declare(strict_types=1);

namespace App\TenantContext\Domain;

interface TenantDatabaseSwitcher
{
    public function switchTo(string $tenantId): void;

    public function switchForMaintenance(string $tenantId): void;

    public function reset(): void;
}
