<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20260824110000 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Make production stock integer-only, preserve deleted reference names and enforce one device per project position.';
    }

    public function isTransactional(): bool
    {
        // SQLite must temporarily disable foreign keys while recreating tables.
        return 'sqlite' !== $this->getDatabaseType();
    }

    public function mySQLUp(Schema $schema): void
    {
        $this->guardExistingData('CAST(quantity AS SIGNED)');

        $this->addSql('ALTER TABLE production_system_templates ADD base_project_name VARCHAR(255) DEFAULT NULL, ADD base_project_reference_id INT DEFAULT NULL');
        $this->addSql('UPDATE production_system_templates st LEFT JOIN projects p ON p.id = st.base_project_id SET st.base_project_name = p.name, st.base_project_reference_id = st.base_project_id');
        $this->addSql('ALTER TABLE production_system_templates DROP FOREIGN KEY FK_PROD_SYSTEM_TEMPLATE_BASE');
        $this->addSql('ALTER TABLE production_system_templates ADD CONSTRAINT FK_PROD_SYSTEM_TEMPLATE_BASE FOREIGN KEY (base_project_id) REFERENCES projects (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE production_project_positions ADD content_name VARCHAR(255) DEFAULT NULL, ADD content_reference_type VARCHAR(32) DEFAULT NULL, ADD content_reference_id INT DEFAULT NULL');
        $this->addSql("UPDATE production_project_positions pp LEFT JOIN production_system_templates st ON st.id = pp.system_template_id LEFT JOIN projects p ON p.id = pp.template_project_id SET pp.content_name = COALESCE(st.name, p.name, pp.name), pp.content_reference_type = CASE WHEN pp.system_template_id IS NOT NULL THEN 'system_template' WHEN pp.template_project_id IS NOT NULL THEN 'project' ELSE NULL END, pp.content_reference_id = COALESCE(pp.system_template_id, pp.template_project_id)");
        $this->addSql('ALTER TABLE production_project_positions DROP FOREIGN KEY FK_FBEBCF6C34A65A53');
        $this->addSql('ALTER TABLE production_project_positions ADD CONSTRAINT FK_FBEBCF6C34A65A53 FOREIGN KEY (template_project_id) REFERENCES projects (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE production_build_instances ADD content_name VARCHAR(255) DEFAULT NULL, ADD content_reference_type VARCHAR(32) DEFAULT NULL, ADD content_reference_id INT DEFAULT NULL');
        $this->addSql("UPDATE production_build_instances bi LEFT JOIN production_system_templates st ON st.id = bi.system_template_id LEFT JOIN projects p ON p.id = bi.template_project_id SET bi.content_name = COALESCE(st.name, p.name, bi.serial_number), bi.content_reference_type = CASE WHEN bi.system_template_id IS NOT NULL THEN 'system_template' WHEN bi.template_project_id IS NOT NULL THEN 'project' ELSE NULL END, bi.content_reference_id = COALESCE(bi.system_template_id, bi.template_project_id)");
        $this->addSql('ALTER TABLE production_build_instances DROP FOREIGN KEY FK_PRODUCTION_BUILD_TEMPLATE');
        $this->addSql('ALTER TABLE production_build_instances ADD CONSTRAINT FK_PRODUCTION_BUILD_TEMPLATE FOREIGN KEY (template_project_id) REFERENCES projects (id) ON DELETE SET NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PROD_BUILD_POSITION ON production_build_instances (project_position_id)');
        $this->addSql('DROP INDEX IDX_4C67941F154BD9BD ON production_build_instances');

        $this->addSql('ALTER TABLE production_project_accessories ADD part_name VARCHAR(255) DEFAULT NULL, ADD part_reference_id INT DEFAULT NULL');
        $this->addSql('UPDATE production_project_accessories pa INNER JOIN parts p ON p.id = pa.part_id SET pa.part_name = p.name, pa.part_reference_id = pa.part_id');
        $this->addSql('ALTER TABLE production_project_accessories DROP FOREIGN KEY FK_PROD_ACCESSORY_PART');
        $this->addSql('ALTER TABLE production_project_accessories MODIFY part_id INT DEFAULT NULL, MODIFY part_name VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE production_project_accessories ADD CONSTRAINT FK_PROD_ACCESSORY_PART FOREIGN KEY (part_id) REFERENCES parts (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE production_project_material_allocations ADD part_name VARCHAR(255) DEFAULT NULL, ADD part_reference_id INT DEFAULT NULL');
        $this->addSql('UPDATE production_project_material_allocations ma INNER JOIN parts p ON p.id = ma.part_id SET ma.part_name = p.name, ma.part_reference_id = ma.part_id');
        $this->addSql('ALTER TABLE production_project_material_allocations DROP FOREIGN KEY FK_PROD_MATERIAL_PART');
        $this->addSql('ALTER TABLE production_project_material_allocations MODIFY part_id INT DEFAULT NULL, MODIFY quantity INT NOT NULL, MODIFY part_name VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE production_project_material_allocations ADD CONSTRAINT FK_PROD_MATERIAL_PART FOREIGN KEY (part_id) REFERENCES parts (id) ON DELETE SET NULL');
    }

    public function mySQLDown(Schema $schema): void
    {
        $this->abortDowngrade();
    }

    public function sqLiteUp(Schema $schema): void
    {
        $this->guardExistingData('CAST(quantity AS INTEGER)');
        $this->addSql('PRAGMA foreign_keys = OFF');

        $this->addSql('CREATE TEMPORARY TABLE __temp__production_system_templates AS SELECT * FROM production_system_templates');
        $this->addSql('DROP TABLE production_system_templates');
        $this->addSql('CREATE TABLE production_system_templates (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, base_project_id INTEGER DEFAULT NULL, name VARCHAR(255) NOT NULL, description CLOB NOT NULL, active BOOLEAN DEFAULT 1 NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, base_project_name VARCHAR(255) DEFAULT NULL, base_project_reference_id INTEGER DEFAULT NULL, CONSTRAINT FK_PROD_SYSTEM_TEMPLATE_BASE FOREIGN KEY (base_project_id) REFERENCES projects (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO production_system_templates (id, base_project_id, name, description, active, last_modified, datetime_added, base_project_name, base_project_reference_id) SELECT id, base_project_id, name, description, active, last_modified, datetime_added, (SELECT p.name FROM projects p WHERE p.id = __temp__production_system_templates.base_project_id), base_project_id FROM __temp__production_system_templates');
        $this->addSql('DROP TABLE __temp__production_system_templates');
        $this->addSql('CREATE INDEX IDX_PROD_SYSTEM_TEMPLATE_BASE ON production_system_templates (base_project_id)');

        $this->addSql('CREATE TEMPORARY TABLE __temp__production_project_accessories AS SELECT * FROM production_project_accessories');
        $this->addSql('DROP TABLE production_project_accessories');
        $this->addSql('CREATE TABLE production_project_accessories (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, customer_project_id INTEGER NOT NULL, project_position_id INTEGER DEFAULT NULL, source_slot_id INTEGER DEFAULT NULL, part_id INTEGER DEFAULT NULL, quantity INTEGER DEFAULT 1 NOT NULL, serial_tracking BOOLEAN DEFAULT 0 NOT NULL, note VARCHAR(255) DEFAULT \'\' NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, part_name VARCHAR(255) NOT NULL, part_reference_id INTEGER DEFAULT NULL, CONSTRAINT FK_PROD_ACCESSORY_PROJECT FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PROD_ACCESSORY_POSITION FOREIGN KEY (project_position_id) REFERENCES production_project_positions (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PROD_ACCESSORY_SLOT FOREIGN KEY (source_slot_id) REFERENCES production_system_template_slots (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PROD_ACCESSORY_PART FOREIGN KEY (part_id) REFERENCES parts (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO production_project_accessories (id, customer_project_id, project_position_id, source_slot_id, part_id, quantity, serial_tracking, note, last_modified, datetime_added, part_name, part_reference_id) SELECT id, customer_project_id, project_position_id, source_slot_id, part_id, quantity, serial_tracking, note, last_modified, datetime_added, (SELECT p.name FROM parts p WHERE p.id = __temp__production_project_accessories.part_id), part_id FROM __temp__production_project_accessories');
        $this->addSql('DROP TABLE __temp__production_project_accessories');
        $this->addSql('CREATE INDEX IDX_PROD_ACCESSORY_PROJECT ON production_project_accessories (customer_project_id)');
        $this->addSql('CREATE INDEX IDX_PROD_ACCESSORY_POSITION ON production_project_accessories (project_position_id)');
        $this->addSql('CREATE INDEX IDX_PROD_ACCESSORY_SLOT ON production_project_accessories (source_slot_id)');
        $this->addSql('CREATE INDEX IDX_PROD_ACCESSORY_PART ON production_project_accessories (part_id)');

        $this->addSql('CREATE TEMPORARY TABLE __temp__production_project_material_allocations AS SELECT * FROM production_project_material_allocations');
        $this->addSql('DROP TABLE production_project_material_allocations');
        $this->addSql('CREATE TABLE production_project_material_allocations (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, customer_project_id INTEGER NOT NULL, part_id INTEGER DEFAULT NULL, source_part_lot_id INTEGER DEFAULT NULL, allocated_by_id INTEGER DEFAULT NULL, quantity INTEGER NOT NULL, serial_number VARCHAR(128) DEFAULT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, part_name VARCHAR(255) NOT NULL, part_reference_id INTEGER DEFAULT NULL, CONSTRAINT FK_PROD_MATERIAL_PROJECT FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PROD_MATERIAL_PART FOREIGN KEY (part_id) REFERENCES parts (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PROD_MATERIAL_LOT FOREIGN KEY (source_part_lot_id) REFERENCES part_lots (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PROD_MATERIAL_USER FOREIGN KEY (allocated_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO production_project_material_allocations (id, customer_project_id, part_id, source_part_lot_id, allocated_by_id, quantity, serial_number, last_modified, datetime_added, part_name, part_reference_id) SELECT id, customer_project_id, part_id, source_part_lot_id, allocated_by_id, CAST(quantity AS INTEGER), serial_number, last_modified, datetime_added, (SELECT p.name FROM parts p WHERE p.id = __temp__production_project_material_allocations.part_id), part_id FROM __temp__production_project_material_allocations');
        $this->addSql('DROP TABLE __temp__production_project_material_allocations');
        $this->addSql('CREATE INDEX IDX_PROD_MATERIAL_PROJECT ON production_project_material_allocations (customer_project_id)');
        $this->addSql('CREATE INDEX IDX_PROD_MATERIAL_PART ON production_project_material_allocations (part_id)');
        $this->addSql('CREATE INDEX IDX_PROD_MATERIAL_LOT ON production_project_material_allocations (source_part_lot_id)');
        $this->addSql('CREATE INDEX IDX_PROD_MATERIAL_USER ON production_project_material_allocations (allocated_by_id)');

        $this->addSql('CREATE TEMPORARY TABLE __temp__production_project_positions AS SELECT * FROM production_project_positions');
        $this->addSql('DROP TABLE production_project_positions');
        $this->addSql('CREATE TABLE production_project_positions (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, name VARCHAR(255) NOT NULL, position INTEGER DEFAULT 0 NOT NULL, quantity INTEGER DEFAULT 1 NOT NULL, customer_project_id INTEGER NOT NULL, template_project_id INTEGER DEFAULT NULL, parent_id INTEGER DEFAULT NULL, system_template_id INTEGER DEFAULT NULL, source_slot_id INTEGER DEFAULT NULL, notes CLOB DEFAULT NULL, content_name VARCHAR(255) DEFAULT NULL, content_reference_type VARCHAR(32) DEFAULT NULL, content_reference_id INTEGER DEFAULT NULL, CONSTRAINT FK_FBEBCF6CEBA41B1E FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_FBEBCF6C34A65A53 FOREIGN KEY (template_project_id) REFERENCES projects (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_FBEBCF6C727ACA70 FOREIGN KEY (parent_id) REFERENCES production_project_positions (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PROD_POSITION_SYSTEM_TEMPLATE FOREIGN KEY (system_template_id) REFERENCES production_system_templates (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PROD_POSITION_SOURCE_SLOT FOREIGN KEY (source_slot_id) REFERENCES production_system_template_slots (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql("INSERT INTO production_project_positions (id, last_modified, datetime_added, name, position, quantity, customer_project_id, template_project_id, parent_id, system_template_id, source_slot_id, notes, content_name, content_reference_type, content_reference_id) SELECT id, last_modified, datetime_added, name, position, quantity, customer_project_id, template_project_id, parent_id, system_template_id, source_slot_id, notes, COALESCE((SELECT st.name FROM production_system_templates st WHERE st.id = __temp__production_project_positions.system_template_id), (SELECT p.name FROM projects p WHERE p.id = __temp__production_project_positions.template_project_id), name), CASE WHEN system_template_id IS NOT NULL THEN 'system_template' WHEN template_project_id IS NOT NULL THEN 'project' ELSE NULL END, COALESCE(system_template_id, template_project_id) FROM __temp__production_project_positions");
        $this->addSql('DROP TABLE __temp__production_project_positions');
        $this->addSql('CREATE INDEX IDX_FBEBCF6CEBA41B1E ON production_project_positions (customer_project_id)');
        $this->addSql('CREATE INDEX IDX_FBEBCF6C34A65A53 ON production_project_positions (template_project_id)');
        $this->addSql('CREATE INDEX IDX_FBEBCF6C727ACA70 ON production_project_positions (parent_id)');
        $this->addSql('CREATE INDEX IDX_PROD_POSITION_SYSTEM_TEMPLATE ON production_project_positions (system_template_id)');
        $this->addSql('CREATE INDEX IDX_PROD_POSITION_SOURCE_SLOT ON production_project_positions (source_slot_id)');

        $this->addSql('CREATE TEMPORARY TABLE __temp__production_build_instances AS SELECT * FROM production_build_instances');
        $this->addSql('DROP TABLE production_build_instances');
        $this->addSql('CREATE TABLE production_build_instances (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, template_project_id INTEGER DEFAULT NULL, system_template_id INTEGER DEFAULT NULL, customer_project_id INTEGER DEFAULT NULL, serial_number VARCHAR(128) NOT NULL, status VARCHAR(32) NOT NULL, location VARCHAR(255) DEFAULT NULL, completed_at DATETIME DEFAULT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, project_position_id INTEGER DEFAULT NULL, notes CLOB DEFAULT NULL, content_name VARCHAR(255) DEFAULT NULL, content_reference_type VARCHAR(32) DEFAULT NULL, content_reference_id INTEGER DEFAULT NULL, CONSTRAINT FK_PRODUCTION_BUILD_TEMPLATE FOREIGN KEY (template_project_id) REFERENCES projects (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PROD_BUILD_SYSTEM_TEMPLATE FOREIGN KEY (system_template_id) REFERENCES production_system_templates (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PRODUCTION_BUILD_CUSTOMER_PROJECT FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_4C67941F154BD9BD FOREIGN KEY (project_position_id) REFERENCES production_project_positions (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql("INSERT INTO production_build_instances (id, template_project_id, system_template_id, customer_project_id, serial_number, status, location, completed_at, last_modified, datetime_added, project_position_id, notes, content_name, content_reference_type, content_reference_id) SELECT id, template_project_id, system_template_id, customer_project_id, serial_number, status, location, completed_at, last_modified, datetime_added, project_position_id, notes, COALESCE((SELECT st.name FROM production_system_templates st WHERE st.id = __temp__production_build_instances.system_template_id), (SELECT p.name FROM projects p WHERE p.id = __temp__production_build_instances.template_project_id), serial_number), CASE WHEN system_template_id IS NOT NULL THEN 'system_template' WHEN template_project_id IS NOT NULL THEN 'project' ELSE NULL END, COALESCE(system_template_id, template_project_id) FROM __temp__production_build_instances");
        $this->addSql('DROP TABLE __temp__production_build_instances');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4C67941FD948EE2 ON production_build_instances (serial_number)');
        $this->addSql('CREATE INDEX IDX_4C67941F34A65A53 ON production_build_instances (template_project_id)');
        $this->addSql('CREATE INDEX IDX_PROD_BUILD_SYSTEM_TEMPLATE ON production_build_instances (system_template_id)');
        $this->addSql('CREATE INDEX IDX_4C67941FEBA41B1E ON production_build_instances (customer_project_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PROD_BUILD_POSITION ON production_build_instances (project_position_id)');

        $this->addSql('PRAGMA foreign_keys = ON');
    }

    public function sqLiteDown(Schema $schema): void
    {
        $this->abortDowngrade();
    }

    public function postgreSQLUp(Schema $schema): void
    {
        $this->guardExistingData('CAST(quantity AS INTEGER)');

        $this->addSql('ALTER TABLE production_system_templates ADD base_project_name VARCHAR(255) DEFAULT NULL, ADD base_project_reference_id INT DEFAULT NULL');
        $this->addSql('UPDATE production_system_templates st SET base_project_name = p.name, base_project_reference_id = st.base_project_id FROM projects p WHERE p.id = st.base_project_id');
        $this->addSql('ALTER TABLE production_system_templates DROP CONSTRAINT FK_PROD_SYSTEM_TEMPLATE_BASE');
        $this->addSql('ALTER TABLE production_system_templates ADD CONSTRAINT FK_PROD_SYSTEM_TEMPLATE_BASE FOREIGN KEY (base_project_id) REFERENCES projects (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE production_project_positions ADD content_name VARCHAR(255) DEFAULT NULL, ADD content_reference_type VARCHAR(32) DEFAULT NULL, ADD content_reference_id INT DEFAULT NULL');
        $this->addSql("UPDATE production_project_positions pp SET content_name = COALESCE((SELECT st.name FROM production_system_templates st WHERE st.id = pp.system_template_id), (SELECT p.name FROM projects p WHERE p.id = pp.template_project_id), pp.name), content_reference_type = CASE WHEN system_template_id IS NOT NULL THEN 'system_template' WHEN template_project_id IS NOT NULL THEN 'project' ELSE NULL END, content_reference_id = COALESCE(system_template_id, template_project_id)");
        $this->addSql('ALTER TABLE production_project_positions DROP CONSTRAINT FK_FBEBCF6C34A65A53');
        $this->addSql('ALTER TABLE production_project_positions ADD CONSTRAINT FK_FBEBCF6C34A65A53 FOREIGN KEY (template_project_id) REFERENCES projects (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE production_build_instances ADD content_name VARCHAR(255) DEFAULT NULL, ADD content_reference_type VARCHAR(32) DEFAULT NULL, ADD content_reference_id INT DEFAULT NULL');
        $this->addSql("UPDATE production_build_instances bi SET content_name = COALESCE((SELECT st.name FROM production_system_templates st WHERE st.id = bi.system_template_id), (SELECT p.name FROM projects p WHERE p.id = bi.template_project_id), bi.serial_number), content_reference_type = CASE WHEN system_template_id IS NOT NULL THEN 'system_template' WHEN template_project_id IS NOT NULL THEN 'project' ELSE NULL END, content_reference_id = COALESCE(system_template_id, template_project_id)");
        $this->addSql('ALTER TABLE production_build_instances DROP CONSTRAINT FK_PRODUCTION_BUILD_TEMPLATE');
        $this->addSql('ALTER TABLE production_build_instances ADD CONSTRAINT FK_PRODUCTION_BUILD_TEMPLATE FOREIGN KEY (template_project_id) REFERENCES projects (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PROD_BUILD_POSITION ON production_build_instances (project_position_id)');
        $this->addSql('DROP INDEX IDX_4C67941F154BD9BD');

        $this->addSql('ALTER TABLE production_project_accessories ADD part_name VARCHAR(255) DEFAULT NULL, ADD part_reference_id INT DEFAULT NULL');
        $this->addSql('UPDATE production_project_accessories pa SET part_name = p.name, part_reference_id = pa.part_id FROM parts p WHERE p.id = pa.part_id');
        $this->addSql('ALTER TABLE production_project_accessories DROP CONSTRAINT FK_PROD_ACCESSORY_PART');
        $this->addSql('ALTER TABLE production_project_accessories ALTER part_id DROP NOT NULL, ALTER part_name SET NOT NULL');
        $this->addSql('ALTER TABLE production_project_accessories ADD CONSTRAINT FK_PROD_ACCESSORY_PART FOREIGN KEY (part_id) REFERENCES parts (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE production_project_material_allocations ADD part_name VARCHAR(255) DEFAULT NULL, ADD part_reference_id INT DEFAULT NULL');
        $this->addSql('UPDATE production_project_material_allocations ma SET part_name = p.name, part_reference_id = ma.part_id FROM parts p WHERE p.id = ma.part_id');
        $this->addSql('ALTER TABLE production_project_material_allocations DROP CONSTRAINT FK_PROD_MATERIAL_PART');
        $this->addSql('ALTER TABLE production_project_material_allocations ALTER part_id DROP NOT NULL, ALTER quantity TYPE INT USING quantity::INT, ALTER part_name SET NOT NULL');
        $this->addSql('ALTER TABLE production_project_material_allocations ADD CONSTRAINT FK_PROD_MATERIAL_PART FOREIGN KEY (part_id) REFERENCES parts (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function postgreSQLDown(Schema $schema): void
    {
        $this->abortDowngrade();
    }

    private function guardExistingData(string $integerCast): void
    {
        $fractionalAllocations = (int) $this->connection->fetchOne(sprintf(
            'SELECT COUNT(*) FROM production_project_material_allocations WHERE quantity <> %s',
            $integerCast,
        ));
        $this->abortIf($fractionalAllocations > 0, 'Production material contains fractional allocations. Return or correct them before enabling integer-only production stock.');

        $duplicatePositions = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM (SELECT project_position_id FROM production_build_instances WHERE project_position_id IS NOT NULL GROUP BY project_position_id HAVING COUNT(*) > 1) duplicate_positions');
        $this->abortIf($duplicatePositions > 0, 'More than one device is assigned to a project position. Resolve these assignments before applying the unique position constraint.');
    }

    private function abortDowngrade(): never
    {
        throw new IrreversibleMigration('This migration adds historical snapshots and integer-only material semantics. Restore the database backup to downgrade safely.');
    }
}
