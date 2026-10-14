<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Infrastructure\Persistence;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'platform_users')]
class PlatformUser
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 36)]
        private string $id,
        #[ORM\Column(length: 180)]
        private string $email,
        #[ORM\Column(length: 200)]
        private string $displayName,
        #[ORM\Column(length: 32)]
        private string $status,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }
}
