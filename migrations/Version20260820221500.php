<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * Normalizes installations on which Version20260820220000 was already run
 * before its generated index names and default FK actions were aligned with
 * Doctrine's schema comparator. Fresh installations already have the desired
 * schema, making this migration a no-op there.
 */
final class Version20260820221500 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Normalize early production table index names and foreign-key actions without losing data.';
    }

    public function mySQLUp(Schema $schema): void
    {
        if (!$this->hasLegacyProductionIndexes($schema)) {
            return;
        }

        $this->addSql('ALTER TABLE production_customers RENAME INDEX UNIQ_PRODUCTION_CUSTOMER_NUMBER TO UNIQ_C5CC1FFB2755C305');
        $this->addSql('ALTER TABLE production_customer_projects RENAME INDEX IDX_PRODUCTION_PROJECT_CUSTOMER TO IDX_4C1E9F4C9395C3F3, RENAME INDEX UNIQ_PRODUCTION_PROJECT_NUMBER TO UNIQ_4C1E9F4C8134F41E');
        $this->addSql('ALTER TABLE production_build_instances RENAME INDEX IDX_PRODUCTION_BUILD_TEMPLATE TO IDX_4C67941F34A65A53, RENAME INDEX IDX_PRODUCTION_BUILD_CUSTOMER_PROJECT TO IDX_4C67941FEBA41B1E, RENAME INDEX UNIQ_PRODUCTION_BUILD_SERIAL TO UNIQ_4C67941FD948EE2');
    }

    public function mySQLDown(Schema $schema): void
    {
        // This compatibility migration intentionally has no reverse operation.
    }

    public function sqLiteUp(Schema $schema): void
    {
        if (!$this->hasLegacyProductionIndexes($schema)) {
            return;
        }

        $this->addSql('CREATE TEMPORARY TABLE __backup__production_customers AS SELECT * FROM production_customers');
        $this->addSql('CREATE TEMPORARY TABLE __backup__production_customer_projects AS SELECT * FROM production_customer_projects');
        $this->addSql('CREATE TEMPORARY TABLE __backup__production_build_instances AS SELECT * FROM production_build_instances');

        $this->addSql('DROP TABLE production_build_instances');
        $this->addSql('DROP TABLE production_customer_projects');
        $this->addSql('DROP TABLE production_customers');

        $this->addSql('CREATE TABLE production_customers (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, customer_number VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, description CLOB NOT NULL, active BOOLEAN DEFAULT 1 NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C5CC1FFB2755C305 ON production_customers (customer_number)');
        $this->addSql('CREATE TABLE production_customer_projects (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, customer_id INTEGER NOT NULL, project_number VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, status VARCHAR(32) NOT NULL, description CLOB NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, CONSTRAINT FK_PRODUCTION_PROJECT_CUSTOMER FOREIGN KEY (customer_id) REFERENCES production_customers (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_4C1E9F4C9395C3F3 ON production_customer_projects (customer_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4C1E9F4C8134F41E ON production_customer_projects (project_number)');
        $this->addSql('CREATE TABLE production_build_instances (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, template_project_id INTEGER NOT NULL, customer_project_id INTEGER DEFAULT NULL, serial_number VARCHAR(128) NOT NULL, status VARCHAR(32) NOT NULL, location VARCHAR(255) DEFAULT NULL, completed_at DATETIME DEFAULT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, CONSTRAINT FK_PRODUCTION_BUILD_TEMPLATE FOREIGN KEY (template_project_id) REFERENCES projects (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PRODUCTION_BUILD_CUSTOMER_PROJECT FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_4C67941F34A65A53 ON production_build_instances (template_project_id)');
        $this->addSql('CREATE INDEX IDX_4C67941FEBA41B1E ON production_build_instances (customer_project_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4C67941FD948EE2 ON production_build_instances (serial_number)');

        $this->addSql('INSERT INTO production_customers SELECT * FROM __backup__production_customers');
        $this->addSql('INSERT INTO production_customer_projects SELECT * FROM __backup__production_customer_projects');
        $this->addSql('INSERT INTO production_build_instances SELECT * FROM __backup__production_build_instances');

        $this->addSql('DROP TABLE __backup__production_build_instances');
        $this->addSql('DROP TABLE __backup__production_customer_projects');
        $this->addSql('DROP TABLE __backup__production_customers');
    }

    public function sqLiteDown(Schema $schema): void
    {
        // This compatibility migration intentionally has no reverse operation.
    }

    public function postgreSQLUp(Schema $schema): void
    {
        if (!$this->hasLegacyProductionIndexes($schema)) {
            return;
        }

        $this->addSql('ALTER INDEX UNIQ_PRODUCTION_CUSTOMER_NUMBER RENAME TO UNIQ_C5CC1FFB2755C305');
        $this->addSql('ALTER INDEX IDX_PRODUCTION_PROJECT_CUSTOMER RENAME TO IDX_4C1E9F4C9395C3F3');
        $this->addSql('ALTER INDEX UNIQ_PRODUCTION_PROJECT_NUMBER RENAME TO UNIQ_4C1E9F4C8134F41E');
        $this->addSql('ALTER INDEX IDX_PRODUCTION_BUILD_TEMPLATE RENAME TO IDX_4C67941F34A65A53');
        $this->addSql('ALTER INDEX IDX_PRODUCTION_BUILD_CUSTOMER_PROJECT RENAME TO IDX_4C67941FEBA41B1E');
        $this->addSql('ALTER INDEX UNIQ_PRODUCTION_BUILD_SERIAL RENAME TO UNIQ_4C67941FD948EE2');
    }

    public function postgreSQLDown(Schema $schema): void
    {
        // This compatibility migration intentionally has no reverse operation.
    }

    private function hasLegacyProductionIndexes(Schema $schema): bool
    {
        return $schema->hasTable('production_customers')
            && $schema->getTable('production_customers')->hasIndex('UNIQ_PRODUCTION_CUSTOMER_NUMBER');
    }
}
