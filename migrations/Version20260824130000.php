<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20260824130000 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Add non-withdrawing, lot-specific material reservations for commissioned production projects.';
    }

    public function mySQLUp(Schema $schema): void
    {
        $this->createTable('INT AUTO_INCREMENT NOT NULL', 'INT NOT NULL', 'DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL', 'ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
    }

    public function sqLiteUp(Schema $schema): void
    {
        $this->createTable('INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL', 'INTEGER NOT NULL', 'DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL', '', '');
    }

    public function postgreSQLUp(Schema $schema): void
    {
        $this->createTable('SERIAL NOT NULL', 'INT NOT NULL', 'TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL', '');
    }

    private function createTable(string $id, string $integer, string $datetime, string $suffix, string $primaryKey = ', PRIMARY KEY(id)'): void
    {
        $this->addSql(sprintf('CREATE TABLE production_project_material_reservations (id %s, customer_project_id %s, part_id INT DEFAULT NULL, source_part_lot_id INT DEFAULT NULL, site_id INT DEFAULT NULL, reserved_by_id INT DEFAULT NULL, part_name VARCHAR(255) NOT NULL, part_reference_id INT DEFAULT NULL, source_lot_name VARCHAR(255) DEFAULT NULL, site_name VARCHAR(255) NOT NULL, quantity %s, last_modified %s, datetime_added %s%s, CONSTRAINT FK_PROD_RES_PROJECT FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON DELETE CASCADE, CONSTRAINT FK_PROD_RES_PART FOREIGN KEY (part_id) REFERENCES parts (id) ON DELETE SET NULL, CONSTRAINT FK_PROD_RES_LOT FOREIGN KEY (source_part_lot_id) REFERENCES part_lots (id) ON DELETE SET NULL, CONSTRAINT FK_PROD_RES_SITE FOREIGN KEY (site_id) REFERENCES storelocations (id) ON DELETE SET NULL, CONSTRAINT FK_PROD_RES_USER FOREIGN KEY (reserved_by_id) REFERENCES users (id) ON DELETE SET NULL) %s', $id, $integer, $integer, $datetime, $datetime, $primaryKey, $suffix));
        $this->addSql('CREATE INDEX IDX_PROD_RES_PROJECT ON production_project_material_reservations (customer_project_id)');
        $this->addSql('CREATE INDEX IDX_PROD_RES_PART ON production_project_material_reservations (part_id)');
        $this->addSql('CREATE INDEX IDX_PROD_RES_LOT ON production_project_material_reservations (source_part_lot_id)');
        $this->addSql('CREATE INDEX IDX_PROD_RES_SITE ON production_project_material_reservations (site_id)');
        $this->addSql('CREATE INDEX IDX_PROD_RES_USER ON production_project_material_reservations (reserved_by_id)');
    }

    public function mySQLDown(Schema $schema): void { $this->abortDowngrade(); }
    public function sqLiteDown(Schema $schema): void { $this->abortDowngrade(); }
    public function postgreSQLDown(Schema $schema): void { $this->abortDowngrade(); }
    private function abortDowngrade(): never { throw new IrreversibleMigration('Restore the database backup to remove production reservations safely.'); }
}
