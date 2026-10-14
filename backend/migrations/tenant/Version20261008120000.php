<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store attendance correction details and approved adjustments without rewriting attendance events.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE attendance_correction_requests ADD COLUMN local_date VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE attendance_correction_requests ADD COLUMN local_time VARCHAR(8) DEFAULT NULL');
        $this->addSql('ALTER TABLE attendance_correction_requests ADD COLUMN requested_action VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE attendance_correction_requests ADD COLUMN requested_occurred_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE attendance_correction_requests ADD COLUMN comment VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE attendance_correction_requests ADD COLUMN supporting_information VARCHAR(1000) DEFAULT NULL');
        $this->addSql('ALTER TABLE attendance_correction_requests ADD COLUMN original_events TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE attendance_correction_requests ADD COLUMN decision_comment VARCHAR(500) DEFAULT NULL');
        $this->addSql('UPDATE attendance_correction_requests SET status = \'requested\' WHERE status = \'pending\'');
        $this->addSql('CREATE TABLE attendance_adjustments (id VARCHAR(36) NOT NULL, correction_id VARCHAR(36) NOT NULL, employee_id VARCHAR(36) NOT NULL, event_type VARCHAR(32) NOT NULL, occurred_at DATETIME NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_adjustment_correction ON attendance_adjustments (correction_id)');
        $this->addSql('CREATE INDEX idx_adjustment_employee_time ON attendance_adjustments (employee_id, occurred_at)');
        $this->addSql('CREATE VIEW attendance_facts AS SELECT id, employee_id, event_type, occurred_at, device_id, \'event\' AS origin FROM attendance_events UNION ALL SELECT id, employee_id, event_type, occurred_at, NULL AS device_id, \'adjustment\' AS origin FROM attendance_adjustments');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP VIEW attendance_facts');
        $this->addSql('DROP INDEX idx_adjustment_employee_time');
        $this->addSql('DROP INDEX uniq_adjustment_correction');
        $this->addSql('DROP TABLE attendance_adjustments');
        $this->addSql('ALTER TABLE attendance_correction_requests DROP COLUMN decision_comment');
        $this->addSql('ALTER TABLE attendance_correction_requests DROP COLUMN original_events');
        $this->addSql('ALTER TABLE attendance_correction_requests DROP COLUMN supporting_information');
        $this->addSql('ALTER TABLE attendance_correction_requests DROP COLUMN comment');
        $this->addSql('ALTER TABLE attendance_correction_requests DROP COLUMN requested_occurred_at');
        $this->addSql('ALTER TABLE attendance_correction_requests DROP COLUMN requested_action');
        $this->addSql('ALTER TABLE attendance_correction_requests DROP COLUMN local_time');
        $this->addSql('ALTER TABLE attendance_correction_requests DROP COLUMN local_date');
    }
}
