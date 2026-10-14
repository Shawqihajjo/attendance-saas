<?php

declare(strict_types=1);

namespace DoctrineMigrations\Platform;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the device Ed25519 public key used to authenticate a terminal.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE device_registry ADD public_key VARCHAR(128) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE device_registry DROP public_key');
    }
}
