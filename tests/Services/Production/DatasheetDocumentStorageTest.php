<?php

declare(strict_types=1);

namespace App\Tests\Services\Production;

use App\Services\Production\DatasheetDocumentStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class DatasheetDocumentStorageTest extends TestCase
{
    private string $directory;
    private Filesystem $filesystem;
    private DatasheetDocumentStorage $storage;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/partdb-datasheet-storage-'.bin2hex(random_bytes(8));
        $this->filesystem = new Filesystem();
        $this->storage = new DatasheetDocumentStorage($this->directory, $this->filesystem);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->directory);
    }

    public function testStoresAndServesHashedPdfOutsidePublicTree(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj <<>> endobj\n%%EOF";
        $document = $this->storage->store($pdf, 'CID 688 customer datasheet.pdf', [
            'source_protocol_run_ids' => [12, 13],
        ]);

        self::assertSame(hash('sha256', $pdf), $document->getSha256Checksum());
        self::assertSame(strlen($pdf), $document->getFileSize());
        self::assertSame('CID-688-customer-datasheet.pdf', $document->getOriginalFilename());
        self::assertSame([12, 13], $document->getSourceSnapshot()['source_protocol_run_ids']);
        self::assertFileExists($this->directory.'/'.$document->getStoredFilename());
        self::assertSame(0o440, fileperms($this->directory.'/'.$document->getStoredFilename()) & 0o777);

        $response = $this->storage->createDownloadResponse($document);
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testRejectsNonPdfContentAndUnsafeStoredFilename(): void
    {
        try {
            $this->storage->store('<script>alert(1)</script>', 'attack.pdf', []);
            self::fail('Non-PDF content must be rejected.');
        } catch (\RuntimeException) {
            self::assertDirectoryDoesNotExist($this->directory);
        }

        $document = $this->storage->store('%PDF-safe', 'safe.pdf', []);
        $document->setStoredFilename('../safe.pdf');
        $this->expectException(\InvalidArgumentException::class);
        $this->storage->createDownloadResponse($document);
    }
}
