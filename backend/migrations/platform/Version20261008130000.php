<?php

declare(strict_types=1);

namespace DoctrineMigrations\Platform;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store enabled tenant integration kinds and encrypted configuration.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tenant_integrations (id VARCHAR(36) NOT NULL, tenant_id VARCHAR(36) NOT NULL, kind VARCHAR(32) NOT NULL, event_name VARCHAR(64) NOT NULL, status VARCHAR(32) NOT NULL, config_ciphertext TEXT NOT NULL, key_id VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_tenant_integration_tenant ON tenant_integrations (tenant_id, status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_tenant_integration_tenant');
        $this->addSql('DROP TABLE tenant_integrations');
    }
}
