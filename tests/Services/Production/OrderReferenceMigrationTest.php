<?php

declare(strict_types=1);

namespace App\Tests\Services\Production;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260915080000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class OrderReferenceMigrationTest extends TestCase
{
    public function testLegacyReferencesMoveWithoutLosingNotes(): void
    {
        require_once dirname(__DIR__, 3).'/migrations/Version20260915080000.php';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE production_customer_projects (id INTEGER PRIMARY KEY, notes TEXT)');
        $original = [1 => 'Kundenreferenz: REF-ONE', 2 => "Kundenreferenz: REF-TWO\nDelivery note", 3 => 'Ordinary notes', 4 => null, 5 => "Kundenreferenz: 0\n0"];
        foreach ($original as $id => $notes) {
            $connection->insert('production_customer_projects', ['id' => $id, 'notes' => $notes]);
        }
        $migration = new Version20260915080000($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        self::assertSame([
            ['id' => 1, 'notes' => null, 'customer_reference' => 'REF-ONE'],
            ['id' => 2, 'notes' => 'Delivery note', 'customer_reference' => 'REF-TWO'],
            ['id' => 3, 'notes' => 'Ordinary notes', 'customer_reference' => null],
            ['id' => 4, 'notes' => null, 'customer_reference' => null],
            ['id' => 5, 'notes' => '0', 'customer_reference' => '0'],
        ], $connection->fetchAllAssociative('SELECT * FROM production_customer_projects ORDER BY id'));
        $migration = new Version20260915080000($connection, new NullLogger());
        $migration->down(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        self::assertSame($original, $connection->fetchAllKeyValue('SELECT id, notes FROM production_customer_projects ORDER BY id'));
        $connection->close();
    }
}
