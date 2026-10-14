<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Domain;

interface TenantDatabaseProvisioner
{
    public function provision(TenantDatabaseSpec $spec): void;

    public function drop(string $databaseName, string $username): void;
}
