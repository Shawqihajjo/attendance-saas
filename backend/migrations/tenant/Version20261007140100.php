<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007140100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store tenant roles and attendance correction requests.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_roles (id VARCHAR(36) NOT NULL, user_id VARCHAR(36) NOT NULL, role VARCHAR(64) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_role ON user_roles (user_id, role)');
        $this->addSql('CREATE TABLE attendance_correction_requests (id VARCHAR(36) NOT NULL, employee_id VARCHAR(36) NOT NULL, requested_by_user_id VARCHAR(36) NOT NULL, reason VARCHAR(500) NOT NULL, status VARCHAR(32) NOT NULL, decided_by_user_id VARCHAR(36) DEFAULT NULL, created_at DATETIME NOT NULL, decided_at DATETIME DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_correction_employee ON attendance_correction_requests (employee_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE attendance_correction_requests');
        $this->addSql('DROP TABLE user_roles');
    }
}
