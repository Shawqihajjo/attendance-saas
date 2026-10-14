<?php

declare(strict_types=1);

namespace App\TenantContext\Infrastructure\Doctrine;

use App\TenantContext\Domain\TenantConnectionPurpose;
use App\TenantContext\Domain\TenantContext;
use App\TenantContext\Domain\TenantContextHolder;
use App\TenantContext\Domain\TenantDatabaseParametersProvider;
use App\TenantContext\Domain\TenantDatabaseSwitcher;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Service\ResetInterface;

final class DoctrineTenantDatabaseSwitcher implements TenantDatabaseSwitcher, ResetInterface
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly TenantContextHolder $context,
        private readonly TenantDatabaseParametersProvider $parameters,
    ) {
    }

    public function switchTo(string $tenantId): void
    {
        $this->select($tenantId, TenantConnectionPurpose::Runtime);
    }

    public function switchForMaintenance(string $tenantId): void
    {
        $this->select($tenantId, TenantConnectionPurpose::Maintenance);
    }

    public function reset(): void
    {
        $manager = $this->doctrine->getManager('tenant');
        if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
            $this->doctrine->resetManager('tenant');
            $manager = $this->doctrine->getManager('tenant');
        }
        if ($manager instanceof EntityManagerInterface && $manager->isOpen()) {
            $manager->clear();
        }
        $this->connection()->release();
        $this->context->clear();
    }

    private function select(string $tenantId, TenantConnectionPurpose $purpose): void
    {
        $profile = $this->parameters->getProfile($tenantId, $purpose);
        $manager = $this->doctrine->getManager('tenant');
        if ($manager instanceof EntityManagerInterface && $manager->isOpen()) {
            $manager->clear();
        }
        try {
            $this->connection()->selectDatabase($profile->connectionParams);
            $this->context->set(new TenantContext(
                $profile->tenantId,
                $profile->slug,
                $profile->timezone,
                $profile->defaultLocale,
                $profile->enabledLocales,
                $profile->photoRequired,
            ));
        } catch (\Throwable $exception) {
            $this->reset();
            throw $exception;
        }
    }

    private function connection(): TenantAwareConnection
    {
        $connection = $this->doctrine->getConnection('tenant');
        if (!$connection instanceof TenantAwareConnection) {
            throw new \LogicException('The tenant connection must use TenantAwareConnection.');
        }

        return $connection;
    }
}
