<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20260824120000 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Allow production devices without serial numbers and record nested builds and consumed material.';
    }

    public function isTransactional(): bool
    {
        return 'sqlite' !== $this->getDatabaseType();
    }

    public function mySQLUp(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_build_instances ADD parent_id INT DEFAULT NULL, MODIFY serial_number VARCHAR(128) DEFAULT NULL');
        $this->addSql('ALTER TABLE production_build_instances ADD CONSTRAINT FK_PROD_BUILD_PARENT FOREIGN KEY (parent_id) REFERENCES production_build_instances (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_PROD_BUILD_PARENT ON production_build_instances (parent_id)');
        $this->createUsageTable('INT AUTO_INCREMENT NOT NULL', 'INT NOT NULL', 'TINYINT(1) DEFAULT 0 NOT NULL', 'DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL', 'ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
    }

    public function sqLiteUp(Schema $schema): void
    {
        $this->addSql('PRAGMA foreign_keys = OFF');
        $this->addSql('CREATE TEMPORARY TABLE __temp__production_build_instances AS SELECT * FROM production_build_instances');
        $this->addSql('DROP TABLE production_build_instances');
        $this->addSql('CREATE TABLE production_build_instances (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, template_project_id INTEGER DEFAULT NULL, system_template_id INTEGER DEFAULT NULL, customer_project_id INTEGER DEFAULT NULL, serial_number VARCHAR(128) DEFAULT NULL, status VARCHAR(32) NOT NULL, location VARCHAR(255) DEFAULT NULL, completed_at DATETIME DEFAULT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, project_position_id INTEGER DEFAULT NULL, notes CLOB DEFAULT NULL, content_name VARCHAR(255) DEFAULT NULL, content_reference_type VARCHAR(32) DEFAULT NULL, content_reference_id INTEGER DEFAULT NULL, parent_id INTEGER DEFAULT NULL, CONSTRAINT FK_PRODUCTION_BUILD_TEMPLATE FOREIGN KEY (template_project_id) REFERENCES projects (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PROD_BUILD_SYSTEM_TEMPLATE FOREIGN KEY (system_template_id) REFERENCES production_system_templates (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PRODUCTION_BUILD_CUSTOMER_PROJECT FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_4C67941F154BD9BD FOREIGN KEY (project_position_id) REFERENCES production_project_positions (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PROD_BUILD_PARENT FOREIGN KEY (parent_id) REFERENCES production_build_instances (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO production_build_instances (id, template_project_id, system_template_id, customer_project_id, serial_number, status, location, completed_at, last_modified, datetime_added, project_position_id, notes, content_name, content_reference_type, content_reference_id) SELECT id, template_project_id, system_template_id, customer_project_id, serial_number, status, location, completed_at, last_modified, datetime_added, project_position_id, notes, content_name, content_reference_type, content_reference_id FROM __temp__production_build_instances');
        $this->addSql('DROP TABLE __temp__production_build_instances');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4C67941FD948EE2 ON production_build_instances (serial_number)');
        $this->addSql('CREATE INDEX IDX_4C67941F34A65A53 ON production_build_instances (template_project_id)');
        $this->addSql('CREATE INDEX IDX_PROD_BUILD_SYSTEM_TEMPLATE ON production_build_instances (system_template_id)');
        $this->addSql('CREATE INDEX IDX_4C67941FEBA41B1E ON production_build_instances (customer_project_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PROD_BUILD_POSITION ON production_build_instances (project_position_id)');
        $this->addSql('CREATE INDEX IDX_PROD_BUILD_PARENT ON production_build_instances (parent_id)');
        $this->createUsageTable('INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL', 'INTEGER NOT NULL', 'BOOLEAN DEFAULT 0 NOT NULL', 'DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL', '', '');
        $this->addSql('PRAGMA foreign_keys = ON');
    }

    public function postgreSQLUp(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_build_instances ADD parent_id INT DEFAULT NULL, ALTER serial_number DROP NOT NULL');
        $this->addSql('ALTER TABLE production_build_instances ADD CONSTRAINT FK_PROD_BUILD_PARENT FOREIGN KEY (parent_id) REFERENCES production_build_instances (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_PROD_BUILD_PARENT ON production_build_instances (parent_id)');
        $this->createUsageTable('SERIAL NOT NULL', 'INT NOT NULL', 'BOOLEAN DEFAULT FALSE NOT NULL', 'TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL', '');
    }

    private function createUsageTable(string $id, string $integer, string $boolean, string $datetime, string $suffix, string $primaryKey = ', PRIMARY KEY(id)'): void
    {
        $this->addSql(sprintf('CREATE TABLE production_build_material_usages (id %s, build_instance_id %s, part_id INT DEFAULT NULL, source_part_lot_id INT DEFAULT NULL, allocated_by_id INT DEFAULT NULL, part_name VARCHAR(255) NOT NULL, part_reference_id INT DEFAULT NULL, source_lot_name VARCHAR(255) DEFAULT NULL, source_location_name VARCHAR(255) DEFAULT NULL, quantity %s, from_project_stock %s, serial_number VARCHAR(128) DEFAULT NULL, last_modified %s, datetime_added %s%s, CONSTRAINT FK_PROD_USAGE_BUILD FOREIGN KEY (build_instance_id) REFERENCES production_build_instances (id) ON DELETE CASCADE, CONSTRAINT FK_PROD_USAGE_PART FOREIGN KEY (part_id) REFERENCES parts (id) ON DELETE SET NULL, CONSTRAINT FK_PROD_USAGE_LOT FOREIGN KEY (source_part_lot_id) REFERENCES part_lots (id) ON DELETE SET NULL, CONSTRAINT FK_PROD_USAGE_USER FOREIGN KEY (allocated_by_id) REFERENCES users (id) ON DELETE SET NULL) %s', $id, $integer, $integer, $boolean, $datetime, $datetime, $primaryKey, $suffix));
        $this->addSql('CREATE INDEX IDX_PROD_USAGE_BUILD ON production_build_material_usages (build_instance_id)');
        $this->addSql('CREATE INDEX IDX_PROD_USAGE_PART ON production_build_material_usages (part_id)');
        $this->addSql('CREATE INDEX IDX_PROD_USAGE_LOT ON production_build_material_usages (source_part_lot_id)');
        $this->addSql('CREATE INDEX IDX_PROD_USAGE_USER ON production_build_material_usages (allocated_by_id)');
    }

    public function mySQLDown(Schema $schema): void { $this->abortDowngrade(); }
    public function sqLiteDown(Schema $schema): void { $this->abortDowngrade(); }
    public function postgreSQLDown(Schema $schema): void { $this->abortDowngrade(); }
    private function abortDowngrade(): never { throw new IrreversibleMigration('Restore the database backup to downgrade production build records safely.'); }
}
