<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Infrastructure\Persistence;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tenant_database_connections')]
class TenantDatabaseConnection
{
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $droppedAt = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 36)]
        private string $tenantId,
        #[ORM\Column(length: 16)]
        private string $driver,
        #[ORM\Column(length: 253)]
        private string $host,
        #[ORM\Column]
        private int $port,
        #[ORM\Column(length: 64)]
        private string $databaseName,
        #[ORM\Column(length: 32)]
        private string $username,
        #[ORM\Column(type: 'text')]
        private string $passwordCiphertext,
        #[ORM\Column(length: 64)]
        private string $keyId,
        #[ORM\Column(length: 16)]
        private string $credentialsState = 'present',
    ) {
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function databaseName(): string
    {
        return $this->databaseName;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function passwordCiphertext(): string
    {
        return $this->passwordCiphertext;
    }

    public function keyId(): string
    {
        return $this->keyId;
    }

    public function credentialsState(): string
    {
        return $this->credentialsState;
    }

    public function markDropped(\DateTimeImmutable $now): void
    {
        $this->credentialsState = 'dropped';
        $this->passwordCiphertext = '';
        $this->droppedAt = $now;
    }
}
