<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260911100000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add shared serial-number ranges and explicit build-type assignments.'; }
    public function isTransactional(): bool { return !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform; }
    public function up(Schema $schema): void
    {
        $range = $schema->createTable('production_serial_number_ranges');
        $range->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $range->setPrimaryKey(['id']);
        $range->addColumn('name', Types::STRING, ['length' => 128]);
        $range->addColumn('prefix', Types::STRING, ['length' => 4]);
        $range->addUniqueIndex(['prefix']);
        $range->addColumn('next_number', Types::INTEGER);
        $range->addColumn('minimum_digits', Types::INTEGER);
        $range->addColumn('version', Types::INTEGER, ['default' => 1]);
        $range->addColumn('datetime_added', Types::DATETIME_MUTABLE, ['default' => 'CURRENT_TIMESTAMP']);
        $range->addColumn('last_modified', Types::DATETIME_MUTABLE, ['default' => 'CURRENT_TIMESTAMP']);
        foreach (['systems' => ['system_template_id', 'production_system_templates'], 'projects' => ['project_id', 'projects']] as $suffix => [$column, $target]) {
            $join = $schema->createTable('production_serial_range_'.$suffix);
            $join->addColumn('range_id', Types::INTEGER);
            $join->addColumn($column, Types::INTEGER);
            $join->setPrimaryKey(['range_id', $column]);
            $join->addUniqueIndex([$column]);
            $join->addForeignKeyConstraint($range->getName(), ['range_id'], ['id'], ['onDelete' => 'CASCADE']);
            $join->addForeignKeyConstraint($target, [$column], ['id'], ['onDelete' => 'CASCADE']);
        }
        $instances = $schema->getTable('production_build_instances');
        $instances->addColumn('serial_number_range_id', Types::INTEGER, ['notnull' => false]);
        $instances->addColumn('serial_ordinal', Types::INTEGER, ['notnull' => false]);
        $instances->addUniqueIndex(['serial_number_range_id', 'serial_ordinal'], 'UNIQ_BUILD_RANGE_ORDINAL');
        $instances->addForeignKeyConstraint($range->getName(), ['serial_number_range_id'], ['id'], ['onDelete' => 'RESTRICT']);
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Restore a verified backup to preserve number-range assignments.'); }
}
