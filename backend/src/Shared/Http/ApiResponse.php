<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

final class ApiResponse
{
    /**
     * @param array<string, mixed> $meta
     */
    public static function ok(mixed $data, array $meta = [], int $status = 200): JsonResponse
    {
        $response = new JsonResponse([
            'data' => $data,
            'meta' => $meta === [] ? new \stdClass() : $meta,
        ], $status);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
