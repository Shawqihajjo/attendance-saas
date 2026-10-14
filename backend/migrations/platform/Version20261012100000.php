<?php

declare(strict_types=1);

namespace DoctrineMigrations\Platform;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store platform-owned user, employee, and active-device limits for each tenant.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tenant_quotas (tenant_id VARCHAR(36) NOT NULL, max_users INTEGER DEFAULT NULL, max_employees INTEGER DEFAULT NULL, max_active_devices INTEGER DEFAULT NULL, PRIMARY KEY (tenant_id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tenant_quotas');
    }
}
