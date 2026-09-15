<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915090000 extends AbstractMigration
{
    public function getDescription(): string { return 'Keep unassigned imported order positions open until assigned or explicitly classified as notes.'; }
    public function isTransactional(): bool { return !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform; }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE production_order_import_lines ADD disposition VARCHAR(16) NOT NULL DEFAULT 'pending'");
        $this->addSql("UPDATE production_order_import_lines SET disposition = 'assigned' WHERE mapping_id IS NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The distinction between pending, assigned and note positions must be retained.');
    }
}
