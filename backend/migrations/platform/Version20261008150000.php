<?php

declare(strict_types=1);

namespace DoctrineMigrations\Platform;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index platform audit rows by time.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_platform_audit_occurred ON platform_audit_log (occurred_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_platform_audit_occurred');
    }
}
