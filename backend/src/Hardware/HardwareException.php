<?php

declare(strict_types=1);

namespace App\Hardware;

final class HardwareException extends \RuntimeException
{
    public function __construct(public readonly HardwareError $error)
    {
        parent::__construct($error->value);
    }
}
