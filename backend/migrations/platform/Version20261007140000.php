<?php

declare(strict_types=1);

namespace DoctrineMigrations\Platform;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store platform administrator password hashes separately from the user profile.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE platform_credentials (user_id VARCHAR(36) NOT NULL, password_hash VARCHAR(255) NOT NULL, token_version INTEGER NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (user_id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE platform_credentials');
    }
}
