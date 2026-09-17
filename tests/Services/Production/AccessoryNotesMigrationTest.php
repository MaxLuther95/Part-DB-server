<?php

declare(strict_types=1);

namespace App\Tests\Services\Production;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\TextType;
use DoctrineMigrations\Version20260917090000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AccessoryNotesMigrationTest extends TestCase
{
    public function testWideningNotePreservesExistingValuesAndDefaults(): void
    {
        require_once dirname(__DIR__, 3).'/migrations/Version20260917090000.php';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement("CREATE TABLE production_project_accessories (id INTEGER PRIMARY KEY, note VARCHAR(255) NOT NULL DEFAULT '', quantity INTEGER NOT NULL)");
        foreach ([1 => '', 2 => "Existing manual note ä with \"quotes\".\nSecond line."] as $id => $note) {
            $connection->insert('production_project_accessories', ['id' => $id, 'note' => $note, 'quantity' => 2]);
        }
        $beforeValues = $connection->fetchAllAssociative('SELECT * FROM production_project_accessories ORDER BY id');
        $manager = $connection->createSchemaManager();
        $before = $manager->introspectSchema();
        $after = clone $before;
        (new Version20260917090000($connection, new NullLogger()))->up($after);
        $diff = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($connection->getDatabasePlatform()->getAlterSchemaSQL($diff) as $sql) {
            $connection->executeStatement($sql);
        }
        self::assertSame($beforeValues, $connection->fetchAllAssociative('SELECT * FROM production_project_accessories ORDER BY id'));
        $column = $connection->createSchemaManager()->introspectTable('production_project_accessories')->getColumn('note');
        self::assertInstanceOf(TextType::class, $column->getType());
        self::assertSame('', $column->getDefault());
        $note = str_repeat("Multiline ä detail.\n", 1000);
        $connection->update('production_project_accessories', ['note' => $note], ['id' => 1]);
        self::assertSame($note, $connection->fetchOne('SELECT note FROM production_project_accessories WHERE id = 1'));
        $connection->close();
    }
}
