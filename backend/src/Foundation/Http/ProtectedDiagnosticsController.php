<?php

declare(strict_types=1);

namespace App\Foundation\Http;

use App\Shared\Http\ApiResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ProtectedDiagnosticsController
{
    #[Route('/api/v1/platform/diagnostics', name: 'platform_diagnostics', methods: ['GET'])]
    public function platform(): JsonResponse
    {
        return ApiResponse::ok(['area' => 'platform']);
    }

    #[Route('/api/v1/terminal/diagnostics', name: 'terminal_diagnostics', methods: ['GET'])]
    public function terminal(): JsonResponse
    {
        return ApiResponse::ok(['area' => 'terminal']);
    }

    #[Route('/api/v1/hr/diagnostics', name: 'hr_diagnostics', methods: ['GET'])]
    public function hr(): JsonResponse
    {
        return ApiResponse::ok(['area' => 'hr']);
    }
}
