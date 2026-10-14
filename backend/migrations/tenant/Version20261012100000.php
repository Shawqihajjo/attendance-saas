<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a row lock used while counting tenant users and employees.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE quota_guard (id INTEGER NOT NULL, PRIMARY KEY (id))');
        $this->addSql('INSERT INTO quota_guard (id) VALUES (1)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE quota_guard');
    }
}
