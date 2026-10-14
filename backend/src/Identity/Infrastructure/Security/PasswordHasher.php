<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\AuthenticatedActor;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

final class PasswordHasher
{
    private ?string $dummyHash = null;

    public function __construct(private readonly PasswordHasherFactoryInterface $factory)
    {
    }

    public function hash(string $password): string
    {
        return $this->factory->getPasswordHasher(AuthenticatedActor::class)->hash($password);
    }

    public function verify(string $hash, string $password): bool
    {
        return $this->factory->getPasswordHasher(AuthenticatedActor::class)->verify($hash, $password);
    }

    public function verifyOrDummy(?string $hash, string $password): bool
    {
        return $this->verify($hash ?? $this->dummyHash(), $password) && $hash !== null;
    }

    private function dummyHash(): string
    {
        return $this->dummyHash ??= $this->hash('dummy-password-not-used');
    }
}
