<?php

declare(strict_types=1);

namespace App\Identity\Domain;

final readonly class AccountRecord
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        public string $id,
        public string $email,
        public string $displayName,
        public string $status,
        public int $tokenVersion,
        public string $passwordHash,
        public array $roles,
        public ?string $employeeId,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
