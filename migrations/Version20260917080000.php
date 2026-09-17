<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260917080000 extends AbstractMigration
{
    public function getDescription(): string { return 'Preserve PDF position descriptions until their manufacturing assignment.'; }
    public function isTransactional(): bool { return !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform; }

    public function up(Schema $schema): void
    {
        $type = $this->connection->getDatabasePlatform()->getClobTypeDeclarationSQL([]);
        $this->addSql('ALTER TABLE production_order_import_lines ADD notes '.$type.' DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Imported position notes must be retained.');
    }
}
