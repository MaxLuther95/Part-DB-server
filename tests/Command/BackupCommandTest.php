<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\BackupCommand;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\ORM\EntityManagerInterface;
use PhpZip\ZipFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class BackupCommandTest extends TestCase
{
    private string $directory;
    private Filesystem $filesystem;
    private string|false $originalPath;
    private mixed $serverPath;
    private mixed $envPath;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->directory = sys_get_temp_dir().'/partdb-backup-test-'.bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->directory, 0700);
        foreach (['config/parameters.yaml', 'config/banner.md', 'public/kicad/footprints_custom.txt',
            'public/kicad/symbols_custom.txt', 'public/media/example.txt', 'uploads/example.txt'] as $file) {
            $this->filesystem->dumpFile($this->directory.'/'.$file, 'Backup fixture');
        }
        $this->originalPath = getenv('PATH');
        $this->serverPath = $_SERVER['PATH'] ?? null;
        $this->envPath = $_ENV['PATH'] ?? null;
        // Exercise the real dumper process and exception handling without accessing a database.
        $path = $this->directory.'/bin'.PATH_SEPARATOR.($this->originalPath ?: '');
        putenv('PATH='.$path);
        $_SERVER['PATH'] = $_ENV['PATH'] = $path;
    }

    protected function tearDown(): void
    {
        putenv($this->originalPath === false ? 'PATH' : 'PATH='.$this->originalPath);
        if ($this->serverPath === null) {
            unset($_SERVER['PATH']);
        } else {
            $_SERVER['PATH'] = $this->serverPath;
        }
        if ($this->envPath === null) {
            unset($_ENV['PATH']);
        } else {
            $_ENV['PATH'] = $this->envPath;
        }
        $this->filesystem->remove($this->directory);
    }

    public static function failedDumps(): iterable
    {
        foreach ([MySQLPlatform::class, PostgreSQLPlatform::class] as $platform) {
            foreach (['--database', '--full'] as $option) {
                foreach ([false, true] as $existing) {
                    foreach (['exit 1', 'exit 0'] as $script) {
                        yield [$platform, $option, $existing, $script];
                    }
                }
            }
        }
    }

    #[DataProvider('failedDumps')]
    public function testFailedOrEmptyDumpNeverReportsSuccessOrReplacesBackup(
        string $platform, string $option, bool $existing, string $script,
    ): void {
        $this->installDumpers($script);
        $output = $this->directory.'/backup.zip';
        if ($existing) {
            $zip = new ZipFile();
            $zip->addFromString('database.sql', 'Previous successful backup')->saveAsFile($output)->close();
        }
        $before = $existing ? hash_file('sha256', $output) : null;
        $tester = $this->tester(new $platform());
        $tester->execute(['output' => $output, $option => true, '--overwrite' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Backup failed:', $tester->getDisplay());
        self::assertStringNotContainsString('Backup finished!', $tester->getDisplay());
        if ($existing) {
            self::assertSame($before, hash_file('sha256', $output));
        } else {
            self::assertFileDoesNotExist($output);
        }
    }

    public static function sqlPlatforms(): iterable
    {
        yield [new MySQLPlatform()];
        yield [new PostgreSQLPlatform()];
    }

    #[DataProvider('sqlPlatforms')]
    public function testSuccessfulFullBackupContainsDatabaseAndFiles(AbstractPlatform $platform): void
    {
        $this->installDumpers("printf '%s' 'CREATE TABLE backup_test (id INT);'");
        $output = $this->directory.'/backup.zip';
        $tester = $this->tester($platform);
        $tester->execute(['output' => $output, '--full' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Backup finished!', $tester->getDisplay());
        $zip = new ZipFile();
        try {
            $zip->openFile($output);
            self::assertSame('CREATE TABLE backup_test (id INT);', $zip->getEntryContents('database.sql'));
            self::assertSame('Backup fixture', $zip->getEntryContents('uploads/example.txt'));
            self::assertSame('Backup fixture', $zip->getEntryContents('config/parameters.yaml'));
        } finally {
            $zip->close();
        }
    }

    public function testUnsupportedDatabaseFails(): void
    {
        $tester = $this->tester(new SQLServerPlatform());
        $tester->execute(['output' => $this->directory.'/backup.zip', '--full' => true], ['interactive' => false]);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Unknown database platform', $tester->getDisplay());
        self::assertFileDoesNotExist($this->directory.'/backup.zip');
    }

    public function testMissingSqliteFileFails(): void
    {
        $tester = $this->tester(new SQLitePlatform());
        $tester->execute(['output' => $this->directory.'/backup.zip', '--database' => true], ['interactive' => false]);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringNotContainsString('Backup finished!', $tester->getDisplay());
        self::assertFileDoesNotExist($this->directory.'/backup.zip');
    }

    public function testAttachmentsOnlyDoesNotRequireDatabase(): void
    {
        $tester = $this->tester(new SQLServerPlatform());
        $tester->execute(['output' => $this->directory.'/backup.zip', '--attachments' => true], ['interactive' => false]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testMissingConfigurationFailsWithoutReplacingBackup(): void
    {
        $this->filesystem->remove($this->directory.'/config/parameters.yaml');
        $output = $this->directory.'/backup.zip';
        $this->filesystem->dumpFile($output, 'Keep existing backup');
        $tester = $this->tester(new MySQLPlatform());
        $tester->execute(['output' => $output, '--config' => true, '--overwrite' => true], ['interactive' => false]);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame('Keep existing backup', file_get_contents($output));
        self::assertStringNotContainsString('Backup finished!', $tester->getDisplay());
    }

    private function installDumpers(string $script): void
    {
        foreach (['mysqldump', 'pg_dump'] as $binary) {
            $file = $this->directory.'/bin/'.$binary;
            $this->filesystem->dumpFile($file, "#!/bin/sh\n".$script."\n");
            $this->filesystem->chmod($file, 0700);
        }
    }

    private function tester(AbstractPlatform $platform): CommandTester
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('getParams')->willReturn([
            'host' => 'localhost', 'user' => 'backup_test', 'password' => 'test-only',
            'dbname' => 'backup_test', 'path' => $this->directory.'/missing.db',
        ]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        return new CommandTester(new BackupCommand($this->directory, $entityManager));
    }
}
