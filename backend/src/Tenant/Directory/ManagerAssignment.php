<?php

declare(strict_types=1);

namespace App\Tenant\Directory;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'manager_assignments')]
class ManagerAssignment
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 36)]
        private string $id,
        #[ORM\Column(length: 36)]
        private string $managerEmployeeId,
        #[ORM\Column(length: 36)]
        private string $employeeId,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public function managerEmployeeId(): string
    {
        return $this->managerEmployeeId;
    }

    public function employeeId(): string
    {
        return $this->employeeId;
    }
}
