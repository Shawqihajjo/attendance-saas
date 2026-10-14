<?php

declare(strict_types=1);

namespace App\Tests\Support;

final class QueryCounter
{
    private static int $count = 0;

    private static bool $armed = false;

    public static function arm(): void
    {
        self::$count = 0;
        self::$armed = true;
    }

    public static function read(): int
    {
        self::$armed = false;

        return self::$count;
    }

    public static function hit(): void
    {
        if (self::$armed) {
            ++self::$count;
        }
    }
}
