<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\SessionActorResolver;
use App\Identity\Domain\AuthenticatedActor;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * @implements UserProviderInterface<AuthenticatedActor>
 */
final class AuthenticatedActorProvider implements UserProviderInterface
{
    public function __construct(
        private readonly SessionActorResolver $sessions,
        private readonly RequestStack $requests,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        throw new UserNotFoundException($identifier);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof AuthenticatedActor) {
            throw new UnsupportedUserException();
        }
        $request = $this->requests->getCurrentRequest();
        if ($request === null) {
            return $user;
        }
        $actor = $this->sessions->resolve($request);
        if (!$actor instanceof AuthenticatedActor || $actor->getUserIdentifier() !== $user->getUserIdentifier() || $actor->tokenVersion() !== $user->tokenVersion()) {
            throw new UserNotFoundException($user->getUserIdentifier());
        }

        return $actor;
    }

    public function supportsClass(string $class): bool
    {
        return $class === AuthenticatedActor::class || is_a($class, AuthenticatedActor::class, true);
    }
}
