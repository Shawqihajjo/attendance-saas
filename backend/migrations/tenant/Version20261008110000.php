<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index attendance reports by employee, event type, time, department, team, and manager.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_attendance_employee_type_time ON attendance_events (employee_id, event_type, occurred_at)');
        $this->addSql('CREATE INDEX idx_attendance_occurred ON attendance_events (occurred_at, employee_id)');
        $this->addSql('CREATE INDEX idx_employees_department ON employees (department_id)');
        $this->addSql('CREATE INDEX idx_employees_team ON employees (team_id)');
        $this->addSql('CREATE INDEX idx_manager_assignment_manager ON manager_assignments (manager_employee_id, employee_id)');
        $this->addSql('CREATE INDEX idx_correction_status_employee ON attendance_correction_requests (status, employee_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_correction_status_employee');
        $this->addSql('DROP INDEX idx_manager_assignment_manager');
        $this->addSql('DROP INDEX idx_employees_team');
        $this->addSql('DROP INDEX idx_employees_department');
        $this->addSql('DROP INDEX idx_attendance_occurred');
        $this->addSql('DROP INDEX idx_attendance_employee_type_time');
    }
}
