<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/** Brings positions created before direct nested-system support in line with their migrated slot choices. */
final class Version20260821012200 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Normalize existing nested project positions and their build instances to direct system-template assignments.';
    }

    public function mySQLUp(Schema $schema): void
    {
        $this->addSql('UPDATE production_project_positions pp INNER JOIN production_system_template_slot_templates ast ON ast.slot_id = pp.source_slot_id INNER JOIN production_system_templates st ON st.id = ast.allowed_template_id AND st.base_project_id = pp.template_project_id SET pp.system_template_id = st.id, pp.template_project_id = NULL WHERE pp.system_template_id IS NULL');
        $this->addSql('UPDATE production_build_instances bi INNER JOIN production_project_positions pp ON pp.id = bi.project_position_id SET bi.system_template_id = pp.system_template_id, bi.template_project_id = NULL WHERE pp.system_template_id IS NOT NULL');
    }

    public function mySQLDown(Schema $schema): void
    {
        $this->abortDowngrade();
    }

    public function sqLiteUp(Schema $schema): void
    {
        $this->addSql('UPDATE production_project_positions SET system_template_id = (SELECT ast.allowed_template_id FROM production_system_template_slot_templates ast INNER JOIN production_system_templates st ON st.id = ast.allowed_template_id WHERE ast.slot_id = production_project_positions.source_slot_id AND st.base_project_id = production_project_positions.template_project_id LIMIT 1), template_project_id = NULL WHERE system_template_id IS NULL AND EXISTS (SELECT 1 FROM production_system_template_slot_templates ast INNER JOIN production_system_templates st ON st.id = ast.allowed_template_id WHERE ast.slot_id = production_project_positions.source_slot_id AND st.base_project_id = production_project_positions.template_project_id)');
        $this->addSql('UPDATE production_build_instances SET system_template_id = (SELECT pp.system_template_id FROM production_project_positions pp WHERE pp.id = production_build_instances.project_position_id), template_project_id = NULL WHERE EXISTS (SELECT 1 FROM production_project_positions pp WHERE pp.id = production_build_instances.project_position_id AND pp.system_template_id IS NOT NULL)');
    }

    public function sqLiteDown(Schema $schema): void
    {
        $this->abortDowngrade();
    }

    public function postgreSQLUp(Schema $schema): void
    {
        $this->addSql('UPDATE production_project_positions pp SET system_template_id = ast.allowed_template_id, template_project_id = NULL FROM production_system_template_slot_templates ast INNER JOIN production_system_templates st ON st.id = ast.allowed_template_id WHERE ast.slot_id = pp.source_slot_id AND st.base_project_id = pp.template_project_id AND pp.system_template_id IS NULL');
        $this->addSql('UPDATE production_build_instances bi SET system_template_id = pp.system_template_id, template_project_id = NULL FROM production_project_positions pp WHERE pp.id = bi.project_position_id AND pp.system_template_id IS NOT NULL');
    }

    public function postgreSQLDown(Schema $schema): void
    {
        $this->abortDowngrade();
    }

    private function abortDowngrade(): never
    {
        throw new IrreversibleMigration('Nested project positions cannot be converted back without losing their direct system-template assignments. Restore the database backup to downgrade safely.');
    }
}
