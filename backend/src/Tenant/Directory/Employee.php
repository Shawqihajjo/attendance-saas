<?php

declare(strict_types=1);

namespace App\Tenant\Directory;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'employees')]
class Employee
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 36)]
        private string $id,
        #[ORM\Column(length: 200)]
        private string $displayName,
        #[ORM\Column(length: 32)]
        private string $status,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(length: 32, nullable: true)]
        private ?string $employeeNumber = null,
        #[ORM\Column(length: 180, nullable: true)]
        private ?string $email = null,
        #[ORM\Column(length: 40, nullable: true)]
        private ?string $phone = null,
        #[ORM\Column(length: 36, nullable: true)]
        private ?string $userId = null,
        #[ORM\Column(length: 36, nullable: true)]
        private ?string $departmentId = null,
        #[ORM\Column(length: 36, nullable: true)]
        private ?string $teamId = null,
        #[ORM\Column(nullable: true)]
        private ?\DateTimeImmutable $updatedAt = null,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function employeeNumber(): ?string
    {
        return $this->employeeNumber;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function departmentId(): ?string
    {
        return $this->departmentId;
    }

    public function teamId(): ?string
    {
        return $this->teamId;
    }

    public function status(): EmploymentStatus
    {
        return EmploymentStatus::fromString($this->status);
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt ?? $this->createdAt;
    }

    public function rename(string $name, \DateTimeImmutable $now): void
    {
        $this->displayName = $name;
        $this->updatedAt = $now;
    }

    public function changeContact(?string $email, ?string $phone, \DateTimeImmutable $now): void
    {
        $this->email = $email;
        $this->phone = $phone;
        $this->updatedAt = $now;
    }

    public function place(?string $departmentId, ?string $teamId, \DateTimeImmutable $now): void
    {
        $this->departmentId = $departmentId;
        $this->teamId = $teamId;
        $this->updatedAt = $now;
    }

    public function changeStatus(EmploymentStatus $status, \DateTimeImmutable $now): void
    {
        $this->status = $status->value;
        $this->updatedAt = $now;
    }
}
