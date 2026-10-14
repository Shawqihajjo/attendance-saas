<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Infrastructure\Persistence;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'platform_memberships')]
class PlatformMembership
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 36)]
        private string $id,
        #[ORM\Column(length: 36)]
        private string $userId,
        #[ORM\Column(length: 64)]
        private string $role,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function role(): string
    {
        return $this->role;
    }
}
