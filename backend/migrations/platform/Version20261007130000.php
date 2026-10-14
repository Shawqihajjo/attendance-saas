<?php

declare(strict_types=1);

namespace DoctrineMigrations\Platform;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the platform tenant registry.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tenants (id VARCHAR(36) NOT NULL, slug VARCHAR(63) NOT NULL, legal_name VARCHAR(200) NOT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, provisioned_at DATETIME DEFAULT NULL, suspended_at DATETIME DEFAULT NULL, decommissioned_at DATETIME DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_tenant_slug ON tenants (slug)');
        $this->addSql('CREATE TABLE tenant_domains (id VARCHAR(36) NOT NULL, tenant_id VARCHAR(36) NOT NULL, hostname VARCHAR(253) NOT NULL, is_primary SMALLINT NOT NULL, verified_at DATETIME DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_tenant_domain_host ON tenant_domains (hostname)');
        $this->addSql('CREATE INDEX idx_tenant_domain_tenant ON tenant_domains (tenant_id)');
        $this->addSql('CREATE TABLE tenant_database_connections (tenant_id VARCHAR(36) NOT NULL, driver VARCHAR(16) NOT NULL, host VARCHAR(253) NOT NULL, port INTEGER NOT NULL, database_name VARCHAR(64) NOT NULL, username VARCHAR(32) NOT NULL, password_ciphertext TEXT NOT NULL, key_id VARCHAR(64) NOT NULL, credentials_state VARCHAR(16) NOT NULL, dropped_at DATETIME DEFAULT NULL, PRIMARY KEY (tenant_id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_tenant_database_name ON tenant_database_connections (database_name)');
        $this->addSql('CREATE TABLE tenant_branding (tenant_id VARCHAR(36) NOT NULL, display_name VARCHAR(200) NOT NULL, primary_color VARCHAR(7) NOT NULL, secondary_color VARCHAR(7) NOT NULL, accent_color VARCHAR(7) NOT NULL, surface_color VARCHAR(7) NOT NULL, text_color VARCHAR(7) NOT NULL, PRIMARY KEY (tenant_id))');
        $this->addSql('CREATE TABLE tenant_locales (id VARCHAR(36) NOT NULL, tenant_id VARCHAR(36) NOT NULL, locale VARCHAR(16) NOT NULL, is_default SMALLINT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_tenant_locale ON tenant_locales (tenant_id, locale)');
        $this->addSql('CREATE TABLE tenant_settings (tenant_id VARCHAR(36) NOT NULL, timezone VARCHAR(64) NOT NULL, photo_required SMALLINT NOT NULL, PRIMARY KEY (tenant_id))');
        $this->addSql('CREATE TABLE platform_users (id VARCHAR(36) NOT NULL, email VARCHAR(180) NOT NULL, display_name VARCHAR(200) NOT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_platform_user_email ON platform_users (email)');
        $this->addSql('CREATE TABLE platform_memberships (id VARCHAR(36) NOT NULL, user_id VARCHAR(36) NOT NULL, role VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_platform_membership ON platform_memberships (user_id, role)');
        $this->addSql('CREATE TABLE device_registry (id VARCHAR(36) NOT NULL, tenant_id VARCHAR(36) NOT NULL, display_name VARCHAR(200) NOT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_device_registry_tenant ON device_registry (tenant_id)');
        $this->addSql('CREATE TABLE platform_audit_log (id VARCHAR(36) NOT NULL, occurred_at DATETIME NOT NULL, actor_type VARCHAR(32) NOT NULL, actor_id VARCHAR(36) NOT NULL, action VARCHAR(64) NOT NULL, target_type VARCHAR(64) NOT NULL, target_id VARCHAR(36) NOT NULL, request_id VARCHAR(64) DEFAULT NULL, peer_ip VARCHAR(64) DEFAULT NULL, metadata TEXT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_platform_audit_target ON platform_audit_log (target_type, target_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE platform_audit_log');
        $this->addSql('DROP TABLE device_registry');
        $this->addSql('DROP TABLE platform_memberships');
        $this->addSql('DROP TABLE platform_users');
        $this->addSql('DROP TABLE tenant_settings');
        $this->addSql('DROP TABLE tenant_locales');
        $this->addSql('DROP TABLE tenant_branding');
        $this->addSql('DROP TABLE tenant_database_connections');
        $this->addSql('DROP TABLE tenant_domains');
        $this->addSql('DROP TABLE tenants');
    }
}
