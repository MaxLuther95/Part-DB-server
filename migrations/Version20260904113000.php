<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260904113000 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Add constrained text styling options to datasheet template blocks.';
    }

    public function mySQLUp(Schema $schema): void
    {
        $this->addSql("ALTER TABLE production_datasheet_template_blocks ADD text_size VARCHAR(16) DEFAULT 'normal' NOT NULL, ADD text_bold TINYINT(1) DEFAULT 0 NOT NULL, ADD text_italic TINYINT(1) DEFAULT 0 NOT NULL, ADD text_underlined TINYINT(1) DEFAULT 0 NOT NULL");
    }

    public function mySQLDown(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_datasheet_template_blocks DROP text_size, DROP text_bold, DROP text_italic, DROP text_underlined');
    }

    public function sqLiteUp(Schema $schema): void
    {
        $this->addSql("ALTER TABLE production_datasheet_template_blocks ADD text_size VARCHAR(16) DEFAULT 'normal' NOT NULL");
        $this->addSql('ALTER TABLE production_datasheet_template_blocks ADD text_bold BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE production_datasheet_template_blocks ADD text_italic BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE production_datasheet_template_blocks ADD text_underlined BOOLEAN DEFAULT 0 NOT NULL');
    }

    public function sqLiteDown(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_datasheet_template_blocks DROP COLUMN text_size');
        $this->addSql('ALTER TABLE production_datasheet_template_blocks DROP COLUMN text_bold');
        $this->addSql('ALTER TABLE production_datasheet_template_blocks DROP COLUMN text_italic');
        $this->addSql('ALTER TABLE production_datasheet_template_blocks DROP COLUMN text_underlined');
    }

    public function postgreSQLUp(Schema $schema): void
    {
        $this->addSql("ALTER TABLE production_datasheet_template_blocks ADD text_size VARCHAR(16) DEFAULT 'normal' NOT NULL, ADD text_bold BOOLEAN DEFAULT FALSE NOT NULL, ADD text_italic BOOLEAN DEFAULT FALSE NOT NULL, ADD text_underlined BOOLEAN DEFAULT FALSE NOT NULL");
    }

    public function postgreSQLDown(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_datasheet_template_blocks DROP COLUMN text_size, DROP COLUMN text_bold, DROP COLUMN text_italic, DROP COLUMN text_underlined');
    }
}
