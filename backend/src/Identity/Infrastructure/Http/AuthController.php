<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Application\SessionActorResolver;
use App\Identity\Application\SessionPresenter;
use App\Identity\Application\WebLogin;
use App\Identity\Domain\AccessCatalog;
use App\Identity\Domain\AuthenticatedActor;
use App\Identity\Domain\SessionKeys;
use App\Identity\Infrastructure\Persistence\TenantAccountStore;
use App\Identity\Infrastructure\Security\SessionExpiredException;
use App\Platform\TenantRegistry\Application\PlatformAuditRecorder;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\Shared\Clock\UtcClock;
use App\Shared\Http\ApiResponse;
use App\TenantContext\Domain\TenantMismatchException;
use App\TenantContext\Domain\TenantNotFoundException;
use App\TenantContext\Domain\TenantUnavailableException;
use App\TenantContext\Infrastructure\Http\TenantHostUnknownException;
use App\TenantContext\Infrastructure\Http\TenantRequestRejectedException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class AuthController
{
    public function __construct(
        private readonly WebLogin $login,
        private readonly SessionActorResolver $sessions,
        private readonly SessionPresenter $presenter,
        private readonly TenantAccountStore $tenantAccounts,
        private readonly PlatformAuditRecorder $platformAudit,
        private readonly TenantRegistryStore $platformStore,
        private readonly UtcClock $clock,
    ) {
    }

    #[Route('/api/v1/auth/login', name: 'auth_login', methods: ['POST'])]
    public function login(Request $request, #[MapRequestPayload] LoginCredentials $credentials): JsonResponse
    {
        try {
            $actor = $this->login->tenant($request, $credentials->email, $credentials->password);
        } catch (TenantNotFoundException $exception) {
            throw new TenantHostUnknownException($exception);
        } catch (TenantMismatchException $exception) {
            throw new TenantRequestRejectedException('tenant_mismatch', 'The request does not match the tenant for this host.', $exception);
        } catch (TenantUnavailableException $exception) {
            throw new TenantRequestRejectedException('tenant_unavailable', 'The tenant is not available.', $exception);
        }

        return ApiResponse::ok($this->presenter->present($actor));
    }

    #[Route('/api/v1/platform/login', name: 'platform_login', methods: ['POST'])]
    public function platformLogin(Request $request, #[MapRequestPayload] LoginCredentials $credentials): JsonResponse
    {
        try {
            $actor = $this->login->platform($request, $credentials->email, $credentials->password);
        } catch (TenantNotFoundException $exception) {
            throw new NotFoundHttpException('Sign-in for platform operators is available on the platform host.', $exception);
        }

        return ApiResponse::ok($this->presenter->present($actor));
    }

    #[Route('/api/v1/auth/logout', name: 'auth_logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $this->end($request);

        return ApiResponse::ok(['ended' => true]);
    }

    #[Route('/api/v1/platform/logout', name: 'platform_logout', methods: ['POST'])]
    public function platformLogout(Request $request): JsonResponse
    {
        $this->end($request);

        return ApiResponse::ok(['ended' => true]);
    }

    #[Route('/api/v1/auth/session', name: 'auth_session', methods: ['GET'])]
    public function session(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->presenter->present($this->optionalActor($request)));
    }

    #[Route('/api/v1/auth/permissions', name: 'auth_permissions', methods: ['GET'])]
    public function permissions(Request $request): JsonResponse
    {
        $actor = $this->optionalActor($request);
        if (!$actor instanceof AuthenticatedActor) {
            throw new SessionExpiredException();
        }

        return ApiResponse::ok([
            'roles' => $actor->appRoles(),
            'permissions' => $actor->permissions(),
            'catalog' => AccessCatalog::catalog(),
        ]);
    }

    private function optionalActor(Request $request): ?AuthenticatedActor
    {
        try {
            return $this->sessions->resolve($request);
        } catch (TenantMismatchException|TenantUnavailableException|TenantNotFoundException) {
            return null;
        }
    }

    private function end(Request $request): void
    {
        $actor = $this->optionalActor($request);
        if ($actor instanceof AuthenticatedActor && $actor->kind() === SessionKeys::KIND_TENANT) {
            $requestId = $request->attributes->get('request_id');
            $this->tenantAccounts->audit(
                'auth.logout',
                'tenant_user',
                $actor->id(),
                'user',
                $actor->id(),
                is_string($requestId) ? $requestId : null,
                ['email' => $actor->email()],
                $this->clock->now(),
            );
        }
        if ($actor instanceof AuthenticatedActor && $actor->kind() === SessionKeys::KIND_PLATFORM) {
            $this->platformAudit->record('auth.logout', 'platform_user', $actor->id(), $actor->id(), [
                'email' => $actor->email(),
            ]);
            $this->platformStore->flush();
        }
        $this->sessions->forget($request);
    }
}
