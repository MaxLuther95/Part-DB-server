<?php

declare(strict_types=1);

namespace App\Services\Production;

/** Bounded decoding of the single-byte fonts used by supported order PDFs. */
final class PdfImportFontDecoder
{
    /** @return array<string, array{encoding:string,map:array<string,string>}> */
    public function readFonts(string $pdf): array
    {
        preg_match_all('/(?:^|\n)(\d+)\s+\d+\s+obj\b(.*?)endobj/s', $pdf, $matches, PREG_SET_ORDER);
        if (count($matches) > 4000) {
            throw new \RuntimeException('Die PDF enthält zu viele Objekte.');
        }
        $objects = [];
        foreach ($matches as $match) {
            $objects[(int) $match[1]] = $match[2];
        }
        preg_match_all('/\/Font\s*<<(.*?)>>/s', $pdf, $resources, PREG_SET_ORDER);
        $fonts = [];
        $references = [];
        $decodedFonts = [];
        foreach ($resources as $resource) {
            preg_match_all('/\/([A-Za-z0-9_-]+)\s+(\d+)\s+\d+\s+R/', $resource[1], $entries, PREG_SET_ORDER);
            foreach ($entries as $entry) {
                $name = $entry[1];
                $id = (int) $entry[2];
                if (isset($references[$name]) && $references[$name] !== $id) {
                    throw new \RuntimeException('Seitenabhängig wechselnde PDF-Schriftzuordnungen werden nicht unterstützt.');
                }
                if (isset($fonts[$name])) {
                    continue;
                }
                if (count($fonts) >= 128) {
                    throw new \RuntimeException('Die PDF enthält zu viele Schriftzuordnungen.');
                }
                $references[$name] = $id;
                if (isset($decodedFonts[$id])) {
                    $fonts[$name] = $decodedFonts[$id];
                    continue;
                }
                $font = $objects[$id] ?? '';
                $encoding = str_contains($font, '/MacRomanEncoding') ? 'Macintosh' : 'Windows-1252';
                $map = [];
                if (1 === preg_match('/\/ToUnicode\s+(\d+)\s+\d+\s+R/', $font, $reference)) {
                    $object = $objects[(int) $reference[1]] ?? '';
                    if (1 !== preg_match('/stream\r?\n(.*?)\r?\nendstream/s', $object, $stream)) {
                        throw new \RuntimeException('Die PDF-Schriftzuordnung kann nicht gelesen werden.');
                    }
                    $cmap = str_contains($object, '/FlateDecode') ? @gzuncompress($stream[1], 1024 * 1024) : $stream[1];
                    if (false === $cmap || strlen($cmap) > 1024 * 1024) {
                        throw new \RuntimeException('Die PDF-Schriftzuordnung überschreitet die Sicherheitsgrenze.');
                    }
                    $map = $this->readUnicodeMap($cmap);
                }
                $fonts[$name] = ['encoding' => $encoding, 'map' => $map];
                $decodedFonts[$id] = $fonts[$name];
            }
        }

        return $fonts;
    }

    /** @param array{encoding:string,map:array<string,string>} $font */
    public function decode(string $bytes, array $font): string
    {
        $result = '';
        foreach (str_split($bytes) as $byte) {
            $decoded = $font['map'][bin2hex($byte)] ?? ('Macintosh' === $font['encoding']
                ? iconv('MACINTOSH', 'UTF-8', $byte)
                : mb_convert_encoding($byte, 'UTF-8', $font['encoding']));
            if (false === $decoded) {
                throw new \RuntimeException('Ein PDF-Zeichen konnte nicht dekodiert werden.');
            }
            $result .= $decoded;
        }

        return $result;
    }

    /** @return array<string,string> */
    private function readUnicodeMap(string $cmap): array
    {
        $map = [];
        preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $blocks);
        foreach ($blocks[1] as $block) {
            preg_match_all('/<([0-9a-f]{2})>\s*<([0-9a-f]{4,32})>/i', $block, $pairs, PREG_SET_ORDER);
            foreach ($pairs as $pair) {
                if (0 !== strlen($pair[2]) % 4) {
                    throw new \RuntimeException('Die PDF enthält eine ungültige Unicode-Schriftzuordnung.');
                }
                $bytes = hex2bin($pair[2]);
                if (false !== $bytes && mb_check_encoding($bytes, 'UTF-16BE')) {
                    $map[strtolower($pair[1])] = mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');
                }
            }
        }
        preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $blocks);
        foreach ($blocks[1] as $block) {
            preg_match_all('/<([0-9a-f]{2})>\s*<([0-9a-f]{2})>\s*<([0-9a-f]{4})>/i', $block, $ranges, PREG_SET_ORDER);
            foreach ($ranges as $range) {
                $start = (int) hexdec($range[1]);
                $end = (int) hexdec($range[2]);
                $unicode = (int) hexdec($range[3]);
                for ($code = $start; $code <= $end; ++$code) {
                    $point = $unicode + $code - $start;
                    if ($point > 0xffff || ($point >= 0xd800 && $point <= 0xdfff)) {
                        throw new \RuntimeException('Die PDF enthält eine ungültige Unicode-Schriftzuordnung.');
                    }
                    $map[sprintf('%02x', $code)] = mb_chr($point, 'UTF-8');
                }
            }
        }
        if ([] === $map) {
            throw new \RuntimeException('Diese PDF-Unicode-Schriftzuordnung wird nicht unterstützt.');
        }

        return $map;
    }
}
