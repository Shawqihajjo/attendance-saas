<?php

declare(strict_types=1);

namespace App\Tenant\Directory;

enum EmploymentStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public static function fromString(string $status): self
    {
        $status = strtolower(trim($status));
        $resolved = self::tryFrom($status);
        if (!$resolved instanceof self) {
            throw new \InvalidArgumentException('Employment status must be active or inactive.');
        }

        return $resolved;
    }
}
