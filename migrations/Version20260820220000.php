<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260820220000 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Add the isolated production module with customers, customer projects and build instances.';
    }

    public function mySQLUp(Schema $schema): void
    {
        $this->addSql('CREATE TABLE production_customers (id INT AUTO_INCREMENT NOT NULL, customer_number VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT NOT NULL, active TINYINT(1) DEFAULT 1 NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, UNIQUE INDEX UNIQ_C5CC1FFB2755C305 (customer_number), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE production_customer_projects (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, project_number VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, status VARCHAR(32) NOT NULL, description LONGTEXT NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, INDEX IDX_4C1E9F4C9395C3F3 (customer_id), UNIQUE INDEX UNIQ_4C1E9F4C8134F41E (project_number), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE production_build_instances (id INT AUTO_INCREMENT NOT NULL, template_project_id INT NOT NULL, customer_project_id INT DEFAULT NULL, serial_number VARCHAR(128) NOT NULL, status VARCHAR(32) NOT NULL, location VARCHAR(255) DEFAULT NULL, completed_at DATETIME DEFAULT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, INDEX IDX_4C67941F34A65A53 (template_project_id), INDEX IDX_4C67941FEBA41B1E (customer_project_id), UNIQUE INDEX UNIQ_4C67941FD948EE2 (serial_number), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE production_customer_projects ADD CONSTRAINT FK_PRODUCTION_PROJECT_CUSTOMER FOREIGN KEY (customer_id) REFERENCES production_customers (id)');
        $this->addSql('ALTER TABLE production_build_instances ADD CONSTRAINT FK_PRODUCTION_BUILD_TEMPLATE FOREIGN KEY (template_project_id) REFERENCES projects (id)');
        $this->addSql('ALTER TABLE production_build_instances ADD CONSTRAINT FK_PRODUCTION_BUILD_CUSTOMER_PROJECT FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON DELETE SET NULL');
    }

    public function mySQLDown(Schema $schema): void
    {
        $this->addSql('DROP TABLE production_build_instances');
        $this->addSql('DROP TABLE production_customer_projects');
        $this->addSql('DROP TABLE production_customers');
    }

    public function sqLiteUp(Schema $schema): void
    {
        $this->addSql('CREATE TABLE production_customers (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, customer_number VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, description CLOB NOT NULL, active BOOLEAN DEFAULT 1 NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C5CC1FFB2755C305 ON production_customers (customer_number)');
        $this->addSql('CREATE TABLE production_customer_projects (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, customer_id INTEGER NOT NULL, project_number VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, status VARCHAR(32) NOT NULL, description CLOB NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, CONSTRAINT FK_PRODUCTION_PROJECT_CUSTOMER FOREIGN KEY (customer_id) REFERENCES production_customers (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_4C1E9F4C9395C3F3 ON production_customer_projects (customer_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4C1E9F4C8134F41E ON production_customer_projects (project_number)');
        $this->addSql('CREATE TABLE production_build_instances (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, template_project_id INTEGER NOT NULL, customer_project_id INTEGER DEFAULT NULL, serial_number VARCHAR(128) NOT NULL, status VARCHAR(32) NOT NULL, location VARCHAR(255) DEFAULT NULL, completed_at DATETIME DEFAULT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, CONSTRAINT FK_PRODUCTION_BUILD_TEMPLATE FOREIGN KEY (template_project_id) REFERENCES projects (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PRODUCTION_BUILD_CUSTOMER_PROJECT FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_4C67941F34A65A53 ON production_build_instances (template_project_id)');
        $this->addSql('CREATE INDEX IDX_4C67941FEBA41B1E ON production_build_instances (customer_project_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4C67941FD948EE2 ON production_build_instances (serial_number)');
    }

    public function sqLiteDown(Schema $schema): void
    {
        $this->addSql('DROP TABLE production_build_instances');
        $this->addSql('DROP TABLE production_customer_projects');
        $this->addSql('DROP TABLE production_customers');
    }

    public function postgreSQLUp(Schema $schema): void
    {
        $this->addSql('CREATE TABLE production_customers (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, customer_number VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, description TEXT NOT NULL, active BOOLEAN DEFAULT TRUE NOT NULL, last_modified TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C5CC1FFB2755C305 ON production_customers (customer_number)');
        $this->addSql('CREATE TABLE production_customer_projects (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, customer_id INT NOT NULL, project_number VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, status VARCHAR(32) NOT NULL, description TEXT NOT NULL, last_modified TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_4C1E9F4C9395C3F3 ON production_customer_projects (customer_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4C1E9F4C8134F41E ON production_customer_projects (project_number)');
        $this->addSql('CREATE TABLE production_build_instances (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, template_project_id INT NOT NULL, customer_project_id INT DEFAULT NULL, serial_number VARCHAR(128) NOT NULL, status VARCHAR(32) NOT NULL, location VARCHAR(255) DEFAULT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_modified TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_4C67941F34A65A53 ON production_build_instances (template_project_id)');
        $this->addSql('CREATE INDEX IDX_4C67941FEBA41B1E ON production_build_instances (customer_project_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4C67941FD948EE2 ON production_build_instances (serial_number)');
        $this->addSql('ALTER TABLE production_customer_projects ADD CONSTRAINT FK_PRODUCTION_PROJECT_CUSTOMER FOREIGN KEY (customer_id) REFERENCES production_customers (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE production_build_instances ADD CONSTRAINT FK_PRODUCTION_BUILD_TEMPLATE FOREIGN KEY (template_project_id) REFERENCES projects (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE production_build_instances ADD CONSTRAINT FK_PRODUCTION_BUILD_CUSTOMER_PROJECT FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function postgreSQLDown(Schema $schema): void
    {
        $this->addSql('DROP TABLE production_build_instances');
        $this->addSql('DROP TABLE production_customer_projects');
        $this->addSql('DROP TABLE production_customers');
    }
}
