<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\AccountRecord;
use App\Identity\Infrastructure\Persistence\PlatformAccountStore;
use App\Identity\Infrastructure\Persistence\TenantAccountStore;
use App\Identity\Infrastructure\Security\PasswordHasher;
use App\Platform\TenantRegistry\Application\PlatformAuditRecorder;
use App\Platform\TenantRegistry\Domain\TenantInputValidator;
use App\Platform\TenantRegistry\Domain\TenantStatus;
use App\Platform\TenantRegistry\Infrastructure\Persistence\Tenant;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\Shared\Clock\UtcClock;
use App\TenantContext\Domain\TenantDatabaseSwitcher;
use App\TenantContext\Domain\TenantNotProvisionedException;

final class OpenAccounts
{
    public function __construct(
        private readonly AccountInput $input,
        private readonly PasswordHasher $passwords,
        private readonly PlatformAccountStore $platformAccounts,
        private readonly TenantAccountStore $tenantAccounts,
        private readonly TenantRegistryStore $store,
        private readonly TenantInputValidator $validator,
        private readonly TenantDatabaseSwitcher $switcher,
        private readonly PlatformAuditRecorder $audit,
        private readonly UtcClock $clock,
    ) {
    }

    public function platformAdmin(string $email, string $displayName, string $password): string
    {
        $email = $this->input->email($email);
        $displayName = $this->input->displayName($displayName);
        $password = $this->input->password($password);
        $id = $this->platformAccounts->createAdmin($email, $displayName, $this->passwords->hash($password), $this->clock->now());
        $this->audit->record('platform_user.created', 'platform_user', $id, 'cli', [
            'email' => $email,
            'role' => 'platform_admin',
        ], 'system');
        $this->store->flush();

        return $id;
    }

    /**
     * @param list<string> $roles
     */
    public function tenantUser(string $slug, string $email, string $displayName, string $password, array $roles): AccountRecord
    {
        $tenant = $this->store->findTenantBySlug($this->validator->slug($slug));
        if (!$tenant instanceof Tenant || $tenant->status() !== TenantStatus::Active) {
            throw new TenantNotProvisionedException($slug);
        }
        try {
            $this->switcher->switchTo($tenant->id());
            $account = $this->createInCurrentDatabase($email, $displayName, $password, $roles);
            $this->tenantAccounts->audit(
                'user.created',
                'system',
                'cli',
                'user',
                $account->id,
                null,
                ['email' => $account->email, 'roles' => implode(',', $account->roles)],
                $this->clock->now(),
            );

            return $account;
        } finally {
            $this->switcher->reset();
        }
    }

    /**
     * @param list<string> $roles
     */
    public function createInCurrentDatabase(string $email, string $displayName, string $password, array $roles): AccountRecord
    {
        return $this->tenantAccounts->create(
            $this->input->email($email),
            $this->input->displayName($displayName),
            $this->passwords->hash($this->input->password($password)),
            $this->input->tenantRoles($roles),
            $this->clock->now(),
        );
    }
}
