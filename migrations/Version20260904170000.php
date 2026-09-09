<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260904170000 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Add safe font-family and alignment options to datasheet content blocks.';
    }

    public function mySQLUp(Schema $schema): void
    {
        $this->addSql("ALTER TABLE production_datasheet_template_blocks ADD font_family VARCHAR(16) DEFAULT 'sans_serif' NOT NULL, ADD text_alignment VARCHAR(8) DEFAULT 'left' NOT NULL");
    }

    public function mySQLDown(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_datasheet_template_blocks DROP font_family, DROP text_alignment');
    }

    public function sqLiteUp(Schema $schema): void
    {
        $this->addSql("ALTER TABLE production_datasheet_template_blocks ADD font_family VARCHAR(16) DEFAULT 'sans_serif' NOT NULL");
        $this->addSql("ALTER TABLE production_datasheet_template_blocks ADD text_alignment VARCHAR(8) DEFAULT 'left' NOT NULL");
    }

    public function sqLiteDown(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_datasheet_template_blocks DROP COLUMN font_family');
        $this->addSql('ALTER TABLE production_datasheet_template_blocks DROP COLUMN text_alignment');
    }

    public function postgreSQLUp(Schema $schema): void
    {
        $this->addSql("ALTER TABLE production_datasheet_template_blocks ADD font_family VARCHAR(16) DEFAULT 'sans_serif' NOT NULL, ADD text_alignment VARCHAR(8) DEFAULT 'left' NOT NULL");
    }

    public function postgreSQLDown(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_datasheet_template_blocks DROP COLUMN font_family, DROP COLUMN text_alignment');
    }
}
