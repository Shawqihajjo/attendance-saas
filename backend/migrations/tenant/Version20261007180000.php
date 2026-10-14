<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record immutable attendance events and lock one open session per employee.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE attendance_events ADD verification_method VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE attendance_events ADD device_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE attendance_events ADD photo_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE attendance_events ADD source VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE attendance_events ADD idempotency_key VARCHAR(80) DEFAULT NULL');
        $this->addSql('ALTER TABLE attendance_events ADD created_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE attendance_events ADD metadata TEXT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_attendance_event_employee ON attendance_events (employee_id, occurred_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_attendance_event_idempotency ON attendance_events (device_id, idempotency_key)');
        $this->addSql('ALTER TABLE attendance_sessions ADD open_employee_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_open_attendance_session ON attendance_sessions (open_employee_id)');
        $this->addSql('CREATE TABLE attendance_guards (employee_id VARCHAR(36) NOT NULL, PRIMARY KEY (employee_id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE attendance_guards');
        $this->addSql('DROP INDEX uniq_open_attendance_session');
        $this->addSql('ALTER TABLE attendance_sessions DROP open_employee_id');
        $this->addSql('DROP INDEX uniq_attendance_event_idempotency');
        $this->addSql('DROP INDEX idx_attendance_event_employee');
        $this->addSql('ALTER TABLE attendance_events DROP metadata');
        $this->addSql('ALTER TABLE attendance_events DROP created_at');
        $this->addSql('ALTER TABLE attendance_events DROP idempotency_key');
        $this->addSql('ALTER TABLE attendance_events DROP source');
        $this->addSql('ALTER TABLE attendance_events DROP photo_id');
        $this->addSql('ALTER TABLE attendance_events DROP device_id');
        $this->addSql('ALTER TABLE attendance_events DROP verification_method');
    }
}
