<?php

declare(strict_types=1);

namespace App\Tenant\Attendance\Infrastructure\Http;

use App\Platform\Device\DeviceCall;
use App\Platform\Device\DevicePrincipal;
use App\Shared\Clock\UtcClock;
use App\Shared\Http\ApiResponse;
use App\Tenant\Attendance\Infrastructure\TerminalAttemptService;
use App\Tenant\Attendance\TerminalRejectedException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class TerminalController
{
    public function __construct(
        private readonly Security $security,
        private readonly TerminalAttemptService $attempts,
        private readonly UtcClock $clock,
    ) {
    }

    #[Route('/api/v1/terminal/attempts', name: 'terminal_attempt_open', methods: ['POST'])]
    public function open(Request $request): JsonResponse
    {
        $device = $this->device();
        $body = $this->json($request);
        $code = $body['code'] ?? null;
        if (!is_string($code)) {
            throw new TerminalRejectedException('unknown_code', 'The attendance code was not recognized.');
        }

        return ApiResponse::ok($this->attempts->open(
            $device->deviceId(),
            $device->tenantId(),
            $code,
            $this->clock->now(),
            $this->requestId($request),
            DeviceCall::fromRequest($request),
        ));
    }

    #[Route('/api/v1/terminal/attempts/{id}/fingerprint', name: 'terminal_attempt_fingerprint', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function fingerprint(Request $request, string $id): JsonResponse
    {
        $device = $this->device();
        $body = $this->json($request);
        $vendor = $body['vendor'] ?? null;
        $sample = $body['sample'] ?? null;
        if (!is_string($vendor) || !is_string($sample) || $vendor === '' || $sample === '') {
            throw new TerminalRejectedException('fingerprint_failed', 'Fingerprint verification failed.');
        }

        return ApiResponse::ok($this->attempts->verifyFingerprint(
            $device->deviceId(),
            $device->tenantId(),
            $id,
            $vendor,
            $sample,
            $this->clock->now(),
            $this->requestId($request),
        ));
    }

    #[Route('/api/v1/terminal/attempts/{id}/photo', name: 'terminal_attempt_photo', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function photo(Request $request, string $id): JsonResponse
    {
        $device = $this->device();
        $contentType = $request->headers->get('Content-Type');

        return ApiResponse::ok($this->attempts->storePhoto(
            $device->deviceId(),
            $device->tenantId(),
            $id,
            is_string($contentType) ? strtolower(trim(explode(';', $contentType, 2)[0])) : '',
            $request->getContent(),
            $this->clock->now(),
        ));
    }

    #[Route('/api/v1/terminal/attempts/{id}/commit', name: 'terminal_attempt_commit', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function commit(Request $request, string $id): JsonResponse
    {
        $device = $this->device();
        $body = $this->json($request);
        $action = $body['action'] ?? null;
        $idempotency = $request->headers->get('Idempotency-Key');
        if (!is_string($action) || !is_string($idempotency) || trim($idempotency) === '') {
            throw new TerminalRejectedException('invalid_reference', 'The attendance action is incomplete.');
        }
        return ApiResponse::ok($this->attempts->commit(
            $device->deviceId(),
            $device->tenantId(),
            $id,
            $action,
            trim($idempotency),
            $this->clock->now(),
            $this->requestId($request),
            DeviceCall::fromRequest($request),
        ));
    }

    private function device(): DevicePrincipal
    {
        $device = $this->security->getUser();
        if (!$device instanceof DevicePrincipal) {
            throw new AccessDeniedException();
        }

        return $device;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);
        if (!is_array($decoded)) {
            throw new TerminalRejectedException('validation_failed', 'The request body is invalid.');
        }

        return $decoded;
    }

    private function requestId(Request $request): ?string
    {
        $requestId = $request->attributes->get('request_id');

        return is_string($requestId) && $requestId !== '' ? $requestId : null;
    }
}
