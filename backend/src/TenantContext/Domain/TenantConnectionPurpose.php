<?php

declare(strict_types=1);

namespace App\TenantContext\Domain;

enum TenantConnectionPurpose: string
{
    /** HTTP requests and workers may use an active tenant only. */
    case Runtime = 'runtime';

    /** Platform maintenance may open a database that is not decommissioned. */
    case Maintenance = 'maintenance';
}
