<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PDOStatement;

final class CountingStatement extends PDOStatement
{
    protected function __construct()
    {
        QueryCounter::hit();
    }
}
