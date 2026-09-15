<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add local many-to-many system assignments for protocol templates and optional per-run notes.';
    }

    public function isTransactional(): bool
    {
        return ! $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('production_protocol_runs')->addColumn('notes', Types::TEXT, ['notnull' => false]);
        $table = $schema->createTable('production_protocol_template_systems');
        $table->addColumn('protocol_template_id', Types::INTEGER);
        $table->addColumn('system_template_id', Types::INTEGER);
        $table->setPrimaryKey(['protocol_template_id', 'system_template_id']);
        $table->addForeignKeyConstraint('production_protocol_templates', ['protocol_template_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addForeignKeyConstraint('production_system_templates', ['system_template_id'], ['id'], ['onDelete' => 'CASCADE']);
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $table->addOption('charset', 'utf8mb4');
            $table->addOption('collation', 'utf8mb4_unicode_ci');
            $table->addOption('engine', 'InnoDB');
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Do not discard protocol notes or system assignments. Restore a verified backup if rollback is required.');
    }
}
