<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record integration deliveries, notifications, and the email outbox.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE integration_deliveries ADD COLUMN event_name VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE integration_deliveries ADD COLUMN event_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE integration_deliveries ADD COLUMN tenant_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE integration_deliveries ADD COLUMN attempt INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE integration_deliveries ADD COLUMN error_message VARCHAR(200) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_integration_delivery_event ON integration_deliveries (event_id)');
        $this->addSql('CREATE TABLE integration_notifications (id VARCHAR(36) NOT NULL, event_name VARCHAR(64) NOT NULL, subject_id VARCHAR(36) NOT NULL, message VARCHAR(500) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE integration_outbox (id VARCHAR(36) NOT NULL, kind VARCHAR(32) NOT NULL, recipient VARCHAR(180) NOT NULL, subject VARCHAR(200) NOT NULL, body VARCHAR(1000) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE integration_outbox');
        $this->addSql('DROP TABLE integration_notifications');
        $this->addSql('DROP INDEX idx_integration_delivery_event');
        $this->addSql('ALTER TABLE integration_deliveries DROP COLUMN error_message');
        $this->addSql('ALTER TABLE integration_deliveries DROP COLUMN attempt');
        $this->addSql('ALTER TABLE integration_deliveries DROP COLUMN tenant_id');
        $this->addSql('ALTER TABLE integration_deliveries DROP COLUMN event_id');
        $this->addSql('ALTER TABLE integration_deliveries DROP COLUMN event_name');
    }
}
