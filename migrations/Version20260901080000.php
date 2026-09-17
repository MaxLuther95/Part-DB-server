<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;

final class Version20260901080000 extends AbstractMultiPlatformMigration
{
    private const INDEXES = [
        ['production_customer_projects', 'IDX_PROD_ORDER_STATUS_DATE', ['status', 'order_date']],
        ['production_customer_projects', 'IDX_PROD_ORDER_CUSTOMER_DATE', ['customer_id', 'order_date']],
        ['production_projects', 'IDX_PROD_PROJECT_STATUS_DATE', ['status', 'datetime_added']],
        ['production_build_instances', 'IDX_PROD_BUILD_STATUS_DATE', ['status', 'datetime_added']],
        ['production_build_instances', 'IDX_PROD_BUILD_ORDER_STATUS', ['customer_project_id', 'status']],
    ];

    public function isTransactional(): bool
    {
        // MySQL/MariaDB commit DDL implicitly, including when a later statement fails.
        return !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }

    public function getDescription(): string
    {
        return 'Add indexes for production order, project and build-instance list filters.';
    }

    public function mySQLUp(Schema $schema): void
    {
        foreach (self::INDEXES as [$tableName, $name, $columns]) {
            $table = $schema->getTable($tableName);
            if ($table->hasIndex($name)) {
                $index = $table->getIndex($name);
                // Accept only the complete expected definition, never just its name.
                $this->abortIf(
                    $index->getColumns() !== $columns || !$index->isSimpleIndex()
                    || $index->getFlags() !== []
                    || ($index->hasOption('where') && $index->getOption('where'))
                    || ($index->hasOption('lengths') && array_filter($index->getOption('lengths')) !== []),
                    sprintf('Index %s on %s exists with an unexpected definition; review it before retrying.', $name, $tableName)
                );
                continue;
            }

            $this->addSql(sprintf('CREATE INDEX %s ON %s (%s)', $name, $tableName, implode(', ', $columns)));
        }
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
