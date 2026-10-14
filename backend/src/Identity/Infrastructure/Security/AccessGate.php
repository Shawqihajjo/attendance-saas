<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\AuthenticatedActor;
use App\Identity\Domain\Permission;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class AccessGate
{
    public function __construct(private readonly Security $security)
    {
    }

    public function actor(): AuthenticatedActor
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedActor) {
            throw new AccessDeniedException();
        }

        return $user;
    }

    public function granted(Permission $permission): bool
    {
        return $this->security->isGranted($permission->value);
    }

    public function require(Permission $permission): AuthenticatedActor
    {
        $actor = $this->actor();
        if (!$this->security->isGranted($permission->value)) {
            throw new AccessDeniedException();
        }

        return $actor;
    }

    public function requireEmployeeView(string $employeeId): AuthenticatedActor
    {
        $actor = $this->actor();
        if (!$this->security->isGranted('employee.view', $employeeId)) {
            throw new AccessDeniedException();
        }

        return $actor;
    }

    public function requireCorrectionDecision(string $employeeId): AuthenticatedActor
    {
        $actor = $this->actor();
        if (!$this->security->isGranted('correction.decide', $employeeId)) {
            throw new AccessDeniedException();
        }

        return $actor;
    }
}
