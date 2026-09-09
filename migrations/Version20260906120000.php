<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260906120000 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Add an optional planned delivery date to production orders.';
    }

    public function mySQLUp(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_customer_projects ADD planned_delivery_date DATE DEFAULT NULL');
    }

    public function mySQLDown(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_customer_projects DROP planned_delivery_date');
    }

    public function sqLiteUp(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_customer_projects ADD planned_delivery_date DATE DEFAULT NULL');
    }

    public function sqLiteDown(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_customer_projects DROP COLUMN planned_delivery_date');
    }

    public function postgreSQLUp(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_customer_projects ADD planned_delivery_date DATE DEFAULT NULL');
    }

    public function postgreSQLDown(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_customer_projects DROP COLUMN planned_delivery_date');
    }
}
