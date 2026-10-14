<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class AuthenticatedActor implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    public function __construct(
        private readonly string $id,
        private readonly string $email,
        private readonly string $displayName,
        private readonly string $kind,
        private readonly ?string $tenantId,
        private readonly ?string $employeeId,
        private readonly int $tokenVersion,
        private readonly array $roles,
        private readonly array $permissions,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    public function employeeId(): ?string
    {
        return $this->employeeId;
    }

    public function tokenVersion(): int
    {
        return $this->tokenVersion;
    }

    /**
     * @return list<string>
     */
    public function appRoles(): array
    {
        return $this->roles;
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return $this->permissions;
    }

    public function getUserIdentifier(): string
    {
        return $this->kind.':'.$this->id;
    }

    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];
        foreach ($this->roles as $role) {
            $roles[] = AccessCatalog::securityRole($role);
        }

        return array_values(array_unique($roles));
    }

    public function getPassword(): ?string
    {
        return null;
    }

    public function eraseCredentials(): void
    {
    }

    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self
            && $user->id === $this->id
            && $user->kind === $this->kind
            && $user->tenantId === $this->tenantId
            && $user->tokenVersion === $this->tokenVersion;
    }
}
