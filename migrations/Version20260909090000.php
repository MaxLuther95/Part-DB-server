<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260909090000 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Align MySQL/MariaDB import JSON columns with the ORM mapping.';
    }

    public function isTransactional(): bool
    {
        return 'mysql' !== $this->getDatabaseType();
    }

    public function mySQLUp(Schema $schema): void
    {
        $invalid = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM bulk_info_provider_import_jobs WHERE JSON_VALID(field_mappings) = 0 OR JSON_VALID(search_results) = 0'
        );
        $this->abortIf((int) $invalid > 0, 'Invalid import JSON exists. Review the affected records before migrating; no data was changed.');

        $this->addSql('ALTER TABLE bulk_info_provider_import_jobs CHANGE field_mappings field_mappings JSON NOT NULL, CHANGE search_results search_results JSON NOT NULL');
    }

    public function mySQLDown(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Do not remove JSON validation or restore obsolete column definitions. Restore the pre-migration backup if needed.');
    }

    public function sqLiteUp(Schema $schema): void
    {
        // SQLite already matches its ORM mapping.
    }

    public function sqLiteDown(Schema $schema): void
    {
    }

    public function postgreSQLUp(Schema $schema): void
    {
        // PostgreSQL already matches its ORM mapping.
    }

    public function postgreSQLDown(Schema $schema): void
    {
    }
}
