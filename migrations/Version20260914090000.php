<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add many-to-many datasheet-template assignments to system templates and build projects without inferring existing assignments.';
    }

    public function isTransactional(): bool
    {
        return ! $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }

    public function up(Schema $schema): void
    {
        foreach ([
            'production_datasheet_template_systems' => ['system_template_id', 'production_system_templates'],
            'production_datasheet_template_projects' => ['project_id', 'projects'],
        ] as $name => [$column, $target]) {
            $table = $schema->createTable($name);
            $table->addColumn('datasheet_template_id', Types::INTEGER);
            $table->addColumn($column, Types::INTEGER);
            $table->setPrimaryKey(['datasheet_template_id', $column]);
            $table->addIndex([$column]);
            $table->addForeignKeyConstraint('production_datasheet_templates', ['datasheet_template_id'], ['id'], ['onDelete' => 'CASCADE']);
            $table->addForeignKeyConstraint($target, [$column], ['id'], ['onDelete' => 'CASCADE']);
            if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
                $table->addOption('charset', 'utf8mb4');
                $table->addOption('collation', 'utf8mb4_unicode_ci');
                $table->addOption('engine', 'InnoDB');
            }
        }
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('production_datasheet_template_systems');
        $schema->dropTable('production_datasheet_template_projects');
    }
}
