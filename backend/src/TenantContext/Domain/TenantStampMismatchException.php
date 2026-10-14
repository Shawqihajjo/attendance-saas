<?php

declare(strict_types=1);

namespace App\TenantContext\Domain;

final class TenantStampMismatchException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The message tenant does not match the active tenant context.');
    }
}
