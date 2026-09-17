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
    public function testPositionNotesMigrationPreservesExistingRows(): void
    {
        require_once dirname(__DIR__, 3).'/migrations/Version20260917080000.php';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE production_order_import_lines (id INTEGER PRIMARY KEY, description VARCHAR(255), disposition VARCHAR(16))');
        $connection->executeStatement("INSERT INTO production_order_import_lines VALUES (1,'Synthetic pending','pending'), (2,'Synthetic assigned','assigned')");
        $before = $connection->fetchAllAssociative('SELECT * FROM production_order_import_lines ORDER BY id');
        $migration = new \DoctrineMigrations\Version20260917080000($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        self::assertSame($before, $connection->fetchAllAssociative('SELECT id, description, disposition FROM production_order_import_lines ORDER BY id'));
        self::assertSame([null, null], $connection->fetchFirstColumn('SELECT notes FROM production_order_import_lines ORDER BY id'));
        $connection->executeStatement('UPDATE production_order_import_lines SET notes = ? WHERE id = 1', ["Synthetic multiline\nposition note"]);
        self::assertSame("Synthetic multiline\nposition note", $connection->fetchOne('SELECT notes FROM production_order_import_lines WHERE id = 1'));
        $connection->close();
    }

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
