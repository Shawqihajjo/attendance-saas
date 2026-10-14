<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Infrastructure\Persistence;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'platform_audit_log')]
class PlatformAuditEntry
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 36)]
        private string $id,
        #[ORM\Column]
        private \DateTimeImmutable $occurredAt,
        #[ORM\Column(length: 32)]
        private string $actorType,
        #[ORM\Column(length: 36)]
        private string $actorId,
        #[ORM\Column(length: 64)]
        private string $action,
        #[ORM\Column(length: 64)]
        private string $targetType,
        #[ORM\Column(length: 36)]
        private string $targetId,
        #[ORM\Column(length: 64, nullable: true)]
        private ?string $requestId,
        #[ORM\Column(length: 64, nullable: true)]
        private ?string $peerIp,
        #[ORM\Column(type: 'text')]
        private string $metadata,
        #[ORM\Column(length: 36, nullable: true)]
        private ?string $tenantId = null,
        #[ORM\Column(length: 16, nullable: true)]
        private ?string $result = null,
        #[ORM\Column(length: 36, nullable: true)]
        private ?string $deviceId = null,
    ) {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function actorType(): string
    {
        return $this->actorType;
    }

    public function actorId(): string
    {
        return $this->actorId;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function targetType(): string
    {
        return $this->targetType;
    }

    public function targetId(): string
    {
        return $this->targetId;
    }

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    public function result(): ?string
    {
        return $this->result;
    }

    public function metadata(): string
    {
        return $this->metadata;
    }
}
