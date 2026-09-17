<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260917090000 extends AbstractMigration
{
    public function getDescription(): string { return 'Allow complete multiline PDF descriptions in accessory notes without changing existing content.'; }
    public function isTransactional(): bool { return !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform; }

    public function up(Schema $schema): void
    {
        $schema->getTable('production_project_accessories')->modifyColumn('note', [
            'type' => Type::getType(Types::TEXT),
            'length' => null,
        ]);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Restoring a short field could truncate existing accessory notes.');
    }
}
