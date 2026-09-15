<?php

declare(strict_types=1);

namespace App\Tests\Services\Attachments;

use App\Entity\UserSystem\User;
use App\Services\Attachments\AttachmentPathResolver;
use App\Services\Attachments\PrivateMediaMigration;
use App\Services\UserSystem\PermissionManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class PrivateMediaMigrationTest extends TestCase
{
    private string $directory;
    private Connection $connection;
    private User $anonymous;
    private PrivateMediaMigration $migration;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/partdb-privacy-test-'.bin2hex(random_bytes(8));
        $fs = new Filesystem();
        $fs->mkdir([$this->directory.'/public/media/part', $this->directory.'/uploads'], 0700);
        $fs->dumpFile($this->directory.'/public/media/part/sample.txt', 'Private test document');
        $fs->dumpFile($this->directory.'/public/media/cached-preview.txt', 'Old thumbnail');
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE attachments (id INTEGER PRIMARY KEY, internal_path TEXT)');
        $this->connection->insert('attachments', ['id' => 1, 'internal_path' => '%MEDIA%/part/sample.txt']);
        $this->anonymous = new User();
        $this->anonymous->getPermissions()->setPermissionValue('parts', 'read', true);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->connection);
        $em->method('find')->willReturn($this->anonymous);
        $permissions = $this->createStub(PermissionManager::class);
        $permissions->method('getPermissionStructure')->willReturn(['perms' => ['parts' => ['operations' => ['read' => [], 'edit' => []]]]]);
        $paths = new AttachmentPathResolver($this->directory, 'public/media', 'uploads', 'public/media');
        $this->migration = new PrivateMediaMigration($paths, $em, $permissions, $fs);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        (new Filesystem())->remove($this->directory);
    }

    public function testDryRunAndVerifiedPrivateMigrationPreserveDataAndRemoveOldCopies(): void
    {
        self::assertSame(['attachments' => 1, 'public_files' => 2, 'archive' => null], $this->migration->run());
        self::assertFileExists($this->directory.'/public/media/part/sample.txt');
        self::assertSame('%MEDIA%/part/sample.txt', $this->connection->fetchOne('SELECT internal_path FROM attachments'));
        self::assertTrue($this->anonymous->getPermissions()->getPermissionValue('parts', 'read'));
        $result = $this->migration->run(true);
        self::assertSame('%SECURE%/part/sample.txt', $this->connection->fetchOne('SELECT internal_path FROM attachments'));
        self::assertSame('Private test document', file_get_contents($this->directory.'/uploads/part/sample.txt'));
        self::assertSame('Private test document', file_get_contents($result['archive'].'/media/part/sample.txt'));
        self::assertSame('Old thumbnail', file_get_contents($result['archive'].'/media/cached-preview.txt'));
        self::assertFileDoesNotExist($this->directory.'/public/media/part/sample.txt');
        self::assertFileDoesNotExist($this->directory.'/public/media/cached-preview.txt');
        self::assertFalse($this->anonymous->getPermissions()->getPermissionValue('parts', 'read'));
        self::assertFalse($this->anonymous->getPermissions()->getPermissionValue('parts', 'edit'));
        self::assertSame(0, $this->migration->run()['attachments']);
    }

    public function testConflictingPrivateFileIsNeverOverwritten(): void
    {
        (new Filesystem())->dumpFile($this->directory.'/uploads/part/sample.txt', 'Different private file');
        try {
            $this->migration->run(true);
            self::fail('A conflicting destination must be rejected.');
        } catch (\RuntimeException) {
            self::assertSame('Different private file', file_get_contents($this->directory.'/uploads/part/sample.txt'));
            self::assertFileExists($this->directory.'/public/media/part/sample.txt');
            self::assertSame('%MEDIA%/part/sample.txt', $this->connection->fetchOne('SELECT internal_path FROM attachments'));
            self::assertTrue($this->anonymous->getPermissions()->getPermissionValue('parts', 'read'));
        }
    }

    public function testMissingReferenceStopsMigrationWithoutChangingPermissions(): void
    {
        $this->connection->insert('attachments', ['id' => 2, 'internal_path' => '%MEDIA%/missing.txt']);
        try {
            $this->migration->run(true);
            self::fail('A missing attachment must be reported, not deleted.');
        } catch (\RuntimeException) {
            self::assertFileExists($this->directory.'/public/media/part/sample.txt');
            self::assertTrue($this->anonymous->getPermissions()->getPermissionValue('parts', 'read'));
        }
    }
}
