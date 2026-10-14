<?php

declare(strict_types=1);

namespace App\TenantContext\Domain;

final class TenantContextHolder
{
    private ?TenantContext $context = null;

    public function set(TenantContext $context): void
    {
        $this->context = $context;
    }

    public function clear(): void
    {
        $this->context = null;
    }

    public function current(): ?TenantContext
    {
        return $this->context;
    }
}
