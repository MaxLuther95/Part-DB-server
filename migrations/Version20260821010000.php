<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260821010000 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Remove the redundant system template code.';
    }

    public function mySQLUp(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_system_templates DROP INDEX UNIQ_PROD_SYSTEM_TEMPLATE_CODE, DROP code');
    }

    public function mySQLDown(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_system_templates ADD code VARCHAR(64) DEFAULT NULL');
        $this->addSql("UPDATE production_system_templates SET code = CONCAT('system-', id)");
        $this->addSql('ALTER TABLE production_system_templates MODIFY code VARCHAR(64) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PROD_SYSTEM_TEMPLATE_CODE ON production_system_templates (code)');
    }

    public function sqLiteUp(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_PROD_SYSTEM_TEMPLATE_CODE');
        $this->addSql('ALTER TABLE production_system_templates DROP COLUMN code');
    }

    public function sqLiteDown(Schema $schema): void
    {
        $this->addSql("ALTER TABLE production_system_templates ADD COLUMN code VARCHAR(64) NOT NULL DEFAULT ''");
        $this->addSql("UPDATE production_system_templates SET code = 'system-' || id");
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PROD_SYSTEM_TEMPLATE_CODE ON production_system_templates (code)');
    }

    public function postgreSQLUp(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_PROD_SYSTEM_TEMPLATE_CODE');
        $this->addSql('ALTER TABLE production_system_templates DROP COLUMN code');
    }

    public function postgreSQLDown(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_system_templates ADD code VARCHAR(64) DEFAULT NULL');
        $this->addSql("UPDATE production_system_templates SET code = 'system-' || id");
        $this->addSql('ALTER TABLE production_system_templates ALTER COLUMN code SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PROD_SYSTEM_TEMPLATE_CODE ON production_system_templates (code)');
    }
}
