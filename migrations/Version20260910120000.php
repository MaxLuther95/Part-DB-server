<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260910120000 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Add protocol calendar date and a permanent editor-name snapshot. Historical dates require an explicit data decision.';
    }

    public function isTransactional(): bool
    {
        return 'mysql' !== $this->getDatabaseType();
    }

    public function mySQLUp(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_protocol_runs ADD protocol_date DATE DEFAULT NULL, ADD last_edited_by_name VARCHAR(255) DEFAULT NULL');
        $this->snapshotEditors();
    }

    public function sqLiteUp(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_protocol_runs ADD protocol_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE production_protocol_runs ADD last_edited_by_name VARCHAR(255) DEFAULT NULL');
        $this->snapshotEditors();
    }

    public function postgreSQLUp(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_protocol_runs ADD protocol_date DATE DEFAULT NULL, ADD last_edited_by_name VARCHAR(255) DEFAULT NULL');
        $this->snapshotEditors();
    }

    private function snapshotEditors(): void
    {
        $this->addSql('UPDATE production_protocol_runs SET last_edited_by_name = (SELECT name FROM users WHERE users.id = production_protocol_runs.last_edited_by_id)');
    }

    public function mySQLDown(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Protocol dates and editor snapshots must not be discarded. Restore a verified backup instead.');
    }

    public function sqLiteDown(Schema $schema): void
    {
        $this->mySQLDown($schema);
    }

    public function postgreSQLDown(Schema $schema): void
    {
        $this->mySQLDown($schema);
    }
}
