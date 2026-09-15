<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260911090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add configurable component table header sources and safe text formatting.';
    }

    public function isTransactional(): bool
    {
        return ! $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('production_datasheet_template_blocks');
        $table->addColumn('header_source_path', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('header_format', Types::STRING, ['length' => 255, 'default' => '{value}']);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Restore a verified backup rather than discard configured header mappings.');
    }
}
