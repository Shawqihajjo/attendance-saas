<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\SessionActorResolver;
use App\Identity\Domain\AuthenticatedActor;
use App\Identity\Domain\SessionKeys;
use App\Shared\Http\ProblemHttpResponse;
use App\TenantContext\Domain\TenantMismatchException;
use App\TenantContext\Domain\TenantUnavailableException;
use App\TenantContext\Infrastructure\Http\TenantRequestRejectedException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

abstract class SessionAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly SessionActorResolver $sessions,
        private readonly string $kind,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        $path = $request->getPathInfo();
        if (str_ends_with($path, '/login') || str_ends_with($path, '/logout')) {
            return false;
        }
        if (!SessionKeys::hasCookie($request)) {
            return false;
        }

        return $request->getSession()->get(SessionKeys::KIND) === $this->kind;
    }

    public function authenticate(Request $request): Passport
    {
        try {
            $actor = $this->sessions->resolve($request);
        } catch (TenantMismatchException $exception) {
            throw new TenantRequestRejectedException('tenant_mismatch', 'The request does not match the tenant for this host.', $exception);
        } catch (TenantUnavailableException $exception) {
            throw new TenantRequestRejectedException('tenant_unavailable', 'The tenant is not available.', $exception);
        }
        if (!$actor instanceof AuthenticatedActor || $actor->kind() !== $this->kind) {
            throw new CustomUserMessageAuthenticationException('Sign in is required.');
        }

        return new SelfValidatingPassport(new UserBadge($actor->getUserIdentifier(), static fn (): AuthenticatedActor => $actor));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $requestId = $request->attributes->get('request_id');

        return new ProblemHttpResponse([
            'type' => '/problems/session_expired',
            'title' => 'Authentication required',
            'status' => Response::HTTP_UNAUTHORIZED,
            'detail' => 'Sign in is required for this resource.',
            'code' => 'session_expired',
            'instance' => $request->getPathInfo(),
            'requestId' => is_string($requestId) ? $requestId : null,
        ]);
    }
}
