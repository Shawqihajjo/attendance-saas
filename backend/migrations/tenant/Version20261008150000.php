<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index attendance scale lookups for employees, devices, corrections, and audit time.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_attendance_device_time ON attendance_events (device_id, occurred_at)');
        $this->addSql('CREATE INDEX idx_adjustment_employee_type_time ON attendance_adjustments (employee_id, event_type, occurred_at)');
        $this->addSql('CREATE INDEX idx_correction_employee_day ON attendance_correction_requests (employee_id, local_date, status)');
        $this->addSql('CREATE INDEX idx_correction_created ON attendance_correction_requests (created_at)');
        $this->addSql('CREATE INDEX idx_manager_assignment_employee ON manager_assignments (employee_id, created_at)');
        $this->addSql('CREATE INDEX idx_schedule_employee_time ON schedules (employee_id, created_at)');
        $this->addSql('CREATE INDEX idx_attempt_device_status ON attendance_attempts (device_id, status)');
        $this->addSql('CREATE INDEX idx_heartbeat_device_time ON device_heartbeats (device_id, observed_at)');
        $this->addSql('CREATE INDEX idx_audit_occurred ON audit_log (occurred_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_audit_occurred');
        $this->addSql('DROP INDEX idx_heartbeat_device_time');
        $this->addSql('DROP INDEX idx_attempt_device_status');
        $this->addSql('DROP INDEX idx_schedule_employee_time');
        $this->addSql('DROP INDEX idx_manager_assignment_employee');
        $this->addSql('DROP INDEX idx_correction_created');
        $this->addSql('DROP INDEX idx_correction_employee_day');
        $this->addSql('DROP INDEX idx_adjustment_employee_type_time');
        $this->addSql('DROP INDEX idx_attendance_device_time');
    }
}
