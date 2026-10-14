<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record the tenant, result, network, and device on tenant audit rows.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_log ADD COLUMN tenant_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE audit_log ADD COLUMN result VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE audit_log ADD COLUMN client_ip VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE audit_log ADD COLUMN device_id VARCHAR(36) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_log DROP COLUMN device_id');
        $this->addSql('ALTER TABLE audit_log DROP COLUMN client_ip');
        $this->addSql('ALTER TABLE audit_log DROP COLUMN result');
        $this->addSql('ALTER TABLE audit_log DROP COLUMN tenant_id');
    }
}
