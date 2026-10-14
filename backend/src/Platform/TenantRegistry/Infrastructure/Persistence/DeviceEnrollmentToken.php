<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Infrastructure\Persistence;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'device_enrollment_tokens')]
class DeviceEnrollmentToken
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 36)]
        private string $id,
        #[ORM\Column(length: 36)]
        private string $deviceId,
        #[ORM\Column(length: 36)]
        private string $tenantId,
        #[ORM\Column(length: 64)]
        private string $tokenHash,
        #[ORM\Column(length: 32)]
        private string $purpose,
        #[ORM\Column]
        private \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $createdAt,
    ) {
        $this->createdAt = $createdAt;
    }

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function deviceId(): string
    {
        return $this->deviceId;
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function purpose(): string
    {
        return $this->purpose;
    }
}
