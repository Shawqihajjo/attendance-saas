<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Infrastructure\Persistence;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tenant_quotas')]
class TenantQuota
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 36)]
        private string $tenantId,
    ) {
    }

    #[ORM\Column(nullable: true)]
    private ?int $maxUsers = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxEmployees = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxActiveDevices = null;

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function maxUsers(): ?int
    {
        return $this->maxUsers;
    }

    public function maxEmployees(): ?int
    {
        return $this->maxEmployees;
    }

    public function maxActiveDevices(): ?int
    {
        return $this->maxActiveDevices;
    }

    public function replace(?int $maxUsers, ?int $maxEmployees, ?int $maxActiveDevices): void
    {
        $this->maxUsers = $maxUsers;
        $this->maxEmployees = $maxEmployees;
        $this->maxActiveDevices = $maxActiveDevices;
    }
}
