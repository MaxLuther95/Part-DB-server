<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260831123000 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Add the fixed commercial order unit to production system templates.';
    }

    public function mySQLUp(Schema $schema): void
    {
        $this->addSql("ALTER TABLE production_system_templates ADD order_unit VARCHAR(16) DEFAULT 'pcs.' NOT NULL");
    }

    public function sqLiteUp(Schema $schema): void
    {
        $this->addSql("ALTER TABLE production_system_templates ADD COLUMN order_unit VARCHAR(16) DEFAULT 'pcs.' NOT NULL");
    }

    public function postgreSQLUp(Schema $schema): void
    {
        $this->addSql("ALTER TABLE production_system_templates ADD order_unit VARCHAR(16) DEFAULT 'pcs.' NOT NULL");
    }

    public function mySQLDown(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_system_templates DROP COLUMN order_unit');
    }

    public function sqLiteDown(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_system_templates DROP COLUMN order_unit');
    }

    public function postgreSQLDown(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_system_templates DROP COLUMN order_unit');
    }
}
