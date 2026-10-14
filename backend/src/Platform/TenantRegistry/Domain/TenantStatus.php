<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Domain;

enum TenantStatus: string
{
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Suspended = 'suspended';
    case Decommissioned = 'decommissioned';
}
