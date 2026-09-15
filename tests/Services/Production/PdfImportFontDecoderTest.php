<?php

declare(strict_types=1);

namespace App\Tests\Services\Production;

use App\Services\Production\PdfImportFontDecoder;
use PHPUnit\Framework\TestCase;

final class PdfImportFontDecoderTest extends TestCase
{
    public function testDeclaredEncodingAndUnicodeRanges(): void
    {
        $decoder = new PdfImportFontDecoder();
        $fonts = $decoder->readFonts($this->pdf("1 beginbfrange\n<21><21><20ac>\nendbfrange\n1 beginbfchar\n<22><03a9>\nendbfchar"));
        self::assertSame('€Ω café', $decoder->decode("!\" caf\x8e", $fonts['F1']));
        self::assertSame('ordinary !', $decoder->decode('ordinary !', ['encoding' => 'Windows-1252', 'map' => []]));
    }

    public function testExcessiveCompressedMappingIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new PdfImportFontDecoder())->readFonts($this->pdf(gzcompress(str_repeat('A', 1024 * 1024 + 1)), '/Filter /FlateDecode'));
    }

    public function testAmbiguousPageFontsAreRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new PdfImportFontDecoder())->readFonts($this->pdf("1 beginbfrange\n<21><21><20ac>\nendbfrange")."\n4 0 obj << /Font << /F1 5 0 R >> >> endobj");
    }

    public function testUnsupportedMultibyteMappingIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new PdfImportFontDecoder())->readFonts($this->pdf("1 beginbfrange\n<0021><0021><20ac>\nendbfrange"));
    }

    public function testTooManyFontsAreRejected(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj << /Font << ";
        for ($index = 0; $index < 129; ++$index) {
            $pdf .= '/F'.$index.' 2 0 R ';
        }
        $pdf .= ' >> >> endobj';
        $this->expectException(\RuntimeException::class);
        (new PdfImportFontDecoder())->readFonts($pdf);
    }

    private function pdf(string $cmap, string $filter = ''): string
    {
        return "%PDF-1.4\n1 0 obj << /Font << /F1 2 0 R >> >> endobj\n"
            ."2 0 obj << /Type /Font /Encoding /MacRomanEncoding /ToUnicode 3 0 R >> endobj\n"
            ."3 0 obj << $filter >>\nstream\n$cmap\nendstream\nendobj\n%%EOF";
    }
}
