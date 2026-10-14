<?php

declare(strict_types=1);

namespace App\TenantContext\Domain;

final class TenantMismatchException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The tenant claim does not match the resolved tenant.');
    }
}
