<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace the removed paused device/assembly status with in progress.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE production_build_instances SET status = 'in_progress' WHERE status = 'paused'");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Previously paused builds cannot be distinguished from other builds in progress.');
    }
}
