<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260901080000 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Add indexes for production order, project and build-instance list filters.';
    }

    public function mySQLUp(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IDX_PROD_ORDER_STATUS_DATE ON production_customer_projects (status, order_date)');
        $this->addSql('CREATE INDEX IDX_PROD_ORDER_CUSTOMER_DATE ON production_customer_projects (customer_id, order_date)');
        $this->addSql('CREATE INDEX IDX_PROD_PROJECT_STATUS_DATE ON production_projects (status, datetime_added)');
        $this->addSql('CREATE INDEX IDX_PROD_BUILD_STATUS_DATE ON production_build_instances (status, datetime_added)');
        $this->addSql('CREATE INDEX IDX_PROD_BUILD_ORDER_STATUS ON production_build_instances (customer_project_id, status)');
    }

    public function sqLiteUp(Schema $schema): void
    {
        $this->mySQLUp($schema);
    }

    public function postgreSQLUp(Schema $schema): void
    {
        $this->mySQLUp($schema);
    }

    public function mySQLDown(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_PROD_ORDER_STATUS_DATE ON production_customer_projects');
        $this->addSql('DROP INDEX IDX_PROD_ORDER_CUSTOMER_DATE ON production_customer_projects');
        $this->addSql('DROP INDEX IDX_PROD_PROJECT_STATUS_DATE ON production_projects');
        $this->addSql('DROP INDEX IDX_PROD_BUILD_STATUS_DATE ON production_build_instances');
        $this->addSql('DROP INDEX IDX_PROD_BUILD_ORDER_STATUS ON production_build_instances');
    }

    public function sqLiteDown(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_PROD_ORDER_STATUS_DATE');
        $this->addSql('DROP INDEX IDX_PROD_ORDER_CUSTOMER_DATE');
        $this->addSql('DROP INDEX IDX_PROD_PROJECT_STATUS_DATE');
        $this->addSql('DROP INDEX IDX_PROD_BUILD_STATUS_DATE');
        $this->addSql('DROP INDEX IDX_PROD_BUILD_ORDER_STATUS');
    }

    public function postgreSQLDown(Schema $schema): void
    {
        $this->sqLiteDown($schema);
    }
}
