<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class JsonBody
{
    /**
     * @return array<string, mixed>
     */
    public static function read(Request $request): array
    {
        try {
            $decoded = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new UnprocessableEntityHttpException('The request body is invalid.');
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new UnprocessableEntityHttpException('The request body is invalid.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function string(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value)) {
            throw new UnprocessableEntityHttpException('The request body is invalid.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function optionalString(array $body, string $key): ?string
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return null;
        }
        if (!is_string($body[$key])) {
            throw new UnprocessableEntityHttpException('The request body is invalid.');
        }

        return $body[$key];
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function bool(array $body, string $key): bool
    {
        $value = $body[$key] ?? null;
        if (!is_bool($value)) {
            throw new UnprocessableEntityHttpException('The request body is invalid.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    public static function stringList(array $body, string $key): array
    {
        $value = $body[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new UnprocessableEntityHttpException('The request body is invalid.');
        }
        $items = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new UnprocessableEntityHttpException('The request body is invalid.');
            }
            $items[] = $item;
        }

        return $items;
    }
}
