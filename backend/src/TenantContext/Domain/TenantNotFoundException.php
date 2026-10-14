<?php

declare(strict_types=1);

namespace App\TenantContext\Domain;

final class TenantNotFoundException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The host is not a tenant.');
    }
}
