<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Infrastructure\Migrations;

use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class DoctrineMigrationRunner
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function migratePlatform(): void
    {
        $this->migrate('platform', 'DoctrineMigrations\\Platform', $this->projectDir.'/migrations/platform');
    }

    public function migrateTenant(): void
    {
        $this->migrate('tenant', 'DoctrineMigrations\\Tenant', $this->projectDir.'/migrations/tenant');
    }

    private function migrate(string $connectionName, string $namespace, string $directory): void
    {
        $configuration = new Configuration();
        $configuration->addMigrationsDirectory($namespace, $directory);
        $storage = new TableMetadataStorageConfiguration();
        $storage->setTableName('doctrine_migration_versions');
        $configuration->setMetadataStorageConfiguration($storage);
        $configuration->setAllOrNothing(false);

        $dependencyFactory = DependencyFactory::fromConnection(
            new ExistingConfiguration($configuration),
            new ExistingConnection($this->doctrine->getConnection($connectionName)),
        );
        $dependencyFactory->getMetadataStorage()->ensureInitialized();
        $pending = $dependencyFactory->getMigrationStatusCalculator()->getNewMigrations();
        if (count($pending) === 0) {
            return;
        }
        $versions = [];
        foreach ($pending->getItems() as $migration) {
            $versions[] = $migration->getVersion();
        }
        $plan = $dependencyFactory->getMigrationPlanCalculator()->getPlanForVersions($versions, Direction::UP);
        $migratorConfiguration = new MigratorConfiguration();
        $migratorConfiguration->setAllOrNothing(false);
        $dependencyFactory->getMigrator()->migrate($plan, $migratorConfiguration);
    }
}
