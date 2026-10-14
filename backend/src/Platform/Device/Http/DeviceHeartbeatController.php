<?php

declare(strict_types=1);

namespace App\Platform\Device\Http;

use App\Platform\Device\DeviceHeartbeatRecorder;
use App\Platform\Device\DevicePrincipal;
use App\Platform\TenantRegistry\Infrastructure\Persistence\DeviceRegistration;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\Shared\Clock\UtcClock;
use App\Shared\Http\ApiResponse;
use App\Tenant\Attendance\TerminalRejectedException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class DeviceHeartbeatController
{
    public function __construct(
        private readonly Security $security,
        private readonly TenantRegistryStore $devices,
        private readonly DeviceHeartbeatRecorder $heartbeats,
        private readonly UtcClock $clock,
    ) {
    }

    #[Route('/api/v1/terminal/heartbeats', name: 'terminal_heartbeat', methods: ['POST'])]
    public function record(Request $request): JsonResponse
    {
        $principal = $this->security->getUser();
        if (!$principal instanceof DevicePrincipal) {
            throw new AccessDeniedException();
        }
        $device = $this->devices->findDevice($principal->deviceId());
        if (!$device instanceof DeviceRegistration || $device->tenantId() !== $principal->tenantId()) {
            throw new TerminalRejectedException('device_rejected', 'The terminal is not an enrolled device.', 401);
        }
        $body = [];
        if ($request->getContent() !== '') {
            $decoded = json_decode($request->getContent(), true);
            if (!is_array($decoded) || (array_is_list($decoded) && $decoded !== [])) {
                throw new TerminalRejectedException('validation_failed', 'The request body is invalid.');
            }
            $body = $decoded;
        }

        return ApiResponse::ok($this->heartbeats->record($device, $body, $request->getClientIp(), $this->clock->now()));
    }
}
