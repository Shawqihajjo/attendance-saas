<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\AccessCatalog;
use App\Identity\Domain\AccountRecord;
use App\Identity\Domain\AuthenticatedActor;
use App\Identity\Domain\SessionKeys;
use App\Identity\Infrastructure\Persistence\PlatformAccountStore;
use App\Identity\Infrastructure\Persistence\TenantAccountStore;
use App\Identity\Infrastructure\Security\InvalidCredentialsException;
use App\Identity\Infrastructure\Security\PasswordHasher;
use App\Platform\TenantRegistry\Application\PlatformAuditRecorder;
use App\Security\AuthenticationRateLimiter;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\Shared\Clock\UtcClock;
use App\TenantContext\Application\TenantResolver;
use App\TenantContext\Domain\TenantDatabaseSwitcher;
use App\TenantContext\Domain\TenantNotFoundException;
use Symfony\Component\HttpFoundation\Request;

final class WebLogin
{
    private const UNKNOWN_ACTOR = '00000000-0000-0000-0000-000000000000';

    public function __construct(
        private readonly TenantResolver $tenants,
        private readonly TenantDatabaseSwitcher $switcher,
        private readonly PlatformAccountStore $platformAccounts,
        private readonly TenantAccountStore $tenantAccounts,
        private readonly PasswordHasher $passwords,
        private readonly SessionActorResolver $sessions,
        private readonly PlatformAuditRecorder $platformAudit,
        private readonly TenantRegistryStore $platformStore,
        private readonly UtcClock $clock,
        private readonly AuthenticationRateLimiter $attempts,
    ) {
    }

    public function platform(Request $request, string $email, string $password): AuthenticatedActor
    {
        if (!$this->tenants->isPlatformHost($request)) {
            throw new TenantNotFoundException();
        }
        $this->guardLogin($request, $email);
        $account = $this->platformAccounts->findByEmail($email);
        $actor = $this->accept($account, $password, SessionKeys::KIND_PLATFORM, null);
        if (!$actor instanceof AuthenticatedActor) {
            $this->platformAudit->record(
                'auth.login_failed',
                'platform_user',
                $account instanceof AccountRecord ? $account->id : self::UNKNOWN_ACTOR,
                'anonymous',
                $this->failureMetadata($account, $email, $password),
                'anonymous',
            );
            $this->noteLoginFailure($request, $email);
            $this->platformStore->flush();
            throw new InvalidCredentialsException();
        }
        $this->platformAudit->record('auth.login_succeeded', 'platform_user', $actor->id(), $actor->id(), [
            'email' => $actor->email(),
        ]);
        $this->platformStore->flush();
        $this->sessions->remember($request, $actor);

        return $actor;
    }

    public function tenant(Request $request, string $email, string $password): AuthenticatedActor
    {
        $resolved = $this->tenants->resolve($request);
        $this->switcher->switchTo($resolved->tenantId);
        $this->guardLogin($request, $email);
        $account = $this->tenantAccounts->findByEmail($email);
        $actor = $this->accept($account, $password, SessionKeys::KIND_TENANT, $resolved->tenantId);
        $requestId = $request->attributes->get('request_id');
        $requestId = is_string($requestId) ? $requestId : null;
        if (!$actor instanceof AuthenticatedActor) {
            $this->tenantAccounts->audit(
                'auth.login_failed',
                'anonymous',
                'anonymous',
                'user',
                $account instanceof AccountRecord ? $account->id : self::UNKNOWN_ACTOR,
                $requestId,
                $this->failureMetadata($account, $email, $password),
                $this->clock->now(),
            );
            $this->noteLoginFailure($request, $email);
            throw new InvalidCredentialsException();
        }
        $this->tenantAccounts->audit(
            'auth.login_succeeded',
            'tenant_user',
            $actor->id(),
            'user',
            $actor->id(),
            $requestId,
            ['email' => $actor->email()],
            $this->clock->now(),
        );
        $this->sessions->remember($request, $actor);

        return $actor;
    }

    private function accept(?AccountRecord $account, string $password, string $kind, ?string $tenantId): ?AuthenticatedActor
    {
        $passwordMatches = $this->passwords->verifyOrDummy($account?->passwordHash, $password);
        if (!$passwordMatches || !$account instanceof AccountRecord || !$account->isActive()) {
            return null;
        }

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

    /**
     * @return array<string, scalar|null>
     */
    private function failureMetadata(?AccountRecord $account, string $email, string $password): array
    {
        if (!$account instanceof AccountRecord) {
            $accountState = 'unknown';
        } elseif (!$this->passwords->verify($account->passwordHash, $password)) {
            $accountState = 'rejected';
        } else {
            $accountState = 'disabled';
        }

        return [
            'email' => strtolower(trim($email)),
            'account' => $accountState,
        ];
    }

    private function guardLogin(Request $request, string $email): void
    {
        $this->attempts->guard($this->emailBucket($request, $email), 8);
        $this->attempts->guard($this->ipBucket($request), 40);
    }

    private function noteLoginFailure(Request $request, string $email): void
    {
        $this->attempts->hit($this->emailBucket($request, $email), 900);
        $this->attempts->hit($this->ipBucket($request), 900);
    }

    private function emailBucket(Request $request, string $email): string
    {
        return 'login-email:'.$request->getHost().':'.hash('sha256', strtolower(trim($email)));
    }

    private function ipBucket(Request $request): string
    {
        return 'login-ip:'.$request->getHost().':'.($request->getClientIp() ?? 'unknown');
    }
}
