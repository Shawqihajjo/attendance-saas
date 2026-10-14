<?php

declare(strict_types=1);

namespace App\Tenant\Directory;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'employee_codes')]
class AttendanceIdentifier
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 36)]
        private string $employeeId,
        #[ORM\Column(name: 'code', length: 64)]
        private string $hash,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public function employeeId(): string
    {
        return $this->employeeId;
    }

    public function hash(): string
    {
        return $this->hash;
    }
}
