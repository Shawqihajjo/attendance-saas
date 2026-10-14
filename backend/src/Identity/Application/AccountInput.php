<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\AccessCatalog;
use App\Platform\TenantRegistry\Domain\InvalidTenantInputException;

final class AccountInput
{
    public function email(string $email): string
    {
        $email = strtolower(trim($email));
        if ($email === '' || strlen($email) > 180 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidTenantInputException('Email is invalid.');
        }

        return $email;
    }

    public function displayName(string $displayName): string
    {
        $displayName = trim($displayName);
        if ($displayName === '' || strlen($displayName) > 200 || preg_match('/[\\x00-\\x1F\\x7F]/', $displayName)) {
            throw new InvalidTenantInputException('Display name is invalid.');
        }

        return $displayName;
    }

    public function password(string $password): string
    {
        if (strlen($password) < 12 || strlen($password) > 200) {
            throw new InvalidTenantInputException('Password must be between 12 and 200 characters.');
        }

        return $password;
    }

    /**
     * @param list<string> $roles
     *
     * @return list<string>
     */
    public function tenantRoles(array $roles): array
    {
        $unique = [];
        foreach ($roles as $role) {
            if (!AccessCatalog::isTenantRole($role)) {
                throw new InvalidTenantInputException('Role is not a tenant role.');
            }
            $unique[$role] = $role;
        }
        if ($unique === []) {
            throw new InvalidTenantInputException('At least one role is required.');
        }

        return array_values($unique);
    }

    public function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 500) {
            throw new InvalidTenantInputException('Reason must be between 1 and 500 characters.');
        }

        return $reason;
    }

    public function decision(string $decision): string
    {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new InvalidTenantInputException('Decision must be approved or rejected.');
        }

        return $decision;
    }

    public function note(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > $max || preg_match('/[\\x00-\\x1F\\x7F]/', $value)) {
            throw new InvalidTenantInputException('The note must be at most '.$max.' characters.');
        }

        return $value;
    }
}
