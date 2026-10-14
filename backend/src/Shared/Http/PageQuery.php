<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class PageQuery
{
    public static function limit(Request $request, int $default): int
    {
        return self::bounded($request, 'limit', $default, 1, 100);
    }

    public static function offset(Request $request): int
    {
        return self::bounded($request, 'offset', 0, 0, 100000);
    }

    private static function bounded(Request $request, string $name, int $default, int $min, int $max): int
    {
        if (!$request->query->has($name)) {
            return $default;
        }
        $value = $request->query->get($name);
        if (!is_string($value) || !preg_match('/^\d{1,6}$/', $value)) {
            throw new UnprocessableEntityHttpException('The page request is invalid.');
        }
        $number = (int) $value;
        if ($number < $min || $number > $max) {
            throw new UnprocessableEntityHttpException('The page request is invalid.');
        }

        return $number;
    }
}
