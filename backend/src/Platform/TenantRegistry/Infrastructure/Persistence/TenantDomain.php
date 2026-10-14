<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Infrastructure\Persistence;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tenant_domains')]
class TenantDomain
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 36)]
        private string $id,
        #[ORM\Column(length: 36)]
        private string $tenantId,
        #[ORM\Column(length: 253)]
        private string $hostname,
        #[ORM\Column]
        private bool $isPrimary,
        #[ORM\Column(nullable: true)]
        private ?\DateTimeImmutable $verifiedAt,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function hostname(): string
    {
        return $this->hostname;
    }

    public function changeHostname(string $hostname): void
    {
        $this->hostname = $hostname;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function isVerified(): bool
    {
        return $this->verifiedAt !== null;
    }
}
