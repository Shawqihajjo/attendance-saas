<?php

declare(strict_types=1);

namespace DoctrineMigrations\Platform;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record the tenant, result, and device on platform audit rows.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE platform_audit_log ADD COLUMN tenant_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE platform_audit_log ADD COLUMN result VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE platform_audit_log ADD COLUMN device_id VARCHAR(36) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE platform_audit_log DROP COLUMN device_id');
        $this->addSql('ALTER TABLE platform_audit_log DROP COLUMN result');
        $this->addSql('ALTER TABLE platform_audit_log DROP COLUMN tenant_id');
    }
}
