<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20260826190000 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Separate organizational production projects from operational customer orders.';
    }

    public function mySQLUp(Schema $schema): void
    {
        $this->addSql('CREATE TABLE production_projects (id INT AUTO_INCREMENT NOT NULL, project_number VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT NOT NULL, status VARCHAR(32) NOT NULL, notes LONGTEXT DEFAULT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, UNIQUE INDEX UNIQ_A4E408408134F41E (project_number), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql("INSERT INTO production_projects (project_number, name, description, status, notes, last_modified, datetime_added) SELECT project_number, name, description, 'active', notes, last_modified, datetime_added FROM production_customer_projects");
        $this->addSql('ALTER TABLE production_customer_projects ADD production_project_id INT DEFAULT NULL');
        $this->addSql('UPDATE production_customer_projects orders SET production_project_id = (SELECT projects.id FROM production_projects projects WHERE projects.project_number = orders.project_number)');
        $this->addSql('ALTER TABLE production_customer_projects DROP FOREIGN KEY FK_4C1E9F4C9395C3F3');
        $this->addSql('ALTER TABLE production_customer_projects MODIFY customer_id INT NOT NULL, MODIFY production_project_id INT NOT NULL');
        $this->addSql('ALTER TABLE production_customer_projects ADD CONSTRAINT FK_4C1E9F4C9395C3F3 FOREIGN KEY (customer_id) REFERENCES production_customers (id)');
        $this->addSql('ALTER TABLE production_customer_projects ADD CONSTRAINT FK_4C1E9F4C70CCBB07 FOREIGN KEY (production_project_id) REFERENCES production_projects (id)');
        $this->finishMigration();
    }

    public function sqLiteUp(Schema $schema): void
    {
        $this->addSql("CREATE TABLE production_projects (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, project_number VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, description CLOB NOT NULL, status VARCHAR(32) NOT NULL, notes CLOB DEFAULT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL)");
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A4E408408134F41E ON production_projects (project_number)');
        $this->addSql("INSERT INTO production_projects (project_number, name, description, status, notes, last_modified, datetime_added) SELECT project_number, name, description, 'active', notes, last_modified, datetime_added FROM production_customer_projects");
        $this->addSql('CREATE TEMPORARY TABLE __temp__production_customer_projects AS SELECT id, customer_id, project_number, name, status, description, last_modified, datetime_added, notes, production_site_id FROM production_customer_projects');
        $this->addSql('DROP TABLE production_customer_projects');
        $this->addSql('CREATE TABLE production_customer_projects (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, customer_id INTEGER NOT NULL, project_number VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, status VARCHAR(32) NOT NULL, description CLOB NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, notes CLOB DEFAULT NULL, production_site_id INTEGER DEFAULT NULL, production_project_id INTEGER NOT NULL, FOREIGN KEY (production_site_id) REFERENCES storelocations (id) ON UPDATE NO ACTION ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, FOREIGN KEY (production_project_id) REFERENCES production_projects (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_4C1E9F4C9395C3F3 FOREIGN KEY (customer_id) REFERENCES production_customers (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO production_customer_projects (id, customer_id, project_number, name, status, description, last_modified, datetime_added, notes, production_site_id, production_project_id) SELECT orders.id, orders.customer_id, orders.project_number, orders.name, orders.status, orders.description, orders.last_modified, orders.datetime_added, orders.notes, orders.production_site_id, projects.id FROM __temp__production_customer_projects orders INNER JOIN production_projects projects ON projects.project_number = orders.project_number');
        $this->addSql('DROP TABLE __temp__production_customer_projects');
        $this->addSql('CREATE INDEX IDX_4C1E9F4C70CCBB07 ON production_customer_projects (production_project_id)');
        $this->addSql('CREATE INDEX IDX_4C1E9F4C9395C3F3 ON production_customer_projects (customer_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4C1E9F4C8134F41E ON production_customer_projects (project_number)');
        $this->addSql('CREATE INDEX IDX_4C1E9F4CE3A89B9B ON production_customer_projects (production_site_id)');
    }

    public function postgreSQLUp(Schema $schema): void
    {
        $this->addSql('CREATE TABLE production_projects (id SERIAL NOT NULL, project_number VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, description TEXT NOT NULL, status VARCHAR(32) NOT NULL, notes TEXT DEFAULT NULL, last_modified TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A4E408408134F41E ON production_projects (project_number)');
        $this->addSql("INSERT INTO production_projects (project_number, name, description, status, notes, last_modified, datetime_added) SELECT project_number, name, description, 'active', notes, last_modified, datetime_added FROM production_customer_projects");
        $this->addSql('ALTER TABLE production_customer_projects ADD production_project_id INT DEFAULT NULL');
        $this->addSql('UPDATE production_customer_projects orders SET production_project_id = projects.id FROM production_projects projects WHERE projects.project_number = orders.project_number');
        $this->addSql('ALTER TABLE production_customer_projects DROP CONSTRAINT FK_4C1E9F4C9395C3F3');
        $this->addSql('ALTER TABLE production_customer_projects ALTER customer_id SET NOT NULL, ALTER production_project_id SET NOT NULL');
        $this->addSql('ALTER TABLE production_customer_projects ADD CONSTRAINT FK_4C1E9F4C9395C3F3 FOREIGN KEY (customer_id) REFERENCES production_customers (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE production_customer_projects ADD CONSTRAINT FK_4C1E9F4C70CCBB07 FOREIGN KEY (production_project_id) REFERENCES production_projects (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->finishMigration();
    }

    private function finishMigration(): void
    {
        $this->addSql('CREATE INDEX IDX_4C1E9F4C70CCBB07 ON production_customer_projects (production_project_id)');
    }

    public function mySQLDown(Schema $schema): void { $this->abortDowngrade(); }
    public function sqLiteDown(Schema $schema): void { $this->abortDowngrade(); }
    public function postgreSQLDown(Schema $schema): void { $this->abortDowngrade(); }

    private function abortDowngrade(): never
    {
        throw new IrreversibleMigration('Restore the database backup to remove the project/order separation safely.');
    }
}
