<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260821003000 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Add configurable system templates, project/part slots and project accessory requirements.';
    }

    public function mySQLUp(Schema $schema): void
    {
        $this->addSql('CREATE TABLE production_system_templates (id INT AUTO_INCREMENT NOT NULL, base_project_id INT NOT NULL, code VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT NOT NULL, active TINYINT(1) DEFAULT 1 NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, UNIQUE INDEX UNIQ_PROD_SYSTEM_TEMPLATE_CODE (code), UNIQUE INDEX UNIQ_PROD_SYSTEM_TEMPLATE_BASE (base_project_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE production_system_templates ADD CONSTRAINT FK_PROD_SYSTEM_TEMPLATE_BASE FOREIGN KEY (base_project_id) REFERENCES projects (id)');
        $this->addSql('CREATE TABLE production_system_template_slots (id INT AUTO_INCREMENT NOT NULL, system_template_id INT NOT NULL, name VARCHAR(255) NOT NULL, position INT DEFAULT 0 NOT NULL, min_quantity INT DEFAULT 0 NOT NULL, max_quantity INT DEFAULT 1 NOT NULL, serial_tracking TINYINT(1) DEFAULT 0 NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, INDEX IDX_PROD_SLOT_TEMPLATE (system_template_id), UNIQUE INDEX UNIQ_PROD_SLOT_POSITION (system_template_id, position), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE production_system_template_slots ADD CONSTRAINT FK_PROD_SLOT_TEMPLATE FOREIGN KEY (system_template_id) REFERENCES production_system_templates (id) ON DELETE CASCADE');
        $this->addSql('CREATE TABLE production_system_template_slot_projects (slot_id INT NOT NULL, project_id INT NOT NULL, INDEX IDX_PROD_SLOT_PROJECT_SLOT (slot_id), INDEX IDX_PROD_SLOT_PROJECT_PROJECT (project_id), PRIMARY KEY(slot_id, project_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE production_system_template_slot_projects ADD CONSTRAINT FK_PROD_SLOT_PROJECT_SLOT FOREIGN KEY (slot_id) REFERENCES production_system_template_slots (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE production_system_template_slot_projects ADD CONSTRAINT FK_PROD_SLOT_PROJECT_PROJECT FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE');
        $this->addSql('CREATE TABLE production_system_template_slot_parts (slot_id INT NOT NULL, part_id INT NOT NULL, INDEX IDX_PROD_SLOT_PART_SLOT (slot_id), INDEX IDX_PROD_SLOT_PART_PART (part_id), PRIMARY KEY(slot_id, part_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE production_system_template_slot_parts ADD CONSTRAINT FK_PROD_SLOT_PART_SLOT FOREIGN KEY (slot_id) REFERENCES production_system_template_slots (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE production_system_template_slot_parts ADD CONSTRAINT FK_PROD_SLOT_PART_PART FOREIGN KEY (part_id) REFERENCES parts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE production_project_positions ADD system_template_id INT DEFAULT NULL, ADD source_slot_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE production_project_positions ADD CONSTRAINT FK_PROD_POSITION_SYSTEM_TEMPLATE FOREIGN KEY (system_template_id) REFERENCES production_system_templates (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE production_project_positions ADD CONSTRAINT FK_PROD_POSITION_SOURCE_SLOT FOREIGN KEY (source_slot_id) REFERENCES production_system_template_slots (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_PROD_POSITION_SYSTEM_TEMPLATE ON production_project_positions (system_template_id)');
        $this->addSql('CREATE INDEX IDX_PROD_POSITION_SOURCE_SLOT ON production_project_positions (source_slot_id)');
        $this->addSql('CREATE TABLE production_project_accessories (id INT AUTO_INCREMENT NOT NULL, customer_project_id INT NOT NULL, project_position_id INT DEFAULT NULL, source_slot_id INT DEFAULT NULL, part_id INT NOT NULL, quantity INT DEFAULT 1 NOT NULL, serial_tracking TINYINT(1) DEFAULT 0 NOT NULL, note VARCHAR(255) DEFAULT \'\' NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, INDEX IDX_PROD_ACCESSORY_PROJECT (customer_project_id), INDEX IDX_PROD_ACCESSORY_POSITION (project_position_id), INDEX IDX_PROD_ACCESSORY_SLOT (source_slot_id), INDEX IDX_PROD_ACCESSORY_PART (part_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE production_project_accessories ADD CONSTRAINT FK_PROD_ACCESSORY_PROJECT FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE production_project_accessories ADD CONSTRAINT FK_PROD_ACCESSORY_POSITION FOREIGN KEY (project_position_id) REFERENCES production_project_positions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE production_project_accessories ADD CONSTRAINT FK_PROD_ACCESSORY_SLOT FOREIGN KEY (source_slot_id) REFERENCES production_system_template_slots (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE production_project_accessories ADD CONSTRAINT FK_PROD_ACCESSORY_PART FOREIGN KEY (part_id) REFERENCES parts (id)');
    }

    public function mySQLDown(Schema $schema): void
    {
        $this->addSql('DROP TABLE production_project_accessories');
        $this->addSql('ALTER TABLE production_project_positions DROP FOREIGN KEY FK_PROD_POSITION_SYSTEM_TEMPLATE');
        $this->addSql('ALTER TABLE production_project_positions DROP FOREIGN KEY FK_PROD_POSITION_SOURCE_SLOT');
        $this->addSql('DROP INDEX IDX_PROD_POSITION_SYSTEM_TEMPLATE ON production_project_positions');
        $this->addSql('DROP INDEX IDX_PROD_POSITION_SOURCE_SLOT ON production_project_positions');
        $this->addSql('ALTER TABLE production_project_positions DROP system_template_id, DROP source_slot_id');
        $this->addSql('DROP TABLE production_system_template_slot_parts');
        $this->addSql('DROP TABLE production_system_template_slot_projects');
        $this->addSql('DROP TABLE production_system_template_slots');
        $this->addSql('DROP TABLE production_system_templates');
    }

    public function sqLiteUp(Schema $schema): void
    {
        $this->addSql('CREATE TABLE production_system_templates (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, base_project_id INTEGER NOT NULL, code VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, description CLOB NOT NULL, active BOOLEAN DEFAULT 1 NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, CONSTRAINT FK_PROD_SYSTEM_TEMPLATE_BASE FOREIGN KEY (base_project_id) REFERENCES projects (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PROD_SYSTEM_TEMPLATE_CODE ON production_system_templates (code)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PROD_SYSTEM_TEMPLATE_BASE ON production_system_templates (base_project_id)');
        $this->addSql('CREATE TABLE production_system_template_slots (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, system_template_id INTEGER NOT NULL, name VARCHAR(255) NOT NULL, position INTEGER DEFAULT 0 NOT NULL, min_quantity INTEGER DEFAULT 0 NOT NULL, max_quantity INTEGER DEFAULT 1 NOT NULL, serial_tracking BOOLEAN DEFAULT 0 NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, CONSTRAINT FK_PROD_SLOT_TEMPLATE FOREIGN KEY (system_template_id) REFERENCES production_system_templates (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_PROD_SLOT_TEMPLATE ON production_system_template_slots (system_template_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PROD_SLOT_POSITION ON production_system_template_slots (system_template_id, position)');
        $this->addSql('CREATE TABLE production_system_template_slot_projects (slot_id INTEGER NOT NULL, project_id INTEGER NOT NULL, PRIMARY KEY(slot_id, project_id), CONSTRAINT FK_PROD_SLOT_PROJECT_SLOT FOREIGN KEY (slot_id) REFERENCES production_system_template_slots (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PROD_SLOT_PROJECT_PROJECT FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_PROD_SLOT_PROJECT_SLOT ON production_system_template_slot_projects (slot_id)');
        $this->addSql('CREATE INDEX IDX_PROD_SLOT_PROJECT_PROJECT ON production_system_template_slot_projects (project_id)');
        $this->addSql('CREATE TABLE production_system_template_slot_parts (slot_id INTEGER NOT NULL, part_id INTEGER NOT NULL, PRIMARY KEY(slot_id, part_id), CONSTRAINT FK_PROD_SLOT_PART_SLOT FOREIGN KEY (slot_id) REFERENCES production_system_template_slots (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PROD_SLOT_PART_PART FOREIGN KEY (part_id) REFERENCES parts (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_PROD_SLOT_PART_SLOT ON production_system_template_slot_parts (slot_id)');
        $this->addSql('CREATE INDEX IDX_PROD_SLOT_PART_PART ON production_system_template_slot_parts (part_id)');
        $this->addSql('ALTER TABLE production_project_positions ADD COLUMN system_template_id INTEGER DEFAULT NULL REFERENCES production_system_templates (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE production_project_positions ADD COLUMN source_slot_id INTEGER DEFAULT NULL REFERENCES production_system_template_slots (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_PROD_POSITION_SYSTEM_TEMPLATE ON production_project_positions (system_template_id)');
        $this->addSql('CREATE INDEX IDX_PROD_POSITION_SOURCE_SLOT ON production_project_positions (source_slot_id)');
        $this->addSql('CREATE TABLE production_project_accessories (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, customer_project_id INTEGER NOT NULL, project_position_id INTEGER DEFAULT NULL, source_slot_id INTEGER DEFAULT NULL, part_id INTEGER NOT NULL, quantity INTEGER DEFAULT 1 NOT NULL, serial_tracking BOOLEAN DEFAULT 0 NOT NULL, note VARCHAR(255) DEFAULT \'\' NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, CONSTRAINT FK_PROD_ACCESSORY_PROJECT FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PROD_ACCESSORY_POSITION FOREIGN KEY (project_position_id) REFERENCES production_project_positions (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PROD_ACCESSORY_SLOT FOREIGN KEY (source_slot_id) REFERENCES production_system_template_slots (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PROD_ACCESSORY_PART FOREIGN KEY (part_id) REFERENCES parts (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_PROD_ACCESSORY_PROJECT ON production_project_accessories (customer_project_id)');
        $this->addSql('CREATE INDEX IDX_PROD_ACCESSORY_POSITION ON production_project_accessories (project_position_id)');
        $this->addSql('CREATE INDEX IDX_PROD_ACCESSORY_SLOT ON production_project_accessories (source_slot_id)');
        $this->addSql('CREATE INDEX IDX_PROD_ACCESSORY_PART ON production_project_accessories (part_id)');
    }

    public function sqLiteDown(Schema $schema): void
    {
        $this->addSql('DROP TABLE production_project_accessories');
        $this->addSql('DROP INDEX IDX_PROD_POSITION_SYSTEM_TEMPLATE');
        $this->addSql('DROP INDEX IDX_PROD_POSITION_SOURCE_SLOT');
        $this->addSql('ALTER TABLE production_project_positions DROP COLUMN system_template_id');
        $this->addSql('ALTER TABLE production_project_positions DROP COLUMN source_slot_id');
        $this->addSql('DROP TABLE production_system_template_slot_parts');
        $this->addSql('DROP TABLE production_system_template_slot_projects');
        $this->addSql('DROP TABLE production_system_template_slots');
        $this->addSql('DROP TABLE production_system_templates');
    }

    public function postgreSQLUp(Schema $schema): void
    {
        $this->addSql('CREATE TABLE production_system_templates (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, base_project_id INT NOT NULL, code VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, description TEXT NOT NULL, active BOOLEAN DEFAULT TRUE NOT NULL, last_modified TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PROD_SYSTEM_TEMPLATE_CODE ON production_system_templates (code)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PROD_SYSTEM_TEMPLATE_BASE ON production_system_templates (base_project_id)');
        $this->addSql('ALTER TABLE production_system_templates ADD CONSTRAINT FK_PROD_SYSTEM_TEMPLATE_BASE FOREIGN KEY (base_project_id) REFERENCES projects (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE TABLE production_system_template_slots (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, system_template_id INT NOT NULL, name VARCHAR(255) NOT NULL, position INT DEFAULT 0 NOT NULL, min_quantity INT DEFAULT 0 NOT NULL, max_quantity INT DEFAULT 1 NOT NULL, serial_tracking BOOLEAN DEFAULT FALSE NOT NULL, last_modified TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_PROD_SLOT_TEMPLATE ON production_system_template_slots (system_template_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PROD_SLOT_POSITION ON production_system_template_slots (system_template_id, position)');
        $this->addSql('ALTER TABLE production_system_template_slots ADD CONSTRAINT FK_PROD_SLOT_TEMPLATE FOREIGN KEY (system_template_id) REFERENCES production_system_templates (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE TABLE production_system_template_slot_projects (slot_id INT NOT NULL, project_id INT NOT NULL, PRIMARY KEY(slot_id, project_id))');
        $this->addSql('CREATE INDEX IDX_PROD_SLOT_PROJECT_SLOT ON production_system_template_slot_projects (slot_id)');
        $this->addSql('CREATE INDEX IDX_PROD_SLOT_PROJECT_PROJECT ON production_system_template_slot_projects (project_id)');
        $this->addSql('ALTER TABLE production_system_template_slot_projects ADD CONSTRAINT FK_PROD_SLOT_PROJECT_SLOT FOREIGN KEY (slot_id) REFERENCES production_system_template_slots (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE production_system_template_slot_projects ADD CONSTRAINT FK_PROD_SLOT_PROJECT_PROJECT FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE TABLE production_system_template_slot_parts (slot_id INT NOT NULL, part_id INT NOT NULL, PRIMARY KEY(slot_id, part_id))');
        $this->addSql('CREATE INDEX IDX_PROD_SLOT_PART_SLOT ON production_system_template_slot_parts (slot_id)');
        $this->addSql('CREATE INDEX IDX_PROD_SLOT_PART_PART ON production_system_template_slot_parts (part_id)');
        $this->addSql('ALTER TABLE production_system_template_slot_parts ADD CONSTRAINT FK_PROD_SLOT_PART_SLOT FOREIGN KEY (slot_id) REFERENCES production_system_template_slots (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE production_system_template_slot_parts ADD CONSTRAINT FK_PROD_SLOT_PART_PART FOREIGN KEY (part_id) REFERENCES parts (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE production_project_positions ADD system_template_id INT DEFAULT NULL, ADD source_slot_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE production_project_positions ADD CONSTRAINT FK_PROD_POSITION_SYSTEM_TEMPLATE FOREIGN KEY (system_template_id) REFERENCES production_system_templates (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE production_project_positions ADD CONSTRAINT FK_PROD_POSITION_SOURCE_SLOT FOREIGN KEY (source_slot_id) REFERENCES production_system_template_slots (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_PROD_POSITION_SYSTEM_TEMPLATE ON production_project_positions (system_template_id)');
        $this->addSql('CREATE INDEX IDX_PROD_POSITION_SOURCE_SLOT ON production_project_positions (source_slot_id)');
        $this->addSql('CREATE TABLE production_project_accessories (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, customer_project_id INT NOT NULL, project_position_id INT DEFAULT NULL, source_slot_id INT DEFAULT NULL, part_id INT NOT NULL, quantity INT DEFAULT 1 NOT NULL, serial_tracking BOOLEAN DEFAULT FALSE NOT NULL, note VARCHAR(255) DEFAULT \'\' NOT NULL, last_modified TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_PROD_ACCESSORY_PROJECT ON production_project_accessories (customer_project_id)');
        $this->addSql('CREATE INDEX IDX_PROD_ACCESSORY_POSITION ON production_project_accessories (project_position_id)');
        $this->addSql('CREATE INDEX IDX_PROD_ACCESSORY_SLOT ON production_project_accessories (source_slot_id)');
        $this->addSql('CREATE INDEX IDX_PROD_ACCESSORY_PART ON production_project_accessories (part_id)');
        $this->addSql('ALTER TABLE production_project_accessories ADD CONSTRAINT FK_PROD_ACCESSORY_PROJECT FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE production_project_accessories ADD CONSTRAINT FK_PROD_ACCESSORY_POSITION FOREIGN KEY (project_position_id) REFERENCES production_project_positions (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE production_project_accessories ADD CONSTRAINT FK_PROD_ACCESSORY_SLOT FOREIGN KEY (source_slot_id) REFERENCES production_system_template_slots (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE production_project_accessories ADD CONSTRAINT FK_PROD_ACCESSORY_PART FOREIGN KEY (part_id) REFERENCES parts (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function postgreSQLDown(Schema $schema): void
    {
        $this->addSql('DROP TABLE production_project_accessories');
        $this->addSql('ALTER TABLE production_project_positions DROP CONSTRAINT FK_PROD_POSITION_SYSTEM_TEMPLATE');
        $this->addSql('ALTER TABLE production_project_positions DROP CONSTRAINT FK_PROD_POSITION_SOURCE_SLOT');
        $this->addSql('DROP INDEX IDX_PROD_POSITION_SYSTEM_TEMPLATE');
        $this->addSql('DROP INDEX IDX_PROD_POSITION_SOURCE_SLOT');
        $this->addSql('ALTER TABLE production_project_positions DROP system_template_id, DROP source_slot_id');
        $this->addSql('DROP TABLE production_system_template_slot_parts');
        $this->addSql('DROP TABLE production_system_template_slot_projects');
        $this->addSql('DROP TABLE production_system_template_slots');
        $this->addSql('DROP TABLE production_system_templates');
    }
}
