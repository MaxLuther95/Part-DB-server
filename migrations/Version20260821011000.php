<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260821011000 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Remove the redundant status from project positions.';
    }

    public function mySQLUp(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_project_positions DROP status');
    }

    public function mySQLDown(Schema $schema): void
    {
        $this->addSql("ALTER TABLE production_project_positions ADD status VARCHAR(32) DEFAULT 'planned' NOT NULL");
    }

    public function sqLiteUp(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_project_positions DROP COLUMN status');
    }

    public function sqLiteDown(Schema $schema): void
    {
        $this->addSql("ALTER TABLE production_project_positions ADD COLUMN status VARCHAR(32) DEFAULT 'planned' NOT NULL");
    }

    public function postgreSQLUp(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_project_positions DROP COLUMN status');
    }

    public function postgreSQLDown(Schema $schema): void
    {
        $this->addSql("ALTER TABLE production_project_positions ADD status VARCHAR(32) DEFAULT 'planned' NOT NULL");
    }
}
