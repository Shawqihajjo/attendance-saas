<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store terminal metadata on the tenant device projection.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE devices ADD status VARCHAR(32) DEFAULT 'pending' NOT NULL");
        $this->addSql('ALTER TABLE devices ADD hostname VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE devices ADD os_info VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE devices ADD app_version VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE devices ADD mac_address VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE devices ADD network_name VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE devices ADD peer_ip VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE devices ADD last_heartbeat_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE devices ADD updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE devices ADD suspended_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE devices ADD revoked_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE devices DROP revoked_at');
        $this->addSql('ALTER TABLE devices DROP suspended_at');
        $this->addSql('ALTER TABLE devices DROP updated_at');
        $this->addSql('ALTER TABLE devices DROP last_heartbeat_at');
        $this->addSql('ALTER TABLE devices DROP peer_ip');
        $this->addSql('ALTER TABLE devices DROP network_name');
        $this->addSql('ALTER TABLE devices DROP mac_address');
        $this->addSql('ALTER TABLE devices DROP app_version');
        $this->addSql('ALTER TABLE devices DROP os_info');
        $this->addSql('ALTER TABLE devices DROP hostname');
        $this->addSql('ALTER TABLE devices DROP status');
    }
}
