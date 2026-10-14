<?php

declare(strict_types=1);

namespace App\Platform\Device\Http;

use App\Platform\Device\DeviceEnrollmentCompletion;
use App\Shared\Clock\UtcClock;
use App\Shared\Http\ApiResponse;
use App\TenantContext\Domain\TenantMismatchException;
use App\TenantContext\Domain\TenantNotFoundException;
use App\TenantContext\Domain\TenantUnavailableException;
use App\TenantContext\Infrastructure\Http\TenantHostUnknownException;
use App\TenantContext\Infrastructure\Http\TenantRequestRejectedException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class DeviceEnrollmentController
{
    public function __construct(
        private readonly \App\TenantContext\Application\TenantResolver $tenants,
        private readonly DeviceEnrollmentCompletion $completion,
        private readonly UtcClock $clock,
    ) {
    }

    #[Route('/api/v1/public/terminals/enroll', name: 'public_terminal_enroll', methods: ['POST'])]
    public function enroll(Request $request): JsonResponse
    {
        try {
            $tenant = $this->tenants->resolve($request);
        } catch (TenantNotFoundException $exception) {
            throw new TenantHostUnknownException($exception);
        } catch (TenantMismatchException $exception) {
            throw new TenantRequestRejectedException('tenant_mismatch', 'The tenant claim does not match the host.', $exception);
        } catch (TenantUnavailableException $exception) {
            throw new TenantRequestRejectedException('tenant_unavailable', 'The tenant is not available.', $exception);
        }
        $body = $this->json($request);
        $token = $body['token'] ?? null;
        $publicKey = $body['publicKey'] ?? null;
        if (!is_string($token) || !is_string($publicKey)) {
            throw new UnprocessableEntityHttpException('The request body is invalid.');
        }

        return ApiResponse::ok($this->completion->complete(
            $tenant->tenantId,
            $token,
            $publicKey,
            $body,
            $request->getClientIp(),
            $this->clock->now(),
        ), [], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Request $request): array
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
}
