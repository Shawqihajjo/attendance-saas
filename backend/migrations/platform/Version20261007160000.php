<?php

declare(strict_types=1);

namespace DoctrineMigrations\Platform;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tenant logo, favicon, and regional presentation settings.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant_branding ADD logo_url VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE tenant_branding ADD favicon_url VARCHAR(500) DEFAULT NULL');
        $this->addSql("ALTER TABLE tenant_settings ADD date_format VARCHAR(16) DEFAULT 'iso' NOT NULL");
        $this->addSql("ALTER TABLE tenant_settings ADD time_format VARCHAR(16) DEFAULT '24h' NOT NULL");
        $this->addSql('ALTER TABLE tenant_settings ADD week_starts_on SMALLINT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant_branding DROP logo_url');
        $this->addSql('ALTER TABLE tenant_branding DROP favicon_url');
        $this->addSql('ALTER TABLE tenant_settings DROP date_format');
        $this->addSql('ALTER TABLE tenant_settings DROP time_format');
        $this->addSql('ALTER TABLE tenant_settings DROP week_starts_on');
    }
}
