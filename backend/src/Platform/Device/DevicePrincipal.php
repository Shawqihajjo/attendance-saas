<?php

declare(strict_types=1);

namespace App\Platform\Device;

use Symfony\Component\Security\Core\User\UserInterface;

final readonly class DevicePrincipal implements UserInterface
{
    public function __construct(
        private string $deviceId,
        private string $tenantId,
    ) {
    }

    public function deviceId(): string
    {
        return $this->deviceId;
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function getRoles(): array
    {
        return ['ROLE_DEVICE'];
    }

    public function getUserIdentifier(): string
    {
        return $this->deviceId;
    }

    public function eraseCredentials(): void
    {
    }
}
