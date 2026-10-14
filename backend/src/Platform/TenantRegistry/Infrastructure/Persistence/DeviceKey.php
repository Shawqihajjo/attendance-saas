<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Infrastructure\Persistence;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'device_keys')]
class DeviceKey
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 36)]
        private string $id,
        #[ORM\Column(length: 36)]
        private string $deviceId,
        #[ORM\Column(length: 32)]
        private string $algorithm,
        #[ORM\Column(length: 128)]
        private string $publicKey,
        \DateTimeImmutable $createdAt,
    ) {
        $this->status = 'current';
        $this->createdAt = $createdAt;
    }

    #[ORM\Column(length: 32)]
    private string $status;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $retiredAt = null;

    public function id(): string
    {
        return $this->id;
    }

    public function retire(\DateTimeImmutable $now): void
    {
        $this->status = 'retired';
        $this->retiredAt = $now;
    }
}
