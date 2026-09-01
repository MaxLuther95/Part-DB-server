<?php

declare(strict_types=1);

namespace App\Tests\Services\Production;

use App\Services\Attachments\AttachmentPathResolver;
use App\Services\Attachments\AttachmentSubmitHandler;
use App\Services\Production\OrderAttachmentStorage;
use App\Services\Production\PdfOrderConfirmationParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class OrderImportSecurityTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }
    }

    public function testDigitallyGeneratedPdfIsParsedWithinLimits(): void
    {
        $path = $this->writeTemporaryPdf(implode("\n", [
            'BT [(Document #: ORDER-DEMO-001)] TJ ET',
            'BT [(Customer #: CUSTOMER-DEMO-01)] TJ ET',
            'BT [(Project #: PROJECT-DEMO-01)] TJ ET',
            'BT [(Date: 2026-08-14)] TJ ET',
            'BT [(Your Reference #: REFERENCE-DEMO-01)] TJ ET',
            'BT [(1 DEMO-SYSTEM-20/3 1 set)] TJ ET',
        ]));

        $result = $this->createParser()->parseFile($path);

        self::assertSame('ORDER-DEMO-001', $result['order_number']);
        self::assertSame('CUSTOMER-DEMO-01', $result['customer_number']);
        self::assertSame('PROJECT-DEMO-01', $result['project_number']);
        self::assertSame('2026-08-14', $result['order_date']);
        self::assertSame('REFERENCE-DEMO-01', $result['reference']);
        self::assertSame('DEMO-SYSTEM-20/3', $result['lines'][0]['description']);
    }

    public function testPositionedOrderConfirmationIsReconstructedInVisualOrder(): void
    {
        $path = $this->writeTemporaryPdf(implode("\n", [
            'BT 41 0 0 41 1488 2629 Tm (Document ) Tj ET',
            'BT 41 0 0 41 1661 2629 Tm (#:) Tj ET',
            'BT 41 0 0 41 1889 2629 Tm (ORDER-DEMO-002) Tj ET',
            'BT 41 0 0 41 1488 2570 Tm (Customer ) Tj ET',
            'BT 41 0 0 41 1650 2570 Tm (#:) Tj ET',
            'BT 41 0 0 41 1889 2570 Tm (CUSTOMER-DEMO-02) Tj ET',
            'BT 41 0 0 41 1488 2511 Tm (Project ) Tj ET',
            'BT 41 0 0 41 1609 2511 Tm (#:) Tj ET',
            'BT 41 0 0 41 1889 2511 Tm (PROJECT-DEMO-02) Tj ET',
            'BT 41 0 0 41 1488 2452 Tm (Date:) Tj ET',
            'BT 41 0 0 41 1889 2452 Tm (2026-) Tj ET',
            'BT 41 0 0 41 1987 2452 Tm (04-) Tj ET',
            'BT 41 0 0 41 2044 2452 Tm (22) Tj ET',
            'BT 41 0 0 41 236 2130 Tm (Your ) Tj ET',
            'BT 41 0 0 41 319 2130 Tm (Reference ) Tj ET',
            'BT 41 0 0 41 487 2130 Tm (#: ) Tj ET',
            'BT 41 0 0 41 531 2130 Tm (REFERENCE-) Tj ET',
            'BT 41 0 0 41 666 2130 Tm (DEMO-) Tj ET',
            'BT 41 0 0 41 805 2130 Tm (02 ) Tj ET',
            'BT 41 0 0 41 856 2130 Tm (from ) Tj ET',
            'BT 41 0 0 41 941 2130 Tm (2026-04-20) Tj ET',
            'BT 41 0 0 41 318 1948 Tm (description) Tj ET',
            'BT 41 0 0 41 1414 1948 Tm (# ) Tj ET',
            'BT 41 0 0 41 1437 1948 Tm (of ) Tj ET',
            'BT 41 0 0 41 1467 1948 Tm (units) Tj ET',
            'BT 41 0 0 41 1759 1948 Tm (unit ) Tj ET',
            'BT 41 0 0 41 1811 1948 Tm (price) Tj ET',
            'BT 41 0 0 41 2156 1948 Tm (amount) Tj ET',
            'BT 41 0 0 41 236 1845 Tm (1) Tj ET',
            'BT 41 0 0 41 318 1845 Tm (DEMO-) Tj ET',
            'BT 41 0 0 41 400 1845 Tm (SYSTEM-) Tj ET',
            'BT 41 0 0 41 440 1845 Tm (6/) Tj ET',
            'BT 41 0 0 41 474 1845 Tm (1) Tj ET',
            'BT 41 0 0 41 1445 1845 Tm (1 ) Tj ET',
            'BT 41 0 0 41 1475 1845 Tm (set) Tj ET',
            'BT 41 0 0 41 1660 1845 Tm (8010.00 ) Tj ET',
            'BT 41 0 0 41 1801 1845 Tm (EUR) Tj ET',
        ]));

        $result = $this->createParser()->parseFile($path);

        self::assertSame('ORDER-DEMO-002', $result['order_number']);
        self::assertSame('CUSTOMER-DEMO-02', $result['customer_number']);
        self::assertSame('PROJECT-DEMO-02', $result['project_number']);
        self::assertSame('2026-04-22', $result['order_date']);
        self::assertSame('REFERENCE-DEMO-02', $result['reference']);
        self::assertSame([['number' => 1, 'description' => 'DEMO-SYSTEM-6/1', 'quantity' => 1, 'unit' => 'set']], $result['lines']);
    }

    public function testExecutableExtensionAndIncompletePdfAreRejected(): void
    {
        $path = $this->temporaryPath('.pdf');
        file_put_contents($path, '%PDF-<?php echo "unsafe";');
        $storage = new OrderAttachmentStorage($this->createStub(AttachmentPathResolver::class), $this->createAttachmentHandler());

        foreach (['payload.php', 'payload.pdf'] as $filename) {
            try {
                $storage->validateUpload(new UploadedFile($path, $filename, null, null, true), 'payload.pdf' === $filename);
                self::fail($filename.' must be rejected.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testCompressedStreamAboveLimitIsRejected(): void
    {
        $path = $this->temporaryPath('.pdf');
        $compressed = gzcompress(str_repeat('A', 5 * 1024 * 1024));
        file_put_contents($path, "%PDF-1.4\n1 0 obj << /Filter /FlateDecode >> stream\n".$compressed."\nendstream\nendobj\n%%EOF");

        $this->expectException(\RuntimeException::class);
        $this->createParser()->parseFile($path);
    }

    private function writeTemporaryPdf(string $stream): string
    {
        $path = $this->temporaryPath('.pdf');
        file_put_contents($path, "%PDF-1.4\n1 0 obj << /Length ".strlen($stream)." >>\nstream\n".$stream."\nendstream\nendobj\ntrailer <<>>\n%%EOF");

        return $path;
    }

    private function temporaryPath(string $suffix): string
    {
        $path = tempnam(sys_get_temp_dir(), 'partdb-order-import-');
        if (false === $path) {
            throw new \RuntimeException('Could not create a temporary test file.');
        }
        $target = $path.$suffix;
        rename($path, $target);
        $this->temporaryFiles[] = $target;

        return $target;
    }

    private function createParser(): PdfOrderConfirmationParser
    {
        return new PdfOrderConfirmationParser($this->createAttachmentHandler());
    }

    private function createAttachmentHandler(): AttachmentSubmitHandler
    {
        $handler = $this->createStub(AttachmentSubmitHandler::class);
        $handler->method('getMaximumEffectiveUploadSize')->willReturn(10 * 1024 * 1024);

        return $handler;
    }
}
