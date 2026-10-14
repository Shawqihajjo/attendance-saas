<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\AuthenticatedActor;
use App\Identity\Domain\Permission;
use App\Identity\Infrastructure\Persistence\TenantAccountStore;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, string|null>
 */
final class PermissionVoter extends Voter
{
    public function __construct(private readonly TenantAccountStore $accounts)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return Permission::tryFrom($attribute) !== null || $attribute === 'employee.view' || $attribute === 'correction.decide';
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof AuthenticatedActor) {
            return false;
        }
        $permission = Permission::tryFrom($attribute);
        if ($permission !== null) {
            return in_array($permission->value, $user->permissions(), true);
        }
        if (!is_string($subject) || $subject === '') {
            return false;
        }
        if ($attribute === 'employee.view') {
            return $this->canViewEmployee($user, $subject);
        }

        return $this->canDecideCorrection($user, $subject);
    }

    private function canViewEmployee(AuthenticatedActor $user, string $employeeId): bool
    {
        if ($this->allows($user, Permission::EmployeesManage)) {
            return $this->accounts->employee($employeeId) !== null;
        }
        $employee = $this->accounts->employee($employeeId);
        if ($employee === null) {
            return false;
        }
        if ($this->allows($user, Permission::ProfileViewOwn) && $employee['userId'] === $user->id()) {
            return true;
        }

        return $this->allows($user, Permission::EmployeesViewAssigned)
            && $user->employeeId() !== null
            && $this->accounts->manages($user->employeeId(), $employeeId);
    }

    private function canDecideCorrection(AuthenticatedActor $user, string $employeeId): bool
    {
        if ($this->allows($user, Permission::AttendanceCorrectionsManage)) {
            return true;
        }

        return $this->allows($user, Permission::AttendanceCorrectionDecide)
            && $user->employeeId() !== null
            && $this->accounts->manages($user->employeeId(), $employeeId);
    }

    private function allows(AuthenticatedActor $user, Permission $permission): bool
    {
        return in_array($permission->value, $user->permissions(), true);
    }
}
