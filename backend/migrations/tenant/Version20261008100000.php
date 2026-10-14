<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record attendance photograph type, size, hash, storage driver, retention, and deletion.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE attendance_photos ADD content_type VARCHAR(64) DEFAULT 'image/jpeg' NOT NULL");
        $this->addSql('ALTER TABLE attendance_photos ADD byte_size INTEGER DEFAULT 0 NOT NULL');
        $this->addSql("ALTER TABLE attendance_photos ADD content_sha256 VARCHAR(64) DEFAULT '' NOT NULL");
        $this->addSql("ALTER TABLE attendance_photos ADD storage_driver VARCHAR(32) DEFAULT 'local' NOT NULL");
        $this->addSql('ALTER TABLE attendance_photos ADD retained_until DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE attendance_photos ADD deleted_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE attendance_photos DROP deleted_at');
        $this->addSql('ALTER TABLE attendance_photos DROP retained_until');
        $this->addSql('ALTER TABLE attendance_photos DROP storage_driver');
        $this->addSql('ALTER TABLE attendance_photos DROP content_sha256');
        $this->addSql('ALTER TABLE attendance_photos DROP byte_size');
        $this->addSql('ALTER TABLE attendance_photos DROP content_type');
    }
}
