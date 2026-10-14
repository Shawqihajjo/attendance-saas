<?php

declare(strict_types=1);

namespace App\Platform\Device;

use App\Platform\TenantRegistry\Application\PlatformAuditRecorder;
use App\Platform\TenantRegistry\Domain\DeviceStatus;
use App\Security\AuthenticationRateLimiter;
use App\Security\TooManyAttemptsException;
use App\Platform\TenantRegistry\Infrastructure\Persistence\DeviceRegistration;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\Shared\Clock\UtcClock;
use App\Shared\Http\ProblemHttpResponse;
use App\TenantContext\Application\TenantResolver;
use App\TenantContext\Domain\TenantDatabaseSwitcher;
use App\TenantContext\Domain\TenantMismatchException;
use App\TenantContext\Domain\TenantNotFoundException;
use App\TenantContext\Domain\TenantNotProvisionedException;
use App\TenantContext\Domain\TenantUnavailableException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class DeviceSignatureAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly TenantRegistryStore $devices,
        private readonly TenantResolver $tenants,
        private readonly TenantDatabaseSwitcher $switcher,
        private readonly UtcClock $clock,
        private readonly CacheInterface $cache,
        private readonly AuthenticationRateLimiter $attempts,
        private readonly PlatformAuditRecorder $audit,
    ) {
    }

    public function supports(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/api/v1/terminal');
    }

    public function authenticate(Request $request): Passport
    {
        $this->guardDevice($request);
        $deviceId = $this->header($request, 'X-Device-Id');
        $timestamp = $this->header($request, 'X-Device-Timestamp');
        $nonce = $this->header($request, 'X-Device-Nonce');
        $signature = $this->header($request, 'X-Device-Signature');
        $device = $this->devices->findDevice($deviceId);
        $publicKey = $device instanceof DeviceRegistration ? $device->publicKey() : null;
        if (!$device instanceof DeviceRegistration || !is_string($publicKey) || $publicKey === '') {
            throw new DeviceAuthenticationException('device_rejected', 'The terminal is not an enrolled device.');
        }
        if (preg_match('/^[0-9]{1,12}$/', $timestamp) !== 1 || abs($this->clock->now()->getTimestamp() - (int) $timestamp) > 60) {
            throw new DeviceAuthenticationException('device_rejected', 'The terminal request is outside the allowed time window.');
        }
        if (preg_match('/^[A-Za-z0-9-]{8,80}$/', $nonce) !== 1) {
            throw new DeviceAuthenticationException('device_rejected', 'The terminal nonce is not usable.');
        }
        $payload = DeviceSignature::payload(
            $timestamp,
            $nonce,
            $request->getMethod(),
            $request->getPathInfo(),
            DeviceSignature::bodyHash($request->getContent()),
        );
        if (!DeviceSignature::verify($payload, $signature, $publicKey)) {
            throw new DeviceAuthenticationException('device_rejected', 'The terminal signature is not valid.');
        }
        if ($device->status() !== DeviceStatus::Active) {
            throw new DeviceAuthenticationException('device_inactive', 'The terminal is not active.', 403);
        }
        $nonceKey = 'device-nonce.'.$device->id().'.'.$nonce;
        $seen = false;
        $this->cache->get($nonceKey, function (ItemInterface $item) use (&$seen): bool {
            $item->expiresAfter(120);
            $seen = true;

            return true;
        });
        if (!$seen) {
            throw new DeviceAuthenticationException('device_rejected', 'The terminal nonce was already used.');
        }
        $this->assertHost($request, $device);
        $this->switcher->switchTo($device->tenantId());
        $principal = new DevicePrincipal($device->id(), $device->tenantId());

        return new SelfValidatingPassport(new UserBadge($device->id(), static fn (): DevicePrincipal => $principal));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $code = $exception instanceof DeviceAuthenticationException ? $exception->problemCode() : 'device_rejected';
        $status = $exception instanceof DeviceAuthenticationException ? $exception->status() : Response::HTTP_UNAUTHORIZED;
        if ($code !== 'rate_limited') {
            $this->noteDeviceFailure($request);
        }
        $this->auditFailure($request, $code);
        $requestId = $request->attributes->get('request_id');

        return new ProblemHttpResponse([
            'type' => '/problems/'.$code,
            'title' => $status === 401 ? 'Authentication required' : 'Forbidden',
            'status' => $status,
            'detail' => $exception->getMessage(),
            'code' => $code,
            'instance' => $request->getPathInfo(),
            'requestId' => is_string($requestId) ? $requestId : null,
        ]);
    }

    private function assertHost(Request $request, DeviceRegistration $device): void
    {
        try {
            $context = $this->tenants->resolve($request);
        } catch (TenantNotFoundException $exception) {
            throw new DeviceAuthenticationException('tenant_not_found', 'This host is not a tenant.', 404, $exception);
        } catch (TenantMismatchException $exception) {
            throw new DeviceAuthenticationException('tenant_mismatch', 'The terminal does not belong to this host.', 403, $exception);
        } catch (TenantUnavailableException|TenantNotProvisionedException $exception) {
            throw new DeviceAuthenticationException('tenant_unavailable', 'The tenant is not available.', 403, $exception);
        }
        if ($context->tenantId !== $device->tenantId()) {
            throw new DeviceAuthenticationException('tenant_mismatch', 'The terminal does not belong to this host.', 403);
        }
    }

    private function guardDevice(Request $request): void
    {
        try {
            $this->attempts->guard($this->deviceBucket($request), 20);
            $this->attempts->guard($this->ipBucket($request), 40);
        } catch (TooManyAttemptsException $exception) {
            throw new DeviceAuthenticationException('rate_limited', $exception->getMessage(), 429, $exception);
        }
    }

    private function noteDeviceFailure(Request $request): void
    {
        $this->attempts->hit($this->deviceBucket($request), 900);
        $this->attempts->hit($this->ipBucket($request), 900);
    }

    private function auditFailure(Request $request, string $code): void
    {
        $deviceId = $request->headers->get('X-Device-Id');
        $target = is_string($deviceId) && Uuid::isValid($deviceId) ? $deviceId : '00000000-0000-0000-0000-000000000000';
        $device = Uuid::isValid($target) && $target !== '00000000-0000-0000-0000-000000000000'
            ? $this->devices->findDevice($target)
            : null;
        $this->audit->record('device.authentication_failed', 'device', $target, 'anonymous', [
            'code' => $code,
            'tenantId' => $device instanceof DeviceRegistration ? $device->tenantId() : null,
        ], 'anonymous');
    }

    private function deviceBucket(Request $request): string
    {
        $deviceId = $request->headers->get('X-Device-Id');
        $id = is_string($deviceId) && Uuid::isValid(trim($deviceId)) ? trim($deviceId) : 'unknown';

        return 'device-auth:'.$id;
    }

    private function ipBucket(Request $request): string
    {
        return 'device-auth-ip:'.($request->getClientIp() ?? 'unknown');
    }

    private function header(Request $request, string $name): string
    {
        $value = $request->headers->get($name);
        if (!is_string($value) || trim($value) === '') {
            throw new DeviceAuthenticationException('unauthenticated', 'A terminal signature is required.');
        }

        return trim($value);
    }
}
