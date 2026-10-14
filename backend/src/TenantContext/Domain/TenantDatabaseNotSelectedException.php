<?php

declare(strict_types=1);

namespace App\TenantContext\Domain;

final class TenantDatabaseNotSelectedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The tenant database connection is not selected.');
    }
}
