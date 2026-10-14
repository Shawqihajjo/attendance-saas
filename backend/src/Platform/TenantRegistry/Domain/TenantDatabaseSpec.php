<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Domain;

final readonly class TenantDatabaseSpec
{
    public function __construct(
        public string $databaseName,
        public string $username,
        public string $password,
    ) {
    }
}
