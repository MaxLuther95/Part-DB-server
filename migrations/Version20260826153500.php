<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20260826153500 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Record the actual system-template slot of installed build instances and configured material.';
    }

    public function mySQLUp(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_build_instances ADD installed_slot_id INT DEFAULT NULL, ADD installed_slot_index INT DEFAULT NULL');
        $this->addSql('ALTER TABLE production_build_instances ADD CONSTRAINT FK_PROD_BUILD_INSTALLED_SLOT FOREIGN KEY (installed_slot_id) REFERENCES production_system_template_slots (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE production_build_material_usages ADD source_slot_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE production_build_material_usages ADD CONSTRAINT FK_PROD_USAGE_SLOT FOREIGN KEY (source_slot_id) REFERENCES production_system_template_slots (id) ON DELETE SET NULL');
        $this->finishMigration();
    }

    public function sqLiteUp(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_build_instances ADD COLUMN installed_slot_id INTEGER DEFAULT NULL REFERENCES production_system_template_slots (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE production_build_instances ADD COLUMN installed_slot_index INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE production_build_material_usages ADD COLUMN source_slot_id INTEGER DEFAULT NULL REFERENCES production_system_template_slots (id) ON DELETE SET NULL');
        $this->finishMigration();
    }

    public function postgreSQLUp(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_build_instances ADD installed_slot_id INT DEFAULT NULL, ADD installed_slot_index INT DEFAULT NULL');
        $this->addSql('ALTER TABLE production_build_instances ADD CONSTRAINT FK_PROD_BUILD_INSTALLED_SLOT FOREIGN KEY (installed_slot_id) REFERENCES production_system_template_slots (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE production_build_material_usages ADD source_slot_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE production_build_material_usages ADD CONSTRAINT FK_PROD_USAGE_SLOT FOREIGN KEY (source_slot_id) REFERENCES production_system_template_slots (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->finishMigration();
    }

    private function finishMigration(): void
    {
        $this->addSql('CREATE INDEX IDX_PROD_BUILD_INSTALLED_SLOT ON production_build_instances (installed_slot_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PROD_BUILD_PARENT_SLOT_INDEX ON production_build_instances (parent_id, installed_slot_id, installed_slot_index)');
        $this->addSql('CREATE INDEX IDX_PROD_USAGE_SLOT ON production_build_material_usages (source_slot_id)');
    }

    public function mySQLDown(Schema $schema): void { $this->abortDowngrade(); }
    public function sqLiteDown(Schema $schema): void { $this->abortDowngrade(); }
    public function postgreSQLDown(Schema $schema): void { $this->abortDowngrade(); }

    private function abortDowngrade(): never
    {
        throw new IrreversibleMigration('Restore the database backup to remove physical slot assignments safely.');
    }
}
