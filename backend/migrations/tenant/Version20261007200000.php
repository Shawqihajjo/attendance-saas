<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bind a terminal attempt to fingerprint verification, a photograph, and the committed event.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE attendance_attempts ADD verification_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE attendance_attempts ADD photo_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE attendance_attempts ADD event_id VARCHAR(36) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE attendance_attempts DROP event_id');
        $this->addSql('ALTER TABLE attendance_attempts DROP photo_id');
        $this->addSql('ALTER TABLE attendance_attempts DROP verification_id');
    }
}
