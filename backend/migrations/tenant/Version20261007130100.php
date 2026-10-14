<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tenant-owned schema. Attendance behavior is not implemented; these tables establish ownership.
 * attendance_sessions is the attendance record. attendance_events is the append-only history.
 */
final class Version20261007130100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the tenant business schema.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE departments (id VARCHAR(36) NOT NULL, name VARCHAR(200) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE teams (id VARCHAR(36) NOT NULL, department_id VARCHAR(36) DEFAULT NULL, name VARCHAR(200) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_team_department ON teams (department_id)');
        $this->addSql('CREATE TABLE users (id VARCHAR(36) NOT NULL, email VARCHAR(180) NOT NULL, display_name VARCHAR(200) NOT NULL, status VARCHAR(32) NOT NULL, token_version INTEGER NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_tenant_user_email ON users (email)');
        $this->addSql('CREATE TABLE user_credentials (user_id VARCHAR(36) NOT NULL, password_hash VARCHAR(255) NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (user_id))');
        $this->addSql('CREATE TABLE employees (id VARCHAR(36) NOT NULL, user_id VARCHAR(36) DEFAULT NULL, department_id VARCHAR(36) DEFAULT NULL, team_id VARCHAR(36) DEFAULT NULL, display_name VARCHAR(200) NOT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_employee_user ON employees (user_id)');
        $this->addSql('CREATE TABLE employee_codes (employee_id VARCHAR(36) NOT NULL, code VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (employee_id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_employee_code ON employee_codes (code)');
        $this->addSql('CREATE TABLE manager_assignments (id VARCHAR(36) NOT NULL, manager_employee_id VARCHAR(36) NOT NULL, employee_id VARCHAR(36) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE schedules (id VARCHAR(36) NOT NULL, employee_id VARCHAR(36) NOT NULL, name VARCHAR(200) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE hr_notes (id VARCHAR(36) NOT NULL, employee_id VARCHAR(36) NOT NULL, body TEXT NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE devices (id VARCHAR(36) NOT NULL, platform_device_id VARCHAR(36) NOT NULL, display_name VARCHAR(200) NOT NULL, location VARCHAR(200) DEFAULT NULL, timezone VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_device_platform_id ON devices (platform_device_id)');
        $this->addSql('CREATE TABLE device_heartbeats (id VARCHAR(36) NOT NULL, device_id VARCHAR(36) NOT NULL, observed_at DATETIME NOT NULL, payload TEXT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE fingerprint_enrollments (id VARCHAR(36) NOT NULL, employee_id VARCHAR(36) NOT NULL, vendor VARCHAR(64) NOT NULL, template_reference VARCHAR(255) NOT NULL, enrolled_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE attendance_attempts (id VARCHAR(36) NOT NULL, employee_id VARCHAR(36) NOT NULL, device_id VARCHAR(36) NOT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE attendance_sessions (id VARCHAR(36) NOT NULL, employee_id VARCHAR(36) NOT NULL, state VARCHAR(32) NOT NULL, opened_at DATETIME NOT NULL, closed_at DATETIME DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE attendance_events (id VARCHAR(36) NOT NULL, session_id VARCHAR(36) NOT NULL, employee_id VARCHAR(36) NOT NULL, event_type VARCHAR(32) NOT NULL, occurred_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE attendance_photos (id VARCHAR(36) NOT NULL, session_id VARCHAR(36) DEFAULT NULL, employee_id VARCHAR(36) NOT NULL, storage_key VARCHAR(255) NOT NULL, captured_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE idempotency_keys (id VARCHAR(36) NOT NULL, key_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_idempotency_key ON idempotency_keys (key_hash)');
        $this->addSql('CREATE TABLE audit_log (id VARCHAR(36) NOT NULL, occurred_at DATETIME NOT NULL, actor_type VARCHAR(32) NOT NULL, actor_id VARCHAR(36) NOT NULL, action VARCHAR(64) NOT NULL, target_type VARCHAR(64) NOT NULL, target_id VARCHAR(36) NOT NULL, request_id VARCHAR(64) DEFAULT NULL, metadata TEXT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE integration_deliveries (id VARCHAR(36) NOT NULL, integration_type VARCHAR(64) NOT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        foreach ([
            'integration_deliveries',
            'audit_log',
            'idempotency_keys',
            'attendance_photos',
            'attendance_events',
            'attendance_sessions',
            'attendance_attempts',
            'fingerprint_enrollments',
            'device_heartbeats',
            'devices',
            'hr_notes',
            'schedules',
            'manager_assignments',
            'employee_codes',
            'employees',
            'user_credentials',
            'users',
            'teams',
            'departments',
        ] as $table) {
            $this->addSql('DROP TABLE '.$table);
        }
    }
}
