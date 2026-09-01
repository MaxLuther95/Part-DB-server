<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20260826114500 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Allow multiple additive Part-DB base projects per production system template.';
    }

    public function isTransactional(): bool
    {
        return 'sqlite' !== $this->getDatabaseType();
    }

    public function mySQLUp(Schema $schema): void
    {
        $this->addSql('CREATE TABLE production_system_template_base_projects (system_template_id INT NOT NULL, project_id INT NOT NULL, INDEX IDX_3EE83F4E5A6A54A0 (system_template_id), INDEX IDX_3EE83F4E166D1F9C (project_id), PRIMARY KEY(system_template_id, project_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('INSERT INTO production_system_template_base_projects (system_template_id, project_id) SELECT id, base_project_id FROM production_system_templates WHERE base_project_id IS NOT NULL');
        $this->addSql('ALTER TABLE production_system_template_base_projects ADD CONSTRAINT FK_PROD_TEMPLATE_BASE_TEMPLATE FOREIGN KEY (system_template_id) REFERENCES production_system_templates (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE production_system_template_base_projects ADD CONSTRAINT FK_PROD_TEMPLATE_BASE_PROJECT FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE production_system_templates DROP FOREIGN KEY FK_PROD_SYSTEM_TEMPLATE_BASE');
        $this->addSql('DROP INDEX IDX_PROD_SYSTEM_TEMPLATE_BASE ON production_system_templates');
        $this->addSql('ALTER TABLE production_system_templates DROP base_project_id, DROP base_project_name, DROP base_project_reference_id');
    }

    public function sqLiteUp(Schema $schema): void
    {
        $this->addSql('PRAGMA foreign_keys = OFF');
        $this->addSql('CREATE TEMPORARY TABLE __temp__production_system_template_base_projects AS SELECT id AS system_template_id, base_project_id AS project_id FROM production_system_templates WHERE base_project_id IS NOT NULL');
        $this->addSql('CREATE TEMPORARY TABLE __temp__production_system_templates AS SELECT id, name, description, active, last_modified, datetime_added FROM production_system_templates');
        $this->addSql('DROP TABLE production_system_templates');
        $this->addSql('CREATE TABLE production_system_templates (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description CLOB NOT NULL, active BOOLEAN DEFAULT 1 NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL)');
        $this->addSql('INSERT INTO production_system_templates (id, name, description, active, last_modified, datetime_added) SELECT id, name, description, active, last_modified, datetime_added FROM __temp__production_system_templates');
        $this->addSql('DROP TABLE __temp__production_system_templates');
        $this->addSql('CREATE TABLE production_system_template_base_projects (system_template_id INTEGER NOT NULL, project_id INTEGER NOT NULL, PRIMARY KEY(system_template_id, project_id), CONSTRAINT FK_PROD_TEMPLATE_BASE_TEMPLATE FOREIGN KEY (system_template_id) REFERENCES production_system_templates (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PROD_TEMPLATE_BASE_PROJECT FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO production_system_template_base_projects (system_template_id, project_id) SELECT system_template_id, project_id FROM __temp__production_system_template_base_projects');
        $this->addSql('DROP TABLE __temp__production_system_template_base_projects');
        $this->addSql('CREATE INDEX IDX_3EE83F4E5A6A54A0 ON production_system_template_base_projects (system_template_id)');
        $this->addSql('CREATE INDEX IDX_3EE83F4E166D1F9C ON production_system_template_base_projects (project_id)');
        $this->addSql('PRAGMA foreign_keys = ON');
    }

    public function postgreSQLUp(Schema $schema): void
    {
        $this->addSql('CREATE TABLE production_system_template_base_projects (system_template_id INT NOT NULL, project_id INT NOT NULL, PRIMARY KEY(system_template_id, project_id))');
        $this->addSql('INSERT INTO production_system_template_base_projects (system_template_id, project_id) SELECT id, base_project_id FROM production_system_templates WHERE base_project_id IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_3EE83F4E5A6A54A0 ON production_system_template_base_projects (system_template_id)');
        $this->addSql('CREATE INDEX IDX_3EE83F4E166D1F9C ON production_system_template_base_projects (project_id)');
        $this->addSql('ALTER TABLE production_system_template_base_projects ADD CONSTRAINT FK_PROD_TEMPLATE_BASE_TEMPLATE FOREIGN KEY (system_template_id) REFERENCES production_system_templates (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE production_system_template_base_projects ADD CONSTRAINT FK_PROD_TEMPLATE_BASE_PROJECT FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE production_system_templates DROP CONSTRAINT FK_PROD_SYSTEM_TEMPLATE_BASE');
        $this->addSql('DROP INDEX IDX_PROD_SYSTEM_TEMPLATE_BASE');
        $this->addSql('ALTER TABLE production_system_templates DROP base_project_id, DROP base_project_name, DROP base_project_reference_id');
    }

    public function mySQLDown(Schema $schema): void { $this->abortDowngrade(); }
    public function sqLiteDown(Schema $schema): void { $this->abortDowngrade(); }
    public function postgreSQLDown(Schema $schema): void { $this->abortDowngrade(); }

    private function abortDowngrade(): never
    {
        throw new IrreversibleMigration('Multiple base projects cannot be reduced to one without losing assignments. Restore the database backup to downgrade.');
    }
}
