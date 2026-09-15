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

    public function testGermanColumnsKeepVatOutOfQuantityAndFooterOutOfNotes(): void
    {
        $rows = [
            [1200, 2700, 'Dokument-Nr.: DEMO-DE-01'],
            [1200, 2640, 'Kunden-Nr.:'],
            [1200, 2580, 'Projekt-Nr.: PROJECT-DEMO-DE'],
            [1200, 2520, 'Datum: 15.09.2026'],
            [200, 2300, 'Ihre Referenz-Nr.: REF DEMO 01 von 14.09.2026'],
            [300, 2000, 'Bezeichnung'], [1200, 2000, 'Menge'],
            [1300, 2000, 'Einh.'], [1450, 2000, 'MwSt.'], [1740, 2000, 'Einzelpreis'],
            [200, 1900, '1'], [300, 1900, 'Synthetische Dienstleistung'],
            [1260, 1900, '1 '], [1300, 1900, 'psch'], [1520, 1900, '2'], [1680, 1900, '100,00 EUR'],
            [200, 1800, '2'], [300, 1800, 'Synthetisches Bauteil'],
            [1260, 1800, '3 '], [1300, 1800, 'Stk.'], [1520, 1800, '2'],
            [1600, 1600, 'Gesamtbetrag'], [1900, 1600, '119,00 EUR'],
            [200, 1500, 'Zahlbar nach Lieferung.'],
            [200, 1400, 'Weitere synthetische Lieferbedingung.'],
            [200, 200, 'Beispielunternehmen '], [1700, 200, 'Sitz: Beispielstadt'],
            [200, 150, 'Diese Fusszeile ist keine Notiz.'],
        ];
        $stream = implode("\n", array_map(static fn(array $row): string => sprintf('BT 1 0 0 1 %d %d Tm (%s) Tj ET', ...$row), $rows));
        $result = $this->createParser()->parseFile($this->writeTemporaryPdf($stream));
        self::assertSame('DEMO-DE-01', $result['order_number']);
        self::assertSame('', $result['customer_number']);
        self::assertSame('PROJECT-DEMO-DE', $result['project_number']);
        self::assertSame('2026-09-15', $result['order_date']);
        self::assertSame('REF DEMO 01', $result['reference']);
        self::assertSame([1, 3], array_column($result['lines'], 'quantity'));
        self::assertSame(['psch', 'pcs.'], array_column($result['lines'], 'unit'));
        self::assertSame("Zahlbar nach Lieferung.\nWeitere synthetische Lieferbedingung.", $result['notes']);
    }

    public function testGermanTextAndMultiwordReferencesUseSharedHeaderRules(): void
    {
        $result = $this->createParser()->parseText("Dokument-Nr.: DEMO-DE-02\nKunden-Nr.: DEMO-C\nKundenname: Beispielkunde\nProjekt-Nr.: DEMO-P\nDatum: 15.09.2026\nIhre Referenz-Nr.: REF ZWEI WORTE vom 01.09.2026\n1 Synthetisches Geraet 2 Stu\u{0308}ck\n2 Dienstleistung 1 pauschal\nGesamtbetrag 120,00 EUR\nEine Liefernotiz.\nUStID.: SYNTHETISCH");
        self::assertSame('DEMO-C', $result['customer_number']);
        self::assertSame('Beispielkunde', $result['customer_name']);
        self::assertSame('REF ZWEI WORTE', $result['reference']);
        self::assertSame('2026-09-15', $result['order_date']);
        self::assertSame(['pcs.', 'psch'], array_column($result['lines'], 'unit'));
        self::assertSame('Eine Liefernotiz.', $result['notes']);
        self::assertSame('', $this->createParser()->parseText("Customer #:\nProject #: SYNTHETIC-P\nDate: 2026-09-15")['customer_number']);
        self::assertSame('', $this->createParser()->parseText('Datum: 31.02.2026')['order_date']);
        self::assertSame('REF TWO WORDS', $this->createParser()->parseText('Your Reference #: REF TWO WORDS from 2026-09-15')['reference']);
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

    public function testNotesStartAfterTotalAndContinueOnNextPageWithoutFooter(): void
    {
        $path = $this->writeTemporaryPdf(implode("\n", [
            'BT 1 0 0 1 10 500 Tm (Document #: ORDER-NOTES) Tj ET',
            'BT 1 0 0 1 10 450 Tm (Your Reference #: REF-NOTES from 2026-09-15) Tj ET',
            'BT 1 0 0 1 10 300 Tm (total amount) Tj ET',
            'BT 1 0 0 1 200 300 Tm (100.00 EUR) Tj ET',
            'BT 1 0 0 1 10 200 Tm (First delivery condition.) Tj ET',
            'BT 1 0 0 1 10 170 Tm (Payment in thirty days.) Tj ET',
            'BT 1 0 0 1 10 70 Tm (Example company) Tj ET',
            'BT 1 0 0 1 200 70 Tm (Bank name: Example bank) Tj ET',
            'BT 1 0 0 1 10 50 Tm (IBAN: SYNTHETIC) Tj ET',
        ])."\nendstream\nendobj\n2 0 obj <<>>\nstream\n".implode("\n", [
            'BT 1 0 0 1 10 700 Tm (page 2 regarding ORDER-NOTES) Tj ET',
            'BT 1 0 0 1 10 600 Tm (Delivery follows approval.) Tj ET',
            'BT 1 0 0 1 10 560 Tm (Thank you for your order.) Tj ET',
        ]));

        $result = $this->createParser()->parseFile($path);

        self::assertSame('REF-NOTES', $result['reference']);
        self::assertSame("First delivery condition.\nPayment in thirty days.\nDelivery follows approval.\nThank you for your order.", $result['notes']);
    }

    public function testPlainTextNotesAndMissingTotal(): void
    {
        $parser = $this->createParser();
        self::assertSame('', $parser->parseText("Document #: DEMO\nNo total or notes here.")['notes']);
        self::assertSame("Deliver in two batches.\nHandle with care.", $parser->parseText("Gesamtbetrag\n100,00 EUR\nDeliver in two batches.\nHandle with care.\nIBAN: SYNTHETIC")['notes']);
    }

    public function testExcessiveNotesAreRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->createParser()->parseText("total amount 10 EUR\n".str_repeat('A', 50001));
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
