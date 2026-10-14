<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add employee number, contact details, and updated timestamp.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE employees ADD employee_number VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE employees ADD email VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE employees ADD phone VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE employees ADD updated_at DATETIME DEFAULT NULL');
        $this->addSql("UPDATE employees SET updated_at = created_at WHERE updated_at IS NULL");
        $this->addSql("UPDATE employees SET employee_number = REPLACE(id, '-', '') WHERE employee_number IS NULL");
        $this->addSql('CREATE UNIQUE INDEX uniq_employee_number ON employees (employee_number)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_employee_number');
        $this->addSql('ALTER TABLE employees DROP employee_number');
        $this->addSql('ALTER TABLE employees DROP email');
        $this->addSql('ALTER TABLE employees DROP phone');
        $this->addSql('ALTER TABLE employees DROP updated_at');
    }
}
