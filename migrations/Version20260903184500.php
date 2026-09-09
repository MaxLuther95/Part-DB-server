<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260903184500 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Replace repeatable protocol rows with a single section record and add responsive field layout metadata.';
    }

    public function mySQLUp(Schema $schema): void
    {
        $this->assertNoRepeatedSectionData();
        $this->addSql('ALTER TABLE production_protocol_template_fields ADD layout_columns SMALLINT DEFAULT 6 NOT NULL, ADD start_new_row TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE production_protocol_template_sections DROP repeatable, DROP min_rows, DROP max_rows');
        $this->addSql('ALTER TABLE production_protocol_run_rows DROP INDEX UNIQ_PROD_PROTOCOL_RUN_ROW, DROP sequence_number, ADD UNIQUE INDEX UNIQ_PROD_PROTOCOL_RUN_SECTION (run_id, section_id)');
    }

    public function mySQLDown(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_protocol_run_rows DROP INDEX UNIQ_PROD_PROTOCOL_RUN_SECTION, ADD sequence_number INT DEFAULT 1 NOT NULL, ADD UNIQUE INDEX UNIQ_PROD_PROTOCOL_RUN_ROW (run_id, section_id, sequence_number)');
        $this->addSql('ALTER TABLE production_protocol_template_sections ADD repeatable TINYINT(1) DEFAULT 0 NOT NULL, ADD min_rows INT DEFAULT 1 NOT NULL, ADD max_rows INT DEFAULT NULL');
        $this->addSql('ALTER TABLE production_protocol_template_fields DROP layout_columns, DROP start_new_row');
    }

    public function sqLiteUp(Schema $schema): void
    {
        $this->assertNoRepeatedSectionData();
        $this->addSql('ALTER TABLE production_protocol_template_fields ADD layout_columns SMALLINT DEFAULT 6 NOT NULL');
        $this->addSql('ALTER TABLE production_protocol_template_fields ADD start_new_row BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE production_protocol_template_sections DROP COLUMN repeatable');
        $this->addSql('ALTER TABLE production_protocol_template_sections DROP COLUMN min_rows');
        $this->addSql('ALTER TABLE production_protocol_template_sections DROP COLUMN max_rows');
        $this->addSql('DROP INDEX UNIQ_PROD_PROTOCOL_RUN_ROW');
        $this->addSql('ALTER TABLE production_protocol_run_rows DROP COLUMN sequence_number');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PROD_PROTOCOL_RUN_SECTION ON production_protocol_run_rows (run_id, section_id)');
    }

    public function sqLiteDown(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_PROD_PROTOCOL_RUN_SECTION');
        $this->addSql('ALTER TABLE production_protocol_run_rows ADD sequence_number INTEGER DEFAULT 1 NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PROD_PROTOCOL_RUN_ROW ON production_protocol_run_rows (run_id, section_id, sequence_number)');
        $this->addSql('ALTER TABLE production_protocol_template_sections ADD repeatable BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE production_protocol_template_sections ADD min_rows INTEGER DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE production_protocol_template_sections ADD max_rows INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE production_protocol_template_fields DROP COLUMN layout_columns');
        $this->addSql('ALTER TABLE production_protocol_template_fields DROP COLUMN start_new_row');
    }

    public function postgreSQLUp(Schema $schema): void
    {
        $this->assertNoRepeatedSectionData();
        $this->addSql('ALTER TABLE production_protocol_template_fields ADD layout_columns SMALLINT DEFAULT 6 NOT NULL');
        $this->addSql('ALTER TABLE production_protocol_template_fields ADD start_new_row BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE production_protocol_template_sections DROP COLUMN repeatable, DROP COLUMN min_rows, DROP COLUMN max_rows');
        $this->addSql('DROP INDEX UNIQ_PROD_PROTOCOL_RUN_ROW');
        $this->addSql('ALTER TABLE production_protocol_run_rows DROP COLUMN sequence_number');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PROD_PROTOCOL_RUN_SECTION ON production_protocol_run_rows (run_id, section_id)');
    }

    public function postgreSQLDown(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_PROD_PROTOCOL_RUN_SECTION');
        $this->addSql('ALTER TABLE production_protocol_run_rows ADD sequence_number INT DEFAULT 1 NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PROD_PROTOCOL_RUN_ROW ON production_protocol_run_rows (run_id, section_id, sequence_number)');
        $this->addSql('ALTER TABLE production_protocol_template_sections ADD repeatable BOOLEAN DEFAULT FALSE NOT NULL, ADD min_rows INT DEFAULT 1 NOT NULL, ADD max_rows INT DEFAULT NULL');
        $this->addSql('ALTER TABLE production_protocol_template_fields DROP COLUMN layout_columns, DROP COLUMN start_new_row');
    }

    private function assertNoRepeatedSectionData(): void
    {
        $repeatedSections = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM (SELECT run_id, section_id FROM production_protocol_run_rows GROUP BY run_id, section_id HAVING COUNT(*) > 1) repeated_sections'
        );
        $this->abortIf(
            0 !== $repeatedSections,
            'Protocol layout migration found repeated section data. Back up the database and obtain explicit approval before deleting any rows.'
        );
    }
}
