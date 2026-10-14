<?php

declare(strict_types=1);

namespace DoctrineMigrations\Platform;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional attendance photograph retention to tenant settings.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant_settings ADD photo_retention_days INTEGER DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant_settings DROP photo_retention_days');
    }
}
