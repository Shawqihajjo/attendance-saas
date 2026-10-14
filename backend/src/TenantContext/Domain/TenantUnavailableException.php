<?php

declare(strict_types=1);

namespace App\TenantContext\Domain;

final class TenantUnavailableException extends \RuntimeException
{
    public function __construct(public readonly string $status)
    {
        parent::__construct(sprintf('Tenant status "%s" cannot be used.', $status));
    }
}
