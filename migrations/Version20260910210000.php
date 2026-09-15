<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Preserve entered decimal precision, remove unused legacy date fields, and enforce explicit build-type assignments.';
    }

    public function isTransactional(): bool
    {
        return ! $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf((int) $this->connection->fetchOne("SELECT COUNT(*) FROM production_protocol_template_fields WHERE type IN ('long_text', 'date', 'datetime')") > 0, 'Remove or explicitly migrate legacy template fields before continuing. No records were deleted.');
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM production_protocol_answers WHERE date_value IS NOT NULL OR datetime_value IS NOT NULL') > 0, 'Existing date answers require an explicit migration decision.');
        $this->abortIf(false !== $this->connection->fetchOne('SELECT system_template_id FROM production_protocol_template_systems GROUP BY system_template_id HAVING COUNT(*) > 1'), 'Multiple templates are assigned to the same system. Resolve these assignments explicitly.');

        $answers = $schema->getTable('production_protocol_answers');
        $answers->modifyColumn('decimal_value', ['type' => Type::getType(Types::STRING), 'length' => 128, 'precision' => 10, 'scale' => 0]);
        $answers->dropColumn('date_value');
        $answers->dropColumn('datetime_value');
        $systems = $schema->getTable('production_protocol_template_systems');
        foreach ($systems->getIndexes() as $index) {
            if (['system_template_id'] === $index->getColumns() && ! $index->isUnique()) {
                $systems->dropIndex($index->getName());
            }
        }
        $systems->addUniqueIndex(['system_template_id']);

        $projects = $schema->createTable('production_protocol_template_projects');
        $projects->addColumn('protocol_template_id', Types::INTEGER);
        $projects->addColumn('project_id', Types::INTEGER);
        $projects->setPrimaryKey(['protocol_template_id', 'project_id']);
        $projects->addUniqueIndex(['project_id']);
        $projects->addForeignKeyConstraint('production_protocol_templates', ['protocol_template_id'], ['id'], ['onDelete' => 'CASCADE']);
        $projects->addForeignKeyConstraint('projects', ['project_id'], ['id'], ['onDelete' => 'CASCADE']);
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $projects->addOption('charset', 'utf8mb4');
            $projects->addOption('collation', 'utf8mb4_unicode_ci');
            $projects->addOption('engine', 'InnoDB');
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Restoring DECIMAL would lose precision. Restore a verified backup instead.');
    }
}
