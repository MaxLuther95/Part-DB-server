<?php

declare(strict_types=1);

namespace App\Tests\Services\Production;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260915090000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class OrderImportLineMigrationTest extends TestCase
{
    public function testExistingAssignmentsAndOpenLinesRemainDistinct(): void
    {
        require_once dirname(__DIR__, 3).'/migrations/Version20260915090000.php';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE production_order_import_lines (id INTEGER PRIMARY KEY, mapping_id INTEGER)');
        $connection->executeStatement('INSERT INTO production_order_import_lines VALUES (1,NULL), (2,42)');
        $migration = new Version20260915090000($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        self::assertSame([1 => 'pending', 2 => 'assigned'], $connection->fetchAllKeyValue('SELECT id, disposition FROM production_order_import_lines ORDER BY id'));
        $connection->close();
    }
}
