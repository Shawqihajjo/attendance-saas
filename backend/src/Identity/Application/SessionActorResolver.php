<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\AccessCatalog;
use App\Identity\Domain\AccountRecord;
use App\Identity\Domain\AuthenticatedActor;
use App\Identity\Domain\SessionKeys;
use App\Identity\Infrastructure\Persistence\PlatformAccountStore;
use App\Identity\Infrastructure\Persistence\TenantAccountStore;
use App\TenantContext\Application\TenantResolver;
use App\TenantContext\Domain\TenantDatabaseSwitcher;
use App\TenantContext\Domain\TenantMismatchException;
use App\TenantContext\Domain\TenantNotFoundException;
use App\TenantContext\Domain\TenantUnavailableException;
use Symfony\Component\HttpFoundation\Request;

final class SessionActorResolver
{
    public function __construct(
        private readonly TenantResolver $tenants,
        private readonly TenantDatabaseSwitcher $switcher,
        private readonly PlatformAccountStore $platformAccounts,
        private readonly TenantAccountStore $tenantAccounts,
    ) {
    }

    public function resolve(Request $request): ?AuthenticatedActor
    {
        if (!SessionKeys::hasCookie($request) && !$request->hasSession()) {
            return null;
        }
        $session = $request->getSession();
        $kind = $session->get(SessionKeys::KIND);
        $userId = $session->get(SessionKeys::USER_ID);
        $version = $this->version($session->get(SessionKeys::TOKEN_VERSION));
        if (!is_string($kind) || !is_string($userId) || $version === null) {
            return null;
        }
        if ($kind === SessionKeys::KIND_PLATFORM) {
            return $this->platformActor($request, $userId, $version);
        }
        if ($kind === SessionKeys::KIND_TENANT) {
            return $this->tenantActor($request, $userId, $version);
        }

        return null;
    }

    public function remember(Request $request, AuthenticatedActor $actor): void
    {
        $session = $request->getSession();
        $session->migrate(true);
        $session->set(SessionKeys::KIND, $actor->kind());
        $session->set(SessionKeys::USER_ID, $actor->id());
        $session->set(SessionKeys::TENANT_ID, $actor->tenantId());
        $session->set(SessionKeys::TOKEN_VERSION, $actor->tokenVersion());
    }

    public function forget(Request $request): void
    {
        if ($request->hasSession() || SessionKeys::hasCookie($request)) {
            $request->getSession()->invalidate();
        }
    }

    private function platformActor(Request $request, string $userId, int $version): ?AuthenticatedActor
    {
        if (!$this->tenants->isPlatformHost($request)) {
            $this->forget($request);

            return null;
        }
        $account = $this->platformAccounts->find($userId);
        if (!$this->usable($account, $version)) {
            $this->forget($request);

            return null;
        }

        return $this->actor($account, SessionKeys::KIND_PLATFORM, null);
    }

    private function tenantActor(Request $request, string $userId, int $version): ?AuthenticatedActor
    {
        $sessionTenant = $request->getSession()->get(SessionKeys::TENANT_ID);
        if (!is_string($sessionTenant) || $sessionTenant === '') {
            $this->forget($request);

            return null;
        }
        try {
            $resolved = $this->tenants->resolve($request);
        } catch (TenantMismatchException|TenantUnavailableException $exception) {
            $this->forget($request);
            throw $exception;
        } catch (TenantNotFoundException) {
            $this->forget($request);

            return null;
        }
        if ($resolved->tenantId !== $sessionTenant) {
            $this->forget($request);
            throw new TenantMismatchException();
        }
        try {
            $this->switcher->switchTo($resolved->tenantId);
        } catch (TenantUnavailableException $exception) {
            $this->forget($request);
            throw $exception;
        }
        $account = $this->tenantAccounts->find($userId);
        if (!$this->usable($account, $version)) {
            $this->forget($request);

            return null;
        }

        return $this->actor($account, SessionKeys::KIND_TENANT, $resolved->tenantId);
    }

    private function usable(?AccountRecord $account, int $version): bool
    {
        return $account instanceof AccountRecord && $account->isActive() && $account->tokenVersion === $version;
    }

    private function actor(AccountRecord $account, string $kind, ?string $tenantId): AuthenticatedActor
    {
        return new AuthenticatedActor(
            $account->id,
            $account->email,
            $account->displayName,
            $kind,
            $tenantId,
            $account->employeeId,
            $account->tokenVersion,
            $account->roles,
            AccessCatalog::permissionsFor($account->roles),
        );
    }

    private function version(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
