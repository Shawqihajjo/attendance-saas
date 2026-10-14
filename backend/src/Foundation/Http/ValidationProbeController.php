<?php

declare(strict_types=1);

namespace App\Foundation\Http;

use App\Shared\Http\ApiResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class ValidationProbeController
{
    #[Route('/api/v1/public/diagnostics/validate', name: 'public_validation_probe', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] ValidationProbe $probe): JsonResponse
    {
        return ApiResponse::ok(['name' => $probe->name]);
    }
}
