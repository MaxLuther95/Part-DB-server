<?php

declare(strict_types=1);

namespace App\Tests\Services\Production;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use DoctrineMigrations\Version20260901080000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ProductionIndexMigrationTest extends TestCase
{
    public static function interruptionPoints(): iterable
    {
        for ($count = 0; $count <= 5; ++$count) {
            yield 'existing indexes '.$count => [$count];
        }
    }

    #[DataProvider('interruptionPoints')]
    public function testFreshAndInterruptedMigrationPreservesData(int $existing): void
    {
        require_once dirname(__DIR__, 3).'/migrations/Version20260901080000.php';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach (['production_customer_projects', 'production_projects', 'production_build_instances'] as $table) {
            $connection->executeStatement('CREATE TABLE '.$table.' (id INTEGER PRIMARY KEY, status VARCHAR(32), order_date DATETIME, customer_id INTEGER, datetime_added DATETIME, customer_project_id INTEGER)');
            $connection->insert($table, ['id' => 1, 'status' => 'synthetic']);
        }
        $manager = $connection->createSchemaManager();
        $migration = new Version20260901080000($connection, new NullLogger());
        $migration->up($manager->introspectSchema());
        $sql = $migration->getSql();
        self::assertCount(5, $sql);
        foreach (array_slice($sql, 0, $existing) as $statement) {
            $connection->executeStatement($statement->getStatement());
        }

        $resumed = new Version20260901080000($connection, new NullLogger());
        $resumed->up($manager->introspectSchema());
        $remaining = $resumed->getSql();
        self::assertCount(5 - $existing, $remaining);
        foreach ($remaining as $statement) {
            $connection->executeStatement($statement->getStatement());
        }
        $repeat = new Version20260901080000($connection, new NullLogger());
        $repeat->up($manager->introspectSchema());
        self::assertSame([], $repeat->getSql());
        foreach (['production_customer_projects', 'production_projects', 'production_build_instances'] as $table) {
            self::assertSame([['id' => 1, 'status' => 'synthetic']], $connection->fetchAllAssociative('SELECT id, status FROM '.$table));
        }
        $connection->close();
    }

    public function testSameNameWithWrongDefinitionIsRejected(): void
    {
        require_once dirname(__DIR__, 3).'/migrations/Version20260901080000.php';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema = new Schema();
        $table = $schema->createTable('production_customer_projects');
        $table->addColumn('status', 'string');
        $table->addColumn('order_date', 'datetime');
        $table->addIndex(['order_date', 'status'], 'idx_prod_order_status_date');
        $this->expectException(AbortMigration::class);
        $this->expectExceptionMessage('unexpected definition');
        try {
            (new Version20260901080000($connection, new NullLogger()))->up($schema);
        } finally {
            $connection->close();
        }
    }
}
