<?php

declare(strict_types=1);

namespace App\Tenant\Directory;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'teams')]
class Team
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 36)]
        private string $id,
        #[ORM\Column(length: 36, nullable: true)]
        private ?string $departmentId,
        #[ORM\Column(length: 200)]
        private string $name,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function departmentId(): ?string
    {
        return $this->departmentId;
    }
}
