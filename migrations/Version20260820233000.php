<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260820233000 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Add optional project customers, project positions, build-to-position assignments and production history.';
    }

    public function mySQLUp(Schema $schema): void
    {
        $this->addSql("UPDATE production_customer_projects SET status = CASE status WHEN 'draft' THEN 'planning' WHEN 'active' THEN 'commissioned' WHEN 'on_hold' THEN 'planning' ELSE status END");
        $this->addSql('ALTER TABLE production_customer_projects DROP FOREIGN KEY FK_PRODUCTION_PROJECT_CUSTOMER');
        $this->addSql('ALTER TABLE production_customer_projects CHANGE customer_id customer_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE production_customer_projects ADD CONSTRAINT FK_4C1E9F4C9395C3F3 FOREIGN KEY (customer_id) REFERENCES production_customers (id) ON DELETE SET NULL');

        $this->addSql('CREATE TABLE production_project_positions (id INT AUTO_INCREMENT NOT NULL, customer_project_id INT NOT NULL, template_project_id INT NOT NULL, parent_id INT DEFAULT NULL, name VARCHAR(255) NOT NULL, position INT DEFAULT 0 NOT NULL, quantity INT DEFAULT 1 NOT NULL, status VARCHAR(32) NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, INDEX IDX_FBEBCF6CEBA41B1E (customer_project_id), INDEX IDX_FBEBCF6C34A65A53 (template_project_id), INDEX IDX_FBEBCF6C727ACA70 (parent_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE production_project_positions ADD CONSTRAINT FK_FBEBCF6CEBA41B1E FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE production_project_positions ADD CONSTRAINT FK_FBEBCF6C34A65A53 FOREIGN KEY (template_project_id) REFERENCES projects (id)');
        $this->addSql('ALTER TABLE production_project_positions ADD CONSTRAINT FK_FBEBCF6C727ACA70 FOREIGN KEY (parent_id) REFERENCES production_project_positions (id) ON DELETE SET NULL');

        $this->addSql('INSERT INTO production_project_positions (id, customer_project_id, template_project_id, parent_id, name, position, quantity, status, last_modified, datetime_added) SELECT bi.id, bi.customer_project_id, bi.template_project_id, NULL, p.name, bi.id, 1, bi.status, bi.last_modified, bi.datetime_added FROM production_build_instances bi INNER JOIN projects p ON p.id = bi.template_project_id WHERE bi.customer_project_id IS NOT NULL');
        $this->addSql('ALTER TABLE production_build_instances ADD project_position_id INT DEFAULT NULL');
        $this->addSql('UPDATE production_build_instances SET project_position_id = id WHERE customer_project_id IS NOT NULL');
        $this->addSql('ALTER TABLE production_build_instances ADD CONSTRAINT FK_4C67941F154BD9BD FOREIGN KEY (project_position_id) REFERENCES production_project_positions (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_4C67941F154BD9BD ON production_build_instances (project_position_id)');

        $this->addSql('CREATE TABLE production_history (id INT AUTO_INCREMENT NOT NULL, customer_project_id INT NOT NULL, build_instance_id INT DEFAULT NULL, actor_id INT DEFAULT NULL, event_type VARCHAR(64) NOT NULL, description VARCHAR(255) NOT NULL, occurred_at DATETIME NOT NULL, INDEX IDX_32AFFD96EBA41B1E (customer_project_id), INDEX IDX_32AFFD96881EA2B7 (build_instance_id), INDEX IDX_32AFFD9610DAF24A (actor_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE production_history ADD CONSTRAINT FK_32AFFD96EBA41B1E FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE production_history ADD CONSTRAINT FK_32AFFD96881EA2B7 FOREIGN KEY (build_instance_id) REFERENCES production_build_instances (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE production_history ADD CONSTRAINT FK_32AFFD9610DAF24A FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql("INSERT INTO production_history (customer_project_id, build_instance_id, actor_id, event_type, description, occurred_at) SELECT id, NULL, NULL, 'project_created', '', datetime_added FROM production_customer_projects");
    }

    public function mySQLDown(Schema $schema): void
    {
        $this->addSql('DROP TABLE production_history');
        $this->addSql('ALTER TABLE production_build_instances DROP FOREIGN KEY FK_4C67941F154BD9BD');
        $this->addSql('DROP INDEX IDX_4C67941F154BD9BD ON production_build_instances');
        $this->addSql('ALTER TABLE production_build_instances DROP project_position_id');
        $this->addSql('DROP TABLE production_project_positions');
        $this->addSql('ALTER TABLE production_customer_projects DROP FOREIGN KEY FK_4C1E9F4C9395C3F3');
        $this->addSql('ALTER TABLE production_customer_projects CHANGE customer_id customer_id INT NOT NULL');
        $this->addSql('ALTER TABLE production_customer_projects ADD CONSTRAINT FK_PRODUCTION_PROJECT_CUSTOMER FOREIGN KEY (customer_id) REFERENCES production_customers (id)');
    }

    public function sqLiteUp(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __backup__production_customer_projects AS SELECT * FROM production_customer_projects');
        $this->addSql('CREATE TEMPORARY TABLE __backup__production_build_instances AS SELECT * FROM production_build_instances');
        $this->addSql('DROP TABLE production_build_instances');
        $this->addSql('DROP TABLE production_customer_projects');

        $this->addSql('CREATE TABLE production_customer_projects (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, customer_id INTEGER DEFAULT NULL, project_number VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, status VARCHAR(32) NOT NULL, description CLOB NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, CONSTRAINT FK_4C1E9F4C9395C3F3 FOREIGN KEY (customer_id) REFERENCES production_customers (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql("INSERT INTO production_customer_projects (id, customer_id, project_number, name, status, description, last_modified, datetime_added) SELECT id, customer_id, project_number, name, CASE status WHEN 'draft' THEN 'planning' WHEN 'active' THEN 'commissioned' WHEN 'on_hold' THEN 'planning' ELSE status END, description, last_modified, datetime_added FROM __backup__production_customer_projects");
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4C1E9F4C8134F41E ON production_customer_projects (project_number)');
        $this->addSql('CREATE INDEX IDX_4C1E9F4C9395C3F3 ON production_customer_projects (customer_id)');

        $this->addSql('CREATE TABLE production_project_positions (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, name VARCHAR(255) NOT NULL, position INTEGER DEFAULT 0 NOT NULL, quantity INTEGER DEFAULT 1 NOT NULL, status VARCHAR(32) NOT NULL, customer_project_id INTEGER NOT NULL, template_project_id INTEGER NOT NULL, parent_id INTEGER DEFAULT NULL, CONSTRAINT FK_FBEBCF6CEBA41B1E FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_FBEBCF6C34A65A53 FOREIGN KEY (template_project_id) REFERENCES projects (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_FBEBCF6C727ACA70 FOREIGN KEY (parent_id) REFERENCES production_project_positions (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_FBEBCF6CEBA41B1E ON production_project_positions (customer_project_id)');
        $this->addSql('CREATE INDEX IDX_FBEBCF6C34A65A53 ON production_project_positions (template_project_id)');
        $this->addSql('CREATE INDEX IDX_FBEBCF6C727ACA70 ON production_project_positions (parent_id)');
        $this->addSql('INSERT INTO production_project_positions (id, customer_project_id, template_project_id, parent_id, name, position, quantity, status, last_modified, datetime_added) SELECT bi.id, bi.customer_project_id, bi.template_project_id, NULL, p.name, bi.id, 1, bi.status, bi.last_modified, bi.datetime_added FROM __backup__production_build_instances bi INNER JOIN projects p ON p.id = bi.template_project_id WHERE bi.customer_project_id IS NOT NULL');

        $this->addSql('CREATE TABLE production_build_instances (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, template_project_id INTEGER NOT NULL, customer_project_id INTEGER DEFAULT NULL, serial_number VARCHAR(128) NOT NULL, status VARCHAR(32) NOT NULL, location VARCHAR(255) DEFAULT NULL, completed_at DATETIME DEFAULT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, project_position_id INTEGER DEFAULT NULL, CONSTRAINT FK_PRODUCTION_BUILD_TEMPLATE FOREIGN KEY (template_project_id) REFERENCES projects (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PRODUCTION_BUILD_CUSTOMER_PROJECT FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_4C67941F154BD9BD FOREIGN KEY (project_position_id) REFERENCES production_project_positions (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO production_build_instances (id, template_project_id, customer_project_id, serial_number, status, location, completed_at, last_modified, datetime_added, project_position_id) SELECT id, template_project_id, customer_project_id, serial_number, status, location, completed_at, last_modified, datetime_added, CASE WHEN customer_project_id IS NOT NULL THEN id ELSE NULL END FROM __backup__production_build_instances');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4C67941FD948EE2 ON production_build_instances (serial_number)');
        $this->addSql('CREATE INDEX IDX_4C67941FEBA41B1E ON production_build_instances (customer_project_id)');
        $this->addSql('CREATE INDEX IDX_4C67941F34A65A53 ON production_build_instances (template_project_id)');
        $this->addSql('CREATE INDEX IDX_4C67941F154BD9BD ON production_build_instances (project_position_id)');

        $this->addSql('CREATE TABLE production_history (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, event_type VARCHAR(64) NOT NULL, description VARCHAR(255) NOT NULL, occurred_at DATETIME NOT NULL, customer_project_id INTEGER NOT NULL, build_instance_id INTEGER DEFAULT NULL, actor_id INTEGER DEFAULT NULL, CONSTRAINT FK_32AFFD96EBA41B1E FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_32AFFD96881EA2B7 FOREIGN KEY (build_instance_id) REFERENCES production_build_instances (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_32AFFD9610DAF24A FOREIGN KEY (actor_id) REFERENCES "users" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_32AFFD96EBA41B1E ON production_history (customer_project_id)');
        $this->addSql('CREATE INDEX IDX_32AFFD96881EA2B7 ON production_history (build_instance_id)');
        $this->addSql('CREATE INDEX IDX_32AFFD9610DAF24A ON production_history (actor_id)');
        $this->addSql("INSERT INTO production_history (customer_project_id, build_instance_id, actor_id, event_type, description, occurred_at) SELECT id, NULL, NULL, 'project_created', '', datetime_added FROM production_customer_projects");

        $this->addSql('DROP TABLE __backup__production_build_instances');
        $this->addSql('DROP TABLE __backup__production_customer_projects');
    }

    public function sqLiteDown(Schema $schema): void
    {
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM production_customer_projects WHERE customer_id IS NULL') > 0, 'Projects without customers prevent this migration from being reverted.');

        $this->addSql('DROP TABLE production_history');
        $this->addSql('CREATE TEMPORARY TABLE __temp__production_build_instances AS SELECT id, template_project_id, customer_project_id, serial_number, status, location, completed_at, last_modified, datetime_added FROM production_build_instances');
        $this->addSql('DROP TABLE production_build_instances');
        $this->addSql('DROP TABLE production_project_positions');
        $this->addSql('CREATE TABLE production_build_instances (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, template_project_id INTEGER NOT NULL, customer_project_id INTEGER DEFAULT NULL, serial_number VARCHAR(128) NOT NULL, status VARCHAR(32) NOT NULL, location VARCHAR(255) DEFAULT NULL, completed_at DATETIME DEFAULT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, CONSTRAINT FK_PRODUCTION_BUILD_TEMPLATE FOREIGN KEY (template_project_id) REFERENCES projects (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PRODUCTION_BUILD_CUSTOMER_PROJECT FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO production_build_instances SELECT * FROM __temp__production_build_instances');
        $this->addSql('DROP TABLE __temp__production_build_instances');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4C67941FD948EE2 ON production_build_instances (serial_number)');
        $this->addSql('CREATE INDEX IDX_4C67941FEBA41B1E ON production_build_instances (customer_project_id)');
        $this->addSql('CREATE INDEX IDX_4C67941F34A65A53 ON production_build_instances (template_project_id)');
    }

    public function postgreSQLUp(Schema $schema): void
    {
        $this->addSql("UPDATE production_customer_projects SET status = CASE status WHEN 'draft' THEN 'planning' WHEN 'active' THEN 'commissioned' WHEN 'on_hold' THEN 'planning' ELSE status END");
        $this->addSql('ALTER TABLE production_customer_projects DROP CONSTRAINT FK_PRODUCTION_PROJECT_CUSTOMER');
        $this->addSql('ALTER TABLE production_customer_projects ALTER customer_id DROP NOT NULL');
        $this->addSql('ALTER TABLE production_customer_projects ADD CONSTRAINT FK_4C1E9F4C9395C3F3 FOREIGN KEY (customer_id) REFERENCES production_customers (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE production_project_positions (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, customer_project_id INT NOT NULL, template_project_id INT NOT NULL, parent_id INT DEFAULT NULL, name VARCHAR(255) NOT NULL, position INT DEFAULT 0 NOT NULL, quantity INT DEFAULT 1 NOT NULL, status VARCHAR(32) NOT NULL, last_modified TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_FBEBCF6CEBA41B1E ON production_project_positions (customer_project_id)');
        $this->addSql('CREATE INDEX IDX_FBEBCF6C34A65A53 ON production_project_positions (template_project_id)');
        $this->addSql('CREATE INDEX IDX_FBEBCF6C727ACA70 ON production_project_positions (parent_id)');
        $this->addSql('ALTER TABLE production_project_positions ADD CONSTRAINT FK_FBEBCF6CEBA41B1E FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE production_project_positions ADD CONSTRAINT FK_FBEBCF6C34A65A53 FOREIGN KEY (template_project_id) REFERENCES projects (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE production_project_positions ADD CONSTRAINT FK_FBEBCF6C727ACA70 FOREIGN KEY (parent_id) REFERENCES production_project_positions (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('INSERT INTO production_project_positions (id, customer_project_id, template_project_id, parent_id, name, position, quantity, status, last_modified, datetime_added) SELECT bi.id, bi.customer_project_id, bi.template_project_id, NULL, p.name, bi.id, 1, bi.status, bi.last_modified, bi.datetime_added FROM production_build_instances bi INNER JOIN projects p ON p.id = bi.template_project_id WHERE bi.customer_project_id IS NOT NULL');
        $this->addSql("SELECT setval(pg_get_serial_sequence('production_project_positions', 'id'), COALESCE(MAX(id), 1), COUNT(*) > 0) FROM production_project_positions");

        $this->addSql('ALTER TABLE production_build_instances ADD project_position_id INT DEFAULT NULL');
        $this->addSql('UPDATE production_build_instances SET project_position_id = id WHERE customer_project_id IS NOT NULL');
        $this->addSql('ALTER TABLE production_build_instances ADD CONSTRAINT FK_4C67941F154BD9BD FOREIGN KEY (project_position_id) REFERENCES production_project_positions (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_4C67941F154BD9BD ON production_build_instances (project_position_id)');

        $this->addSql('CREATE TABLE production_history (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, customer_project_id INT NOT NULL, build_instance_id INT DEFAULT NULL, actor_id INT DEFAULT NULL, event_type VARCHAR(64) NOT NULL, description VARCHAR(255) NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_32AFFD96EBA41B1E ON production_history (customer_project_id)');
        $this->addSql('CREATE INDEX IDX_32AFFD96881EA2B7 ON production_history (build_instance_id)');
        $this->addSql('CREATE INDEX IDX_32AFFD9610DAF24A ON production_history (actor_id)');
        $this->addSql('ALTER TABLE production_history ADD CONSTRAINT FK_32AFFD96EBA41B1E FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE production_history ADD CONSTRAINT FK_32AFFD96881EA2B7 FOREIGN KEY (build_instance_id) REFERENCES production_build_instances (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE production_history ADD CONSTRAINT FK_32AFFD9610DAF24A FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql("INSERT INTO production_history (customer_project_id, build_instance_id, actor_id, event_type, description, occurred_at) SELECT id, NULL, NULL, 'project_created', '', datetime_added FROM production_customer_projects");
    }

    public function postgreSQLDown(Schema $schema): void
    {
        $this->addSql('DROP TABLE production_history');
        $this->addSql('ALTER TABLE production_build_instances DROP CONSTRAINT FK_4C67941F154BD9BD');
        $this->addSql('DROP INDEX IDX_4C67941F154BD9BD');
        $this->addSql('ALTER TABLE production_build_instances DROP project_position_id');
        $this->addSql('DROP TABLE production_project_positions');
        $this->addSql('ALTER TABLE production_customer_projects DROP CONSTRAINT FK_4C1E9F4C9395C3F3');
        $this->addSql('ALTER TABLE production_customer_projects ALTER customer_id SET NOT NULL');
        $this->addSql('ALTER TABLE production_customer_projects ADD CONSTRAINT FK_PRODUCTION_PROJECT_CUSTOMER FOREIGN KEY (customer_id) REFERENCES production_customers (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
