<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Domain;

enum DeviceStatus: string
{
    case Pending = 'pending';
    case Registered = 'registered';
    case Active = 'active';
    case Suspended = 'suspended';
    case Revoked = 'revoked';
}
