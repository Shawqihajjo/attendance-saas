<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Infrastructure\Persistence;

use App\Platform\TenantRegistry\Domain\TenantLifecycleException;
use App\Platform\TenantRegistry\Domain\TenantStatus;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tenants')]
class Tenant
{
    #[ORM\Column(length: 32, enumType: TenantStatus::class)]
    private TenantStatus $status;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $provisionedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $suspendedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decommissionedAt = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 36)]
        private string $id,
        #[ORM\Column(length: 63)]
        private string $slug,
        #[ORM\Column(length: 200)]
        private string $legalName,
        \DateTimeImmutable $createdAt,
    ) {
        $this->status = TenantStatus::Provisioning;
        $this->updatedAt = $createdAt;
        $this->createdAt = $createdAt;
    }

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function id(): string
    {
        return $this->id;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function legalName(): string
    {
        return $this->legalName;
    }

    public function status(): TenantStatus
    {
        return $this->status;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function rename(string $legalName, \DateTimeImmutable $now): void
    {
        $this->legalName = $legalName;
        $this->updatedAt = $now;
    }

    public function markActive(\DateTimeImmutable $now): void
    {
        if ($this->status !== TenantStatus::Provisioning) {
            throw new TenantLifecycleException('Only a provisioning tenant can be activated.');
        }
        $this->status = TenantStatus::Active;
        $this->provisionedAt = $now;
        $this->updatedAt = $now;
    }

    public function suspend(\DateTimeImmutable $now): void
    {
        if ($this->status !== TenantStatus::Active) {
            throw new TenantLifecycleException('Only an active tenant can be suspended.');
        }
        $this->status = TenantStatus::Suspended;
        $this->suspendedAt = $now;
        $this->updatedAt = $now;
    }

    public function reactivate(\DateTimeImmutable $now): void
    {
        if ($this->status !== TenantStatus::Suspended) {
            throw new TenantLifecycleException('Only a suspended tenant can be reactivated.');
        }
        $this->status = TenantStatus::Active;
        $this->suspendedAt = null;
        $this->updatedAt = $now;
    }

    public function decommission(\DateTimeImmutable $now): void
    {
        if ($this->status !== TenantStatus::Active && $this->status !== TenantStatus::Suspended) {
            throw new TenantLifecycleException('Only an active or suspended tenant can be decommissioned.');
        }
        $this->status = TenantStatus::Decommissioned;
        $this->decommissionedAt = $now;
        $this->updatedAt = $now;
    }
}
