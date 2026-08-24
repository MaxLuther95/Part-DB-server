<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260824100000 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Add notes to production projects and project positions.';
    }

    public function mySQLUp(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_customer_projects ADD notes LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE production_project_positions ADD notes LONGTEXT DEFAULT NULL');
    }

    public function mySQLDown(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_customer_projects DROP notes');
        $this->addSql('ALTER TABLE production_project_positions DROP notes');
    }

    public function sqLiteUp(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_customer_projects ADD notes CLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE production_project_positions ADD notes CLOB DEFAULT NULL');
    }

    public function sqLiteDown(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_customer_projects DROP COLUMN notes');
        $this->addSql('ALTER TABLE production_project_positions DROP COLUMN notes');
    }

    public function postgreSQLUp(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_customer_projects ADD notes TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE production_project_positions ADD notes TEXT DEFAULT NULL');
    }

    public function postgreSQLDown(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_customer_projects DROP COLUMN notes');
        $this->addSql('ALTER TABLE production_project_positions DROP COLUMN notes');
    }
}
