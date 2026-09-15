<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record explicitly accepted missing protocol fields at completion.';
    }

    public function isTransactional(): bool
    {
        return ! $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('production_protocol_runs')->addColumn('completion_warnings', Types::JSON, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('production_protocol_runs')->dropColumn('completion_warnings');
    }
}
