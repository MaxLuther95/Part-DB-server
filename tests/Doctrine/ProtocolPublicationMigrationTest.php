<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260909091000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ProtocolPublicationMigrationTest extends TestCase
{
    public function testOnlySupersededPublishedRevisionsAreRetired(): void
    {
        require_once dirname(__DIR__, 2).'/migrations/Version20260909091000.php';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        try {
            $connection->executeStatement('CREATE TABLE production_protocol_template_revisions (id INTEGER PRIMARY KEY, template_id INTEGER, revision_number INTEGER, status TEXT)');
            $connection->executeStatement(<<<'SQL'
                INSERT INTO production_protocol_template_revisions VALUES
                (1, 1, 1, 'published'), (2, 1, 2, 'published'), (3, 1, 3, 'draft'),
                (4, 2, 1, 'retired'), (5, 2, 2, 'published'), (6, 3, 1, 'draft')
                SQL);
            $migration = new Version20260909091000($connection, new NullLogger());
            $migration->up(new Schema());
            self::assertCount(1, $migration->getSql());
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
            self::assertSame(
                ['retired', 'published', 'draft', 'retired', 'published', 'draft'],
                $connection->fetchFirstColumn('SELECT status FROM production_protocol_template_revisions ORDER BY id')
            );
            $repeated = new Version20260909091000($connection, new NullLogger());
            $repeated->up(new Schema());
            self::assertSame([], $repeated->getSql());
        } finally {
            $connection->close();
        }
    }
}
