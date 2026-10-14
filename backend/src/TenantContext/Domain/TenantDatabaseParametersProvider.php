<?php

declare(strict_types=1);

namespace App\TenantContext\Domain;

interface TenantDatabaseParametersProvider
{
    public function getProfile(string $tenantId, TenantConnectionPurpose $purpose): TenantConnectionProfile;
}
