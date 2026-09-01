<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20260826103000 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Store the primary production site on customer projects.';
    }

    public function mySQLUp(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_customer_projects ADD production_site_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE production_customer_projects ADD CONSTRAINT FK_PROD_PROJECT_SITE FOREIGN KEY (production_site_id) REFERENCES storelocations (id) ON DELETE SET NULL');
        $this->finishMigration();
    }

    public function sqLiteUp(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_customer_projects ADD COLUMN production_site_id INTEGER DEFAULT NULL REFERENCES storelocations (id) ON DELETE SET NULL');
        $this->finishMigration();
    }

    public function postgreSQLUp(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_customer_projects ADD production_site_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE production_customer_projects ADD CONSTRAINT FK_PROD_PROJECT_SITE FOREIGN KEY (production_site_id) REFERENCES storelocations (id) ON DELETE SET NULL');
        $this->finishMigration();
    }

    private function finishMigration(): void
    {
        $this->addSql('CREATE INDEX IDX_PROD_PROJECT_SITE ON production_customer_projects (production_site_id)');
        $this->addSql('UPDATE production_customer_projects SET production_site_id = (SELECT reservation.site_id FROM production_project_material_reservations reservation WHERE reservation.customer_project_id = production_customer_projects.id AND reservation.site_id IS NOT NULL LIMIT 1) WHERE production_site_id IS NULL');
    }

    public function mySQLDown(Schema $schema): void { $this->abortDowngrade(); }
    public function sqLiteDown(Schema $schema): void { $this->abortDowngrade(); }
    public function postgreSQLDown(Schema $schema): void { $this->abortDowngrade(); }

    private function abortDowngrade(): never
    {
        throw new IrreversibleMigration('Restore the database backup to remove the production site relation safely.');
    }
}
