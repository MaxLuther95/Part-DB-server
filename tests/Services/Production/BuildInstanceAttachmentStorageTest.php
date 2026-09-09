<?php

declare(strict_types=1);

namespace App\Tests\Services\Production;

use App\Entity\Production\AbstractProductionEntity;
use App\Entity\Production\BuildInstance;
use App\Entity\Production\BuildInstanceAttachment;
use App\Services\Attachments\AttachmentPathResolver;
use App\Services\Attachments\AttachmentSubmitHandler;
use App\Services\Production\BuildInstanceAttachmentStorage;
use App\Services\Production\OrderAttachmentStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class BuildInstanceAttachmentStorageTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir().'/partdb-build-attachment-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->temporaryDirectory, 0700, true));
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->temporaryDirectory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->temporaryDirectory);
    }

    public function testStoresSanitizedFileInProtectedInstanceDirectoryWithChecksum(): void
    {
        $source = $this->temporaryDirectory.'/source.txt';
        file_put_contents($source, "Messprotokoll\n");
        $buildInstance = $this->persistedBuildInstance(42);
        $storage = $this->createStorage();

        $attachment = $storage->storeUpload(
            $buildInstance,
            new UploadedFile($source, '../unsicher\\messung.TXT', 'text/plain', null, true),
        );

        self::assertSame('messung.txt', $attachment->getOriginalFilename());
        self::assertMatchesRegularExpression('/^[a-f0-9]{48}\.txt$/D', $attachment->getStoredFilename());
        self::assertSame(hash('sha256', "Messprotokoll\n"), $attachment->getSha256Checksum());
        self::assertSame(14, $attachment->getFileSize());
        self::assertSame($buildInstance, $attachment->getBuildInstance());
        self::assertFileExists($storage->getAbsolutePath($attachment));

        $storage->remove($attachment);
        self::assertFileDoesNotExist($this->temporaryDirectory.'/production-build-instances/42/'.$attachment->getStoredFilename());
    }

    public function testRejectsPathTraversalInPersistedFilename(): void
    {
        $attachment = (new BuildInstanceAttachment())
            ->setBuildInstance($this->persistedBuildInstance(7))
            ->setStoredFilename('../../outside.txt');

        $this->expectException(\RuntimeException::class);
        $this->createStorage()
            ->getAbsolutePath($attachment);
    }

    public function testRejectsExecutableUpload(): void
    {
        $source = $this->temporaryDirectory.'/payload.php';
        file_put_contents($source, '<?php echo "unsafe";');

        $this->expectException(\InvalidArgumentException::class);
        $this->createStorage()
            ->storeUpload(
                $this->persistedBuildInstance(9),
                new UploadedFile($source, 'payload.php', 'text/x-php', null, true),
            );
    }

    private function createStorage(): BuildInstanceAttachmentStorage
    {
        $pathResolver = new AttachmentPathResolver(
            $this->temporaryDirectory,
            $this->temporaryDirectory,
            $this->temporaryDirectory,
            $this->temporaryDirectory,
            $this->temporaryDirectory,
        );
        $submitHandler = $this->createStub(AttachmentSubmitHandler::class);
        $submitHandler->method('getMaximumEffectiveUploadSize')
            ->willReturn(10 * 1024 * 1024);

        return new BuildInstanceAttachmentStorage(
            $pathResolver,
            new OrderAttachmentStorage($pathResolver, $submitHandler),
        );
    }

    private function persistedBuildInstance(int $id): BuildInstance
    {
        $instance = new BuildInstance();
        $idProperty = new \ReflectionProperty(AbstractProductionEntity::class, 'id');
        $idProperty->setValue($instance, $id);

        return $instance;
    }
}
