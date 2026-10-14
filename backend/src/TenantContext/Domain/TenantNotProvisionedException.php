<?php

declare(strict_types=1);

namespace App\TenantContext\Domain;

final class TenantNotProvisionedException extends \RuntimeException
{
    public function __construct(string $tenantId)
    {
        parent::__construct(sprintf('Tenant "%s" has no database connection yet.', $tenantId));
    }
}
