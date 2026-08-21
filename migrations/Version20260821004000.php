<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260821004000 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Normalize generated index names for system template choice tables.';
    }

    public function mySQLUp(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_system_template_slot_projects DROP INDEX IDX_PROD_SLOT_PROJECT_SLOT, DROP INDEX IDX_PROD_SLOT_PROJECT_PROJECT, ADD INDEX IDX_A556FD2559E5119C (slot_id), ADD INDEX IDX_A556FD25166D1F9C (project_id)');
        $this->addSql('ALTER TABLE production_system_template_slot_parts DROP INDEX IDX_PROD_SLOT_PART_SLOT, DROP INDEX IDX_PROD_SLOT_PART_PART, ADD INDEX IDX_DA0A319E59E5119C (slot_id), ADD INDEX IDX_DA0A319E4CE34BEC (part_id)');
    }

    public function mySQLDown(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_system_template_slot_projects DROP INDEX IDX_A556FD2559E5119C, DROP INDEX IDX_A556FD25166D1F9C, ADD INDEX IDX_PROD_SLOT_PROJECT_SLOT (slot_id), ADD INDEX IDX_PROD_SLOT_PROJECT_PROJECT (project_id)');
        $this->addSql('ALTER TABLE production_system_template_slot_parts DROP INDEX IDX_DA0A319E59E5119C, DROP INDEX IDX_DA0A319E4CE34BEC, ADD INDEX IDX_PROD_SLOT_PART_SLOT (slot_id), ADD INDEX IDX_PROD_SLOT_PART_PART (part_id)');
    }

    public function sqLiteUp(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_PROD_SLOT_PROJECT_SLOT');
        $this->addSql('DROP INDEX IDX_PROD_SLOT_PROJECT_PROJECT');
        $this->addSql('CREATE INDEX IDX_A556FD2559E5119C ON production_system_template_slot_projects (slot_id)');
        $this->addSql('CREATE INDEX IDX_A556FD25166D1F9C ON production_system_template_slot_projects (project_id)');
        $this->addSql('DROP INDEX IDX_PROD_SLOT_PART_SLOT');
        $this->addSql('DROP INDEX IDX_PROD_SLOT_PART_PART');
        $this->addSql('CREATE INDEX IDX_DA0A319E59E5119C ON production_system_template_slot_parts (slot_id)');
        $this->addSql('CREATE INDEX IDX_DA0A319E4CE34BEC ON production_system_template_slot_parts (part_id)');
    }

    public function sqLiteDown(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_A556FD2559E5119C');
        $this->addSql('DROP INDEX IDX_A556FD25166D1F9C');
        $this->addSql('CREATE INDEX IDX_PROD_SLOT_PROJECT_SLOT ON production_system_template_slot_projects (slot_id)');
        $this->addSql('CREATE INDEX IDX_PROD_SLOT_PROJECT_PROJECT ON production_system_template_slot_projects (project_id)');
        $this->addSql('DROP INDEX IDX_DA0A319E59E5119C');
        $this->addSql('DROP INDEX IDX_DA0A319E4CE34BEC');
        $this->addSql('CREATE INDEX IDX_PROD_SLOT_PART_SLOT ON production_system_template_slot_parts (slot_id)');
        $this->addSql('CREATE INDEX IDX_PROD_SLOT_PART_PART ON production_system_template_slot_parts (part_id)');
    }

    public function postgreSQLUp(Schema $schema): void
    {
        $this->addSql('ALTER INDEX IDX_PROD_SLOT_PROJECT_SLOT RENAME TO IDX_A556FD2559E5119C');
        $this->addSql('ALTER INDEX IDX_PROD_SLOT_PROJECT_PROJECT RENAME TO IDX_A556FD25166D1F9C');
        $this->addSql('ALTER INDEX IDX_PROD_SLOT_PART_SLOT RENAME TO IDX_DA0A319E59E5119C');
        $this->addSql('ALTER INDEX IDX_PROD_SLOT_PART_PART RENAME TO IDX_DA0A319E4CE34BEC');
    }

    public function postgreSQLDown(Schema $schema): void
    {
        $this->addSql('ALTER INDEX IDX_A556FD2559E5119C RENAME TO IDX_PROD_SLOT_PROJECT_SLOT');
        $this->addSql('ALTER INDEX IDX_A556FD25166D1F9C RENAME TO IDX_PROD_SLOT_PROJECT_PROJECT');
        $this->addSql('ALTER INDEX IDX_DA0A319E59E5119C RENAME TO IDX_PROD_SLOT_PART_SLOT');
        $this->addSql('ALTER INDEX IDX_DA0A319E4CE34BEC RENAME TO IDX_PROD_SLOT_PART_PART');
    }
}
