<?php

declare(strict_types=1);

namespace DoctrineMigrations\Platform;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add terminal lifecycle, credential references, enrollment tokens, and optional device policy.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE device_registry ADD location VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE device_registry ADD hostname VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE device_registry ADD os_info VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE device_registry ADD app_version VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE device_registry ADD mac_address VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE device_registry ADD network_name VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE device_registry ADD last_peer_ip VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE device_registry ADD last_heartbeat_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE device_registry ADD credential_ref VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE device_registry ADD certificate_ref VARCHAR(128) DEFAULT NULL');
        $this->addSql('ALTER TABLE device_registry ADD activation_required TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE device_registry ADD registered_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE device_registry ADD suspended_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE device_registry ADD revoked_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE TABLE device_keys (id VARCHAR(36) NOT NULL, device_id VARCHAR(36) NOT NULL, algorithm VARCHAR(32) NOT NULL, public_key VARCHAR(128) NOT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, retired_at DATETIME DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_device_keys_device ON device_keys (device_id, status)');
        $this->addSql('CREATE TABLE device_enrollment_tokens (id VARCHAR(36) NOT NULL, device_id VARCHAR(36) NOT NULL, tenant_id VARCHAR(36) NOT NULL, token_hash VARCHAR(64) NOT NULL, purpose VARCHAR(32) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_device_enrollment_hash ON device_enrollment_tokens (token_hash)');
        $this->addSql('CREATE TABLE tenant_device_policies (tenant_id VARCHAR(36) NOT NULL, allowed_networks LONGTEXT DEFAULT NULL, allowed_ip_ranges LONGTEXT DEFAULT NULL, allowed_app_versions LONGTEXT DEFAULT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (tenant_id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tenant_device_policies');
        $this->addSql('DROP TABLE device_enrollment_tokens');
        $this->addSql('DROP TABLE device_keys');
        $this->addSql('ALTER TABLE device_registry DROP revoked_at');
        $this->addSql('ALTER TABLE device_registry DROP suspended_at');
        $this->addSql('ALTER TABLE device_registry DROP registered_at');
        $this->addSql('ALTER TABLE device_registry DROP activation_required');
        $this->addSql('ALTER TABLE device_registry DROP certificate_ref');
        $this->addSql('ALTER TABLE device_registry DROP credential_ref');
        $this->addSql('ALTER TABLE device_registry DROP last_heartbeat_at');
        $this->addSql('ALTER TABLE device_registry DROP last_peer_ip');
        $this->addSql('ALTER TABLE device_registry DROP network_name');
        $this->addSql('ALTER TABLE device_registry DROP mac_address');
        $this->addSql('ALTER TABLE device_registry DROP app_version');
        $this->addSql('ALTER TABLE device_registry DROP os_info');
        $this->addSql('ALTER TABLE device_registry DROP hostname');
        $this->addSql('ALTER TABLE device_registry DROP location');
    }
}
