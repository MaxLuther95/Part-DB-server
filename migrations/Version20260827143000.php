<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20260827143000 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Add order dates, protected attachments and reusable PDF import mappings.';
    }

    public function mySQLUp(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_customer_projects ADD order_date DATE DEFAULT NULL');
        $this->addSql('CREATE TABLE production_order_attachments (id INT AUTO_INCREMENT NOT NULL, customer_project_id INT NOT NULL, original_filename VARCHAR(255) NOT NULL, stored_filename VARCHAR(255) NOT NULL, mime_type VARCHAR(255) NOT NULL, file_size INT NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, INDEX IDX_603EEA97EBA41B1E (customer_project_id), UNIQUE INDEX UNIQ_603EEA97DF8EB9B7 (stored_filename), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE production_order_import_mappings (id INT AUTO_INCREMENT NOT NULL, system_template_id INT DEFAULT NULL, template_project_id INT DEFAULT NULL, part_id INT DEFAULT NULL, source_description VARCHAR(255) NOT NULL, normalized_description VARCHAR(255) NOT NULL, active TINYINT(1) DEFAULT 1 NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, INDEX IDX_CB52DD975A6A54A0 (system_template_id), INDEX IDX_CB52DD9734A65A53 (template_project_id), INDEX IDX_CB52DD974CE34BEC (part_id), UNIQUE INDEX UNIQ_CB52DD97B268BCE2 (normalized_description), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE production_order_import_lines (id INT AUTO_INCREMENT NOT NULL, customer_project_id INT NOT NULL, mapping_id INT DEFAULT NULL, line_number INT NOT NULL, description VARCHAR(255) NOT NULL, quantity INT NOT NULL, unit VARCHAR(32) NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, INDEX IDX_A3A8A84EEBA41B1E (customer_project_id), INDEX IDX_A3A8A84EFABB77CC (mapping_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->finishMigration();
    }

    public function sqLiteUp(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_customer_projects ADD COLUMN order_date DATE DEFAULT NULL');
        $this->addSql('CREATE TABLE production_order_attachments (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, customer_project_id INTEGER NOT NULL, original_filename VARCHAR(255) NOT NULL, stored_filename VARCHAR(255) NOT NULL, mime_type VARCHAR(255) NOT NULL, file_size INTEGER NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_603EEA97EBA41B1E ON production_order_attachments (customer_project_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_603EEA97DF8EB9B7 ON production_order_attachments (stored_filename)');
        $this->addSql('CREATE TABLE production_order_import_mappings (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, system_template_id INTEGER DEFAULT NULL, template_project_id INTEGER DEFAULT NULL, part_id INTEGER DEFAULT NULL, source_description VARCHAR(255) NOT NULL, normalized_description VARCHAR(255) NOT NULL, active BOOLEAN DEFAULT 1 NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, FOREIGN KEY (system_template_id) REFERENCES production_system_templates (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, FOREIGN KEY (template_project_id) REFERENCES projects (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, FOREIGN KEY (part_id) REFERENCES parts (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_CB52DD975A6A54A0 ON production_order_import_mappings (system_template_id)');
        $this->addSql('CREATE INDEX IDX_CB52DD9734A65A53 ON production_order_import_mappings (template_project_id)');
        $this->addSql('CREATE INDEX IDX_CB52DD974CE34BEC ON production_order_import_mappings (part_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CB52DD97B268BCE2 ON production_order_import_mappings (normalized_description)');
        $this->addSql('CREATE TABLE production_order_import_lines (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, customer_project_id INTEGER NOT NULL, mapping_id INTEGER DEFAULT NULL, line_number INTEGER NOT NULL, description VARCHAR(255) NOT NULL, quantity INTEGER NOT NULL, unit VARCHAR(32) NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, FOREIGN KEY (mapping_id) REFERENCES production_order_import_mappings (id) ON UPDATE NO ACTION ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_A3A8A84EEBA41B1E ON production_order_import_lines (customer_project_id)');
        $this->addSql('CREATE INDEX IDX_A3A8A84EFABB77CC ON production_order_import_lines (mapping_id)');
    }

    public function postgreSQLUp(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_customer_projects ADD order_date DATE DEFAULT NULL');
        $this->addSql('CREATE TABLE production_order_attachments (id SERIAL NOT NULL, customer_project_id INT NOT NULL, original_filename VARCHAR(255) NOT NULL, stored_filename VARCHAR(255) NOT NULL, mime_type VARCHAR(255) NOT NULL, file_size INT NOT NULL, last_modified TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_603EEA97EBA41B1E ON production_order_attachments (customer_project_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_603EEA97DF8EB9B7 ON production_order_attachments (stored_filename)');
        $this->addSql('CREATE TABLE production_order_import_mappings (id SERIAL NOT NULL, system_template_id INT DEFAULT NULL, template_project_id INT DEFAULT NULL, part_id INT DEFAULT NULL, source_description VARCHAR(255) NOT NULL, normalized_description VARCHAR(255) NOT NULL, active BOOLEAN DEFAULT TRUE NOT NULL, last_modified TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_CB52DD975A6A54A0 ON production_order_import_mappings (system_template_id)');
        $this->addSql('CREATE INDEX IDX_CB52DD9734A65A53 ON production_order_import_mappings (template_project_id)');
        $this->addSql('CREATE INDEX IDX_CB52DD974CE34BEC ON production_order_import_mappings (part_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CB52DD97B268BCE2 ON production_order_import_mappings (normalized_description)');
        $this->addSql('CREATE TABLE production_order_import_lines (id SERIAL NOT NULL, customer_project_id INT NOT NULL, mapping_id INT DEFAULT NULL, line_number INT NOT NULL, description VARCHAR(255) NOT NULL, quantity INT NOT NULL, unit VARCHAR(32) NOT NULL, last_modified TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_A3A8A84EEBA41B1E ON production_order_import_lines (customer_project_id)');
        $this->addSql('CREATE INDEX IDX_A3A8A84EFABB77CC ON production_order_import_lines (mapping_id)');
        $this->finishMigration();
    }

    private function finishMigration(): void
    {
        $this->addSql('ALTER TABLE production_order_attachments ADD CONSTRAINT FK_ORDER_ATTACHMENT_ORDER FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE production_order_import_mappings ADD CONSTRAINT FK_ORDER_MAPPING_SYSTEM FOREIGN KEY (system_template_id) REFERENCES production_system_templates (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE production_order_import_mappings ADD CONSTRAINT FK_ORDER_MAPPING_PROJECT FOREIGN KEY (template_project_id) REFERENCES projects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE production_order_import_mappings ADD CONSTRAINT FK_ORDER_MAPPING_PART FOREIGN KEY (part_id) REFERENCES parts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE production_order_import_lines ADD CONSTRAINT FK_ORDER_IMPORT_LINE_ORDER FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE production_order_import_lines ADD CONSTRAINT FK_ORDER_IMPORT_LINE_MAPPING FOREIGN KEY (mapping_id) REFERENCES production_order_import_mappings (id) ON DELETE SET NULL');
    }

    public function mySQLDown(Schema $schema): void { $this->abortDowngrade(); }
    public function sqLiteDown(Schema $schema): void { $this->abortDowngrade(); }
    public function postgreSQLDown(Schema $schema): void { $this->abortDowngrade(); }

    private function abortDowngrade(): never
    {
        throw new IrreversibleMigration('Restore the database backup to remove order imports safely.');
    }
}
