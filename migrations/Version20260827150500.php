<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260827150500 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Align order import indexes and SQLite foreign-key metadata with Doctrine.';
    }

    public function mySQLUp(Schema $schema): void
    {
        $renames = $this->indexRenames();
        foreach ($renames as [$table, $old, $new]) {
            if ($schema->hasTable($table) && $schema->getTable($table)->hasIndex($old)) {
                $this->addSql(sprintf('ALTER TABLE %s RENAME INDEX %s TO %s', $table, $old, $new));
            }
        }
    }

    public function sqLiteUp(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__production_order_attachments AS SELECT * FROM production_order_attachments');
        $this->addSql('CREATE TEMPORARY TABLE __temp__production_order_import_lines AS SELECT * FROM production_order_import_lines');
        $this->addSql('CREATE TEMPORARY TABLE __temp__production_order_import_mappings AS SELECT * FROM production_order_import_mappings');
        $this->addSql('DROP TABLE production_order_import_lines');
        $this->addSql('DROP TABLE production_order_attachments');
        $this->addSql('DROP TABLE production_order_import_mappings');

        $this->addSql('CREATE TABLE production_order_import_mappings (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, system_template_id INTEGER DEFAULT NULL, template_project_id INTEGER DEFAULT NULL, part_id INTEGER DEFAULT NULL, source_description VARCHAR(255) NOT NULL, normalized_description VARCHAR(255) NOT NULL, active BOOLEAN DEFAULT 1 NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, FOREIGN KEY (system_template_id) REFERENCES production_system_templates (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, FOREIGN KEY (template_project_id) REFERENCES projects (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, FOREIGN KEY (part_id) REFERENCES parts (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO production_order_import_mappings SELECT * FROM __temp__production_order_import_mappings');
        $this->addSql('DROP TABLE __temp__production_order_import_mappings');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CB52DD97B268BCE2 ON production_order_import_mappings (normalized_description)');
        $this->addSql('CREATE INDEX IDX_CB52DD975A6A54A0 ON production_order_import_mappings (system_template_id)');
        $this->addSql('CREATE INDEX IDX_CB52DD9734A65A53 ON production_order_import_mappings (template_project_id)');
        $this->addSql('CREATE INDEX IDX_CB52DD974CE34BEC ON production_order_import_mappings (part_id)');

        $this->addSql('CREATE TABLE production_order_import_lines (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, customer_project_id INTEGER NOT NULL, mapping_id INTEGER DEFAULT NULL, line_number INTEGER NOT NULL, description VARCHAR(255) NOT NULL, quantity INTEGER NOT NULL, unit VARCHAR(32) NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, FOREIGN KEY (mapping_id) REFERENCES production_order_import_mappings (id) ON UPDATE NO ACTION ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO production_order_import_lines SELECT * FROM __temp__production_order_import_lines');
        $this->addSql('DROP TABLE __temp__production_order_import_lines');
        $this->addSql('CREATE INDEX IDX_A3A8A84EEBA41B1E ON production_order_import_lines (customer_project_id)');
        $this->addSql('CREATE INDEX IDX_A3A8A84EFABB77CC ON production_order_import_lines (mapping_id)');

        $this->addSql('CREATE TABLE production_order_attachments (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, customer_project_id INTEGER NOT NULL, original_filename VARCHAR(255) NOT NULL, stored_filename VARCHAR(255) NOT NULL, mime_type VARCHAR(255) NOT NULL, file_size INTEGER NOT NULL, last_modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, datetime_added DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO production_order_attachments SELECT * FROM __temp__production_order_attachments');
        $this->addSql('DROP TABLE __temp__production_order_attachments');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_603EEA97DF8EB9B7 ON production_order_attachments (stored_filename)');
        $this->addSql('CREATE INDEX IDX_603EEA97EBA41B1E ON production_order_attachments (customer_project_id)');
    }

    public function postgreSQLUp(Schema $schema): void
    {
        foreach ($this->indexRenames() as [, $old, $new]) {
            foreach ($schema->getTables() as $table) {
                if ($table->hasIndex($old)) {
                    $this->addSql(sprintf('ALTER INDEX %s RENAME TO %s', $old, $new));
                    break;
                }
            }
        }
    }

    /** @return list<array{string, string, string}> */
    private function indexRenames(): array
    {
        return [
            ['production_order_attachments', 'IDX_ORDER_ATTACHMENT_ORDER', 'IDX_603EEA97EBA41B1E'],
            ['production_order_attachments', 'UNIQ_ORDER_ATTACHMENT_STORED', 'UNIQ_603EEA97DF8EB9B7'],
            ['production_order_import_mappings', 'IDX_ORDER_MAPPING_SYSTEM', 'IDX_CB52DD975A6A54A0'],
            ['production_order_import_mappings', 'IDX_ORDER_MAPPING_PROJECT', 'IDX_CB52DD9734A65A53'],
            ['production_order_import_mappings', 'IDX_ORDER_MAPPING_PART', 'IDX_CB52DD974CE34BEC'],
            ['production_order_import_mappings', 'UNIQ_ORDER_MAPPING_DESCRIPTION', 'UNIQ_CB52DD97B268BCE2'],
            ['production_order_import_lines', 'IDX_ORDER_IMPORT_LINE_ORDER', 'IDX_A3A8A84EEBA41B1E'],
            ['production_order_import_lines', 'IDX_ORDER_IMPORT_LINE_MAPPING', 'IDX_A3A8A84EFABB77CC'],
        ];
    }

    public function mySQLDown(Schema $schema): void {}
    public function sqLiteDown(Schema $schema): void {}
    public function postgreSQLDown(Schema $schema): void {}
}
