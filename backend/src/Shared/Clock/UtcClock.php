<?php

declare(strict_types=1);

namespace App\Shared\Clock;

final class UtcClock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
