<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Version protocol runs to protect answers and lifecycle transitions against concurrent writes.';
    }

    public function isTransactional(): bool
    {
        return ! $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('production_protocol_runs')->addColumn('version', Types::INTEGER, ['default' => 1]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('production_protocol_runs')->dropColumn('version');
    }
}
