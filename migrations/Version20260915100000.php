<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\Migration\ContainerAwareMigrationInterface;
use App\Services\Production\ManufacturingSnapshotMigrationPlan;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Psr\Container\ContainerInterface;

final class Version20260915100000 extends AbstractMigration implements ContainerAwareMigrationInterface
{
    private ?ContainerInterface $container = null;
    public function setContainer(?ContainerInterface $container = null): void { $this->container = $container; }
    public function getDescription(): string { return 'Pin order manufacturing definitions including nested choices and BOMs; keep existing data and assignments.'; }
    public function isTransactional(): bool { return !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform; }

    public function up(Schema $schema): void
    {
        if (null === $this->container) { throw new \LogicException('Snapshot migration requires the application service container.'); }
        $before = $schema;
        $schema = clone $schema;
        $snapshot = $schema->createTable('production_manufacturing_snapshots');
        $snapshot->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $snapshot->setPrimaryKey(['id']);
        $snapshot->addColumn('definitions', Types::JSON);
        $snapshot->addColumn('datetime_added', Types::DATETIME_IMMUTABLE, ['default' => new \Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp()]);
        $snapshot->addColumn('last_modified', Types::DATETIME_IMMUTABLE, ['default' => new \Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp()]);
        foreach (['projects' => 'projects', 'parts' => 'parts'] as $suffix => $target) {
            $column = 'projects' === $suffix ? 'project_id' : 'part_id';
            $table = $schema->createTable('production_snapshot_'.$suffix);
            $table->addColumn('snapshot_id', Types::INTEGER);
            $table->addColumn($column, Types::INTEGER);
            $table->setPrimaryKey(['snapshot_id', $column]);
            $table->addForeignKeyConstraint('production_manufacturing_snapshots', ['snapshot_id'], ['id'], ['onDelete' => 'CASCADE']);
            $table->addForeignKeyConstraint($target, [$column], ['id'], ['onDelete' => 'CASCADE']);
        }
        foreach (['production_project_positions', 'production_build_instances'] as $name) {
            $table = $schema->getTable($name);
            $table->addColumn('manufacturing_snapshot_id', Types::INTEGER, ['notnull' => false]);
            $table->addColumn('definition_key', Types::STRING, ['length' => 64, 'notnull' => false]);
            $table->addForeignKeyConstraint('production_manufacturing_snapshots', ['manufacturing_snapshot_id'], ['id'], ['onDelete' => 'RESTRICT']);
        }
        foreach (['production_project_positions', 'production_project_accessories'] as $name) {
            $schema->getTable($name)->addColumn('source_slot_key', Types::INTEGER, ['notnull' => false]);
        }
        $schema->getTable('production_build_instances')->addColumn('installed_slot_key', Types::INTEGER, ['notnull' => false]);
        $platform = $this->connection->getDatabasePlatform();
        $diff = $this->connection->createSchemaManager()->createComparator()->compareSchemas($before, $schema);
        foreach ($platform->getAlterSchemaSQL($diff) as $sql) { $this->addSql($sql); }
        // Queue every mutation, including the data phase, so --dry-run never writes.
        $plan = $this->container->get(ManufacturingSnapshotMigrationPlan::class)->statements();
        if ($platform instanceof AbstractMySQLPlatform) { $this->addSql('START TRANSACTION'); }
        foreach ($plan as $query) { $this->addSql($query->getStatement(), $query->getParameters(), $query->getTypes()); }
        if ($platform instanceof AbstractMySQLPlatform) { $this->addSql('COMMIT'); }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Restore a verified backup rather than discard fixed manufacturing definitions.');
    }
}
