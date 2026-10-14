<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Infrastructure\Persistence;

use App\Platform\TenantRegistry\Domain\DeviceStatus;
use App\Platform\TenantRegistry\Domain\TenantLifecycleException;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'device_registry')]
class DeviceRegistration
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 36)]
        private string $id,
        #[ORM\Column(length: 36)]
        private string $tenantId,
        #[ORM\Column(length: 200)]
        private string $displayName,
        \DateTimeImmutable $createdAt,
    ) {
        $this->status = DeviceStatus::Pending;
        $this->createdAt = $createdAt;
        $this->updatedAt = $createdAt;
    }

    #[ORM\Column(length: 32, enumType: DeviceStatus::class)]
    private DeviceStatus $status;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $publicKey = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $hostname = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $osInfo = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $appVersion = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $macAddress = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $networkName = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $lastPeerIp = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastHeartbeatAt = null;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $credentialRef = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $certificateRef = null;

    #[ORM\Column]
    private bool $activationRequired = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $registeredAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $suspendedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function id(): string
    {
        return $this->id;
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function status(): DeviceStatus
    {
        return $this->status;
    }

    public function publicKey(): ?string
    {
        return $this->publicKey;
    }

    public function location(): ?string
    {
        return $this->location;
    }

    public function hostname(): ?string
    {
        return $this->hostname;
    }

    public function osInfo(): ?string
    {
        return $this->osInfo;
    }

    public function appVersion(): ?string
    {
        return $this->appVersion;
    }

    public function macAddress(): ?string
    {
        return $this->macAddress;
    }

    public function networkName(): ?string
    {
        return $this->networkName;
    }

    public function lastPeerIp(): ?string
    {
        return $this->lastPeerIp;
    }

    public function lastHeartbeatAt(): ?\DateTimeImmutable
    {
        return $this->lastHeartbeatAt;
    }

    public function credentialRef(): ?string
    {
        return $this->credentialRef;
    }

    public function certificateRef(): ?string
    {
        return $this->certificateRef;
    }

    public function activationRequired(): bool
    {
        return $this->activationRequired;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function registeredAt(): ?\DateTimeImmutable
    {
        return $this->registeredAt;
    }

    public function suspendedAt(): ?\DateTimeImmutable
    {
        return $this->suspendedAt;
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function describe(?string $location, ?string $certificateRef, bool $activationRequired, \DateTimeImmutable $now): void
    {
        if ($this->status === DeviceStatus::Revoked) {
            throw new TenantLifecycleException('This device cannot be changed.');
        }
        $this->location = $location;
        $this->certificateRef = $certificateRef;
        $this->activationRequired = $activationRequired;
        $this->updatedAt = $now;
    }

    public function publishKey(string $publicKey, \DateTimeImmutable $now, ?string $credentialRef = null): void
    {
        if ($this->status === DeviceStatus::Revoked) {
            throw new TenantLifecycleException('This device cannot receive a key.');
        }
        $this->publicKey = $publicKey;
        $this->credentialRef = $credentialRef;
        $this->updatedAt = $now;
    }

    public function completeRegistration(\DateTimeImmutable $now): void
    {
        if ($this->status !== DeviceStatus::Pending || $this->publicKey === null || $this->publicKey === '') {
            throw new TenantLifecycleException('This device cannot be registered.');
        }
        $this->status = DeviceStatus::Registered;
        $this->registeredAt = $now;
        $this->updatedAt = $now;
    }

    public function suspend(\DateTimeImmutable $now): void
    {
        if ($this->status === DeviceStatus::Revoked || $this->status === DeviceStatus::Suspended) {
            throw new TenantLifecycleException('This device cannot be suspended.');
        }
        $this->status = DeviceStatus::Suspended;
        $this->suspendedAt = $now;
        $this->updatedAt = $now;
    }

    public function revoke(\DateTimeImmutable $now): void
    {
        if ($this->status === DeviceStatus::Revoked) {
            throw new TenantLifecycleException('This device is already revoked.');
        }
        $this->status = DeviceStatus::Revoked;
        $this->revokedAt = $now;
        $this->updatedAt = $now;
    }

    public function noteSignals(
        ?string $hostname,
        ?string $osInfo,
        ?string $appVersion,
        ?string $macAddress,
        ?string $networkName,
        ?string $peerIp,
        \DateTimeImmutable $now,
    ): void {
        $this->hostname = $hostname ?? $this->hostname;
        $this->osInfo = $osInfo ?? $this->osInfo;
        $this->appVersion = $appVersion ?? $this->appVersion;
        $this->macAddress = $macAddress ?? $this->macAddress;
        $this->networkName = $networkName ?? $this->networkName;
        $this->lastPeerIp = $peerIp ?? $this->lastPeerIp;
        $this->updatedAt = $now;
    }

    public function recordHeartbeat(
        ?string $hostname,
        ?string $osInfo,
        ?string $appVersion,
        ?string $macAddress,
        ?string $networkName,
        ?string $peerIp,
        \DateTimeImmutable $now,
    ): void {
        $this->hostname = $hostname ?? $this->hostname;
        $this->osInfo = $osInfo ?? $this->osInfo;
        $this->appVersion = $appVersion ?? $this->appVersion;
        $this->macAddress = $macAddress ?? $this->macAddress;
        $this->networkName = $networkName ?? $this->networkName;
        $this->lastPeerIp = $peerIp ?? $this->lastPeerIp;
        $this->lastHeartbeatAt = $now;
        $this->updatedAt = $now;
    }

    public function activate(\DateTimeImmutable $now): void
    {
        if ($this->status === DeviceStatus::Active) {
            return;
        }
        if ($this->status !== DeviceStatus::Pending && $this->status !== DeviceStatus::Registered && $this->status !== DeviceStatus::Suspended) {
            throw new TenantLifecycleException('This device cannot be activated.');
        }
        $this->status = DeviceStatus::Active;
        $this->suspendedAt = null;
        $this->updatedAt = $now;
    }
}
