<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20260821012000 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Separate system templates from Part-DB build projects and allow direct nested system templates.';
    }

    public function mySQLUp(Schema $schema): void
    {
        $this->addSql('CREATE TABLE production_system_template_slot_templates (slot_id INT NOT NULL, allowed_template_id INT NOT NULL, INDEX IDX_F56E0B7659E5119C (slot_id), INDEX IDX_F56E0B76B7D948A0 (allowed_template_id), PRIMARY KEY(slot_id, allowed_template_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE production_system_template_slot_templates ADD CONSTRAINT FK_PROD_SLOT_TEMPLATE_SLOT FOREIGN KEY (slot_id) REFERENCES production_system_template_slots (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE production_system_template_slot_templates ADD CONSTRAINT FK_PROD_SLOT_TEMPLATE_ALLOWED FOREIGN KEY (allowed_template_id) REFERENCES production_system_templates (id) ON DELETE CASCADE');
        $this->addSql('INSERT INTO production_system_template_slot_templates (slot_id, allowed_template_id) SELECT sp.slot_id, st.id FROM production_system_template_slot_projects sp INNER JOIN production_system_templates st ON st.base_project_id = sp.project_id');
        $this->addSql('DELETE sp FROM production_system_template_slot_projects sp INNER JOIN production_system_templates st ON st.base_project_id = sp.project_id');

        $this->addSql('ALTER TABLE production_system_templates DROP FOREIGN KEY FK_PROD_SYSTEM_TEMPLATE_BASE');
        $this->addSql('DROP INDEX UNIQ_PROD_SYSTEM_TEMPLATE_BASE ON production_system_templates');
        $this->addSql('ALTER TABLE production_system_templates CHANGE base_project_id base_project_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_PROD_SYSTEM_TEMPLATE_BASE ON production_system_templates (base_project_id)');
        $this->addSql('ALTER TABLE production_system_templates ADD CONSTRAINT FK_PROD_SYSTEM_TEMPLATE_BASE FOREIGN KEY (base_project_id) REFERENCES projects (id)');

        $this->addSql('ALTER TABLE production_project_positions DROP FOREIGN KEY FK_FBEBCF6C34A65A53');
        $this->addSql('ALTER TABLE production_project_positions CHANGE template_project_id template_project_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE production_project_positions ADD CONSTRAINT FK_FBEBCF6C34A65A53 FOREIGN KEY (template_project_id) REFERENCES projects (id)');
        $this->addSql('UPDATE production_project_positions pp INNER JOIN production_system_template_slot_templates ast ON ast.slot_id = pp.source_slot_id INNER JOIN production_system_templates st ON st.id = ast.allowed_template_id AND st.base_project_id = pp.template_project_id SET pp.system_template_id = st.id WHERE pp.system_template_id IS NULL');
        $this->addSql('UPDATE production_project_positions SET template_project_id = NULL WHERE system_template_id IS NOT NULL');

        $this->addSql('ALTER TABLE production_build_instances DROP FOREIGN KEY FK_PRODUCTION_BUILD_TEMPLATE');
        $this->addSql('ALTER TABLE production_build_instances CHANGE template_project_id template_project_id INT DEFAULT NULL, ADD system_template_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE production_build_instances ADD CONSTRAINT FK_PRODUCTION_BUILD_TEMPLATE FOREIGN KEY (template_project_id) REFERENCES projects (id)');
        $this->addSql('ALTER TABLE production_build_instances ADD CONSTRAINT FK_PROD_BUILD_SYSTEM_TEMPLATE FOREIGN KEY (system_template_id) REFERENCES production_system_templates (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_PROD_BUILD_SYSTEM_TEMPLATE ON production_build_instances (system_template_id)');
        $this->addSql('UPDATE production_build_instances bi INNER JOIN production_project_positions pp ON pp.id = bi.project_position_id SET bi.system_template_id = pp.system_template_id, bi.template_project_id = NULL WHERE pp.system_template_id IS NOT NULL');
    }

    public function mySQLDown(Schema $schema): void
    {
        $this->abortDowngrade();
    }

    public function sqLiteUp(Schema $schema): void
    {
        $this->addSql('CREATE TABLE production_system_template_slot_templates (slot_id INTEGER NOT NULL, allowed_template_id INTEGER NOT NULL, PRIMARY KEY(slot_id, allowed_template_id), CONSTRAINT FK_PROD_SLOT_TEMPLATE_SLOT FOREIGN KEY (slot_id) REFERENCES production_system_template_slots (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PROD_SLOT_TEMPLATE_ALLOWED FOREIGN KEY (allowed_template_id) REFERENCES production_system_templates (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_F56E0B7659E5119C ON production_system_template_slot_templates (slot_id)');
        $this->addSql('CREATE INDEX IDX_F56E0B76B7D948A0 ON production_system_template_slot_templates (allowed_template_id)');
        $this->addSql('INSERT INTO production_system_template_slot_templates (slot_id, allowed_template_id) SELECT sp.slot_id, st.id FROM production_system_template_slot_projects sp INNER JOIN production_system_templates st ON st.base_project_id = sp.project_id');
        $this->addSql('DELETE FROM production_system_template_slot_projects WHERE EXISTS (SELECT 1 FROM production_system_templates st WHERE st.base_project_id = production_system_template_slot_projects.project_id)');

        $this->addSql('CREATE TEMPORARY TABLE __backup__production_system_templates AS SELECT * FROM production_system_templates');
        $this->addSql('CREATE TEMPORARY TABLE __backup__production_project_positions AS SELECT * FROM production_project_positions');
        $this->addSql('CREATE TEMPORARY TABLE __backup__production_build_instances AS SELECT * FROM production_build_instances');
        $this->addSql('UPDATE __backup__production_project_positions SET system_template_id = (SELECT ast.allowed_template_id FROM production_system_template_slot_templates ast INNER JOIN __backup__production_system_templates st ON st.id = ast.allowed_template_id WHERE ast.slot_id = __backup__production_project_positions.source_slot_id AND st.base_project_id = __backup__production_project_positions.template_project_id LIMIT 1) WHERE system_template_id IS NULL AND EXISTS (SELECT 1 FROM production_system_template_slot_templates ast INNER JOIN __backup__production_system_templates st ON st.id = ast.allowed_template_id WHERE ast.slot_id = __backup__production_project_positions.source_slot_id AND st.base_project_id = __backup__production_project_positions.template_project_id)');
        $this->addSql('DROP TABLE production_build_instances');
        $this->addSql('DROP TABLE production_project_positions');
        $this->addSql('DROP TABLE production_system_templates');

        $this->addSql('CREATE TABLE production_system_templates (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, base_project_id INTEGER DEFAULT NULL, name VARCHAR(255) NOT NULL, description CLOB NOT NULL, active BOOLEAN DEFAULT 1 NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, CONSTRAINT FK_PROD_SYSTEM_TEMPLATE_BASE FOREIGN KEY (base_project_id) REFERENCES projects (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_PROD_SYSTEM_TEMPLATE_BASE ON production_system_templates (base_project_id)');
        $this->addSql('INSERT INTO production_system_templates (id, base_project_id, name, description, active, last_modified, datetime_added) SELECT id, base_project_id, name, description, active, last_modified, datetime_added FROM __backup__production_system_templates');

        $this->addSql('CREATE TABLE production_project_positions (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, name VARCHAR(255) NOT NULL, position INTEGER DEFAULT 0 NOT NULL, quantity INTEGER DEFAULT 1 NOT NULL, customer_project_id INTEGER NOT NULL, template_project_id INTEGER DEFAULT NULL, parent_id INTEGER DEFAULT NULL, system_template_id INTEGER DEFAULT NULL, source_slot_id INTEGER DEFAULT NULL, CONSTRAINT FK_FBEBCF6CEBA41B1E FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_FBEBCF6C34A65A53 FOREIGN KEY (template_project_id) REFERENCES projects (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_FBEBCF6C727ACA70 FOREIGN KEY (parent_id) REFERENCES production_project_positions (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PROD_POSITION_SYSTEM_TEMPLATE FOREIGN KEY (system_template_id) REFERENCES production_system_templates (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PROD_POSITION_SOURCE_SLOT FOREIGN KEY (source_slot_id) REFERENCES production_system_template_slots (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_FBEBCF6CEBA41B1E ON production_project_positions (customer_project_id)');
        $this->addSql('CREATE INDEX IDX_FBEBCF6C34A65A53 ON production_project_positions (template_project_id)');
        $this->addSql('CREATE INDEX IDX_FBEBCF6C727ACA70 ON production_project_positions (parent_id)');
        $this->addSql('CREATE INDEX IDX_PROD_POSITION_SYSTEM_TEMPLATE ON production_project_positions (system_template_id)');
        $this->addSql('CREATE INDEX IDX_PROD_POSITION_SOURCE_SLOT ON production_project_positions (source_slot_id)');
        $this->addSql('INSERT INTO production_project_positions (id, last_modified, datetime_added, name, position, quantity, customer_project_id, template_project_id, parent_id, system_template_id, source_slot_id) SELECT id, last_modified, datetime_added, name, position, quantity, customer_project_id, CASE WHEN system_template_id IS NOT NULL THEN NULL ELSE template_project_id END, parent_id, system_template_id, source_slot_id FROM __backup__production_project_positions');

        $this->addSql('CREATE TABLE production_build_instances (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, template_project_id INTEGER DEFAULT NULL, system_template_id INTEGER DEFAULT NULL, customer_project_id INTEGER DEFAULT NULL, serial_number VARCHAR(128) NOT NULL, status VARCHAR(32) NOT NULL, location VARCHAR(255) DEFAULT NULL, completed_at DATETIME DEFAULT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, project_position_id INTEGER DEFAULT NULL, CONSTRAINT FK_PRODUCTION_BUILD_TEMPLATE FOREIGN KEY (template_project_id) REFERENCES projects (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PROD_BUILD_SYSTEM_TEMPLATE FOREIGN KEY (system_template_id) REFERENCES production_system_templates (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PRODUCTION_BUILD_CUSTOMER_PROJECT FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_4C67941F154BD9BD FOREIGN KEY (project_position_id) REFERENCES production_project_positions (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_4C67941F34A65A53 ON production_build_instances (template_project_id)');
        $this->addSql('CREATE INDEX IDX_PROD_BUILD_SYSTEM_TEMPLATE ON production_build_instances (system_template_id)');
        $this->addSql('CREATE INDEX IDX_4C67941FEBA41B1E ON production_build_instances (customer_project_id)');
        $this->addSql('CREATE INDEX IDX_4C67941F154BD9BD ON production_build_instances (project_position_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4C67941FD948EE2 ON production_build_instances (serial_number)');
        $this->addSql('INSERT INTO production_build_instances (id, template_project_id, system_template_id, customer_project_id, serial_number, status, location, completed_at, last_modified, datetime_added, project_position_id) SELECT bi.id, CASE WHEN pp.system_template_id IS NOT NULL THEN NULL ELSE bi.template_project_id END, pp.system_template_id, bi.customer_project_id, bi.serial_number, bi.status, bi.location, bi.completed_at, bi.last_modified, bi.datetime_added, bi.project_position_id FROM __backup__production_build_instances bi LEFT JOIN __backup__production_project_positions pp ON pp.id = bi.project_position_id');

        $this->addSql('DROP TABLE __backup__production_build_instances');
        $this->addSql('DROP TABLE __backup__production_project_positions');
        $this->addSql('DROP TABLE __backup__production_system_templates');
    }

    public function sqLiteDown(Schema $schema): void
    {
        $this->abortDowngrade();
    }

    public function postgreSQLUp(Schema $schema): void
    {
        $this->addSql('CREATE TABLE production_system_template_slot_templates (slot_id INT NOT NULL, allowed_template_id INT NOT NULL, PRIMARY KEY(slot_id, allowed_template_id))');
        $this->addSql('CREATE INDEX IDX_F56E0B7659E5119C ON production_system_template_slot_templates (slot_id)');
        $this->addSql('CREATE INDEX IDX_F56E0B76B7D948A0 ON production_system_template_slot_templates (allowed_template_id)');
        $this->addSql('ALTER TABLE production_system_template_slot_templates ADD CONSTRAINT FK_PROD_SLOT_TEMPLATE_SLOT FOREIGN KEY (slot_id) REFERENCES production_system_template_slots (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE production_system_template_slot_templates ADD CONSTRAINT FK_PROD_SLOT_TEMPLATE_ALLOWED FOREIGN KEY (allowed_template_id) REFERENCES production_system_templates (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('INSERT INTO production_system_template_slot_templates (slot_id, allowed_template_id) SELECT sp.slot_id, st.id FROM production_system_template_slot_projects sp INNER JOIN production_system_templates st ON st.base_project_id = sp.project_id');
        $this->addSql('DELETE FROM production_system_template_slot_projects sp USING production_system_templates st WHERE st.base_project_id = sp.project_id');
        $this->addSql('DROP INDEX UNIQ_PROD_SYSTEM_TEMPLATE_BASE');
        $this->addSql('CREATE INDEX IDX_PROD_SYSTEM_TEMPLATE_BASE ON production_system_templates (base_project_id)');
        $this->addSql('ALTER TABLE production_system_templates ALTER base_project_id DROP NOT NULL');
        $this->addSql('ALTER TABLE production_project_positions ALTER template_project_id DROP NOT NULL');
        $this->addSql('UPDATE production_project_positions pp SET system_template_id = ast.allowed_template_id FROM production_system_template_slot_templates ast INNER JOIN production_system_templates st ON st.id = ast.allowed_template_id WHERE ast.slot_id = pp.source_slot_id AND st.base_project_id = pp.template_project_id AND pp.system_template_id IS NULL');
        $this->addSql('UPDATE production_project_positions SET template_project_id = NULL WHERE system_template_id IS NOT NULL');
        $this->addSql('ALTER TABLE production_build_instances ALTER template_project_id DROP NOT NULL');
        $this->addSql('ALTER TABLE production_build_instances ADD system_template_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE production_build_instances ADD CONSTRAINT FK_PROD_BUILD_SYSTEM_TEMPLATE FOREIGN KEY (system_template_id) REFERENCES production_system_templates (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_PROD_BUILD_SYSTEM_TEMPLATE ON production_build_instances (system_template_id)');
        $this->addSql('UPDATE production_build_instances bi SET system_template_id = pp.system_template_id, template_project_id = NULL FROM production_project_positions pp WHERE pp.id = bi.project_position_id AND pp.system_template_id IS NOT NULL');
    }

    public function postgreSQLDown(Schema $schema): void
    {
        $this->abortDowngrade();
    }

    private function abortDowngrade(): never
    {
        throw new IrreversibleMigration('The previous model cannot represent direct or nested system templates. Restore the database backup to downgrade safely.');
    }
}
