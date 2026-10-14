<?php

declare(strict_types=1);

namespace App\Diagnostics\Infrastructure\Http;

use App\Diagnostics\ReadinessChecker;
use App\Shared\Http\ApiResponse;
use App\Shared\Time\UtcTime;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    public function __construct(
        private readonly ReadinessChecker $readiness,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/api/v1/health', name: 'health_liveness', methods: ['GET'])]
    public function liveness(): JsonResponse
    {
        return ApiResponse::ok([
            'status' => 'ok',
            'service' => 'attendance-api',
            'time' => UtcTime::toIso($this->clock->now()),
        ]);
    }

    #[Route('/api/v1/health/ready', name: 'health_readiness', methods: ['GET'])]
    public function readiness(): JsonResponse
    {
        $report = $this->readiness->report();

        return ApiResponse::ok($report->toArray(), [], $report->status === 'ok' ? 200 : 503);
    }
}
