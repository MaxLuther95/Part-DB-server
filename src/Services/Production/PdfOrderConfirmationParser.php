<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Entity\Production\OrderPositionUnit;
use App\Services\Attachments\AttachmentSubmitHandler;

final readonly class PdfOrderConfirmationParser
{
    private const MAX_STREAMS = 250;
    private const MAX_STREAM_BYTES = 4 * 1024 * 1024;
    private const MAX_EXTRACTED_TEXT_BYTES = 8 * 1024 * 1024;
    private const MAX_TEXT_BLOCKS = 4000;
    private const MAX_IMPORT_LINES = 500;

    public function __construct(private AttachmentSubmitHandler $attachmentSubmitHandler)
    {
    }

    /** @return array{order_number:string,customer_number:string,customer_name:string,project_number:string,order_date:string,reference:string,lines:list<array{number:int,description:string,quantity:int,unit:string}>,raw_text:string} */
    public function parseFile(string $path): array
    {
        $size = filesize($path);
        if (false === $size || $size < 1 || $size > $this->attachmentSubmitHandler->getMaximumEffectiveUploadSize()) {
            throw new \RuntimeException('Die PDF ist leer oder überschreitet die zulässige Größe.');
        }
        $positionedItems = [];
        $text = $this->extractTextFromSimplePdf($path, $positionedItems);
        if ('' === trim($text)) {
            throw new \RuntimeException('Die PDF enthält keinen sicher auslesbaren Text. Gescannte oder besonders kodierte PDFs werden nicht unterstützt.');
        }

        $result = $this->parseText($text);
        $layoutResult = $this->parsePositionedItems($positionedItems);
        foreach (['order_number', 'customer_number', 'project_number', 'order_date', 'reference'] as $field) {
            if ('' !== ($layoutResult[$field] ?? '')) {
                $result[$field] = $layoutResult[$field];
            }
        }
        if ([] !== ($layoutResult['lines'] ?? [])) {
            $result['lines'] = $layoutResult['lines'];
        }
        foreach (['order_number', 'customer_number', 'project_number'] as $field) {
            if (in_array(mb_strtolower($result[$field]), ['document', 'customer', 'project', 'date', 'reference'], true)) {
                $result[$field] = '';
            }
        }

        return $result;
    }

    /** @return array{order_number:string,customer_number:string,customer_name:string,project_number:string,order_date:string,reference:string,lines:list<array{number:int,description:string,quantity:int,unit:string}>,raw_text:string} */
    public function parseText(string $text): array
    {
        if (strlen($text) > self::MAX_EXTRACTED_TEXT_BYTES) {
            throw new \RuntimeException('Der ausgelesene PDF-Text überschreitet die Sicherheitsgrenze.');
        }
        $text = $this->sanitizeExtractedText(str_replace(["\r\n", "\r"], "\n", $text));
        $field = static function (string $pattern, int $maximumLength) use ($text): string {
            return 1 === preg_match($pattern, $text, $matches) ? mb_substr(trim($matches[1]), 0, $maximumLength) : '';
        };

        $lines = [];
        foreach (explode("\n", $text) as $line) {
            if (count($lines) >= self::MAX_IMPORT_LINES) {
                break;
            }
            if (1 !== preg_match('/^\s*(\d{1,6})\s+(.{1,500}?)\s+(\d{1,7})\s+(set|pcs?\.?|pieces?)\s*$/iu', trim($line), $matches)) {
                continue;
            }
            $lines[] = $this->createLine($matches);
        }
        if ([] === $lines && 0 < preg_match_all('/(?:^|\n)\s*(\d{1,6})\s*\n+\s*([^\n]{1,500}?)\s*\n+\s*(\d{1,7})\s*\n+\s*(set|pcs?\.?|pieces?)\s*(?:\n|$)/iu', $text, $matches, PREG_SET_ORDER)) {
            foreach (array_slice($matches, 0, self::MAX_IMPORT_LINES) as $match) {
                $lines[] = $this->createLine($match);
            }
        }

        return [
            'order_number' => $field('/Document\s*#?\s*:\s*([^\s]+)/iu', 64),
            'customer_number' => $field('/Customer\s*#?\s*:\s*([^\s]+)/iu', 64),
            'customer_name' => $field('/Customer\s+Name\s*:\s*([^\n]+)/iu', 255),
            'project_number' => $field('/Project\s*#?\s*:\s*([^\s]+)/iu', 64),
            'order_date' => $field('/Date\s*:\s*(\d{4}-\d{2}-\d{2})/iu', 10),
            'reference' => $field('/Your\s+Reference\s*#?\s*:\s*([^\s]+)/iu', 255),
            'lines' => $lines,
            'raw_text' => $text,
        ];
    }

    /** @param list<array{stream:int,x:float,y:float,text:string}> $positionedItems */
    private function extractTextFromSimplePdf(string $path, array &$positionedItems): string
    {
        $maximumBytes = $this->attachmentSubmitHandler->getMaximumEffectiveUploadSize();
        $pdf = file_get_contents($path, false, null, 0, $maximumBytes + 1);
        if (false === $pdf || strlen($pdf) > $maximumBytes || !str_starts_with($pdf, '%PDF-')) {
            throw new \RuntimeException('Die hochgeladene Datei ist keine gültige PDF-Datei.');
        }

        $text = [];
        $textBytes = 0;
        $blockCount = 0;
        if (1 > preg_match_all('/stream\r?\n(?<stream>.*?)\r?\nendstream/s', $pdf, $streams, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return '';
        }
        if (count($streams) > self::MAX_STREAMS) {
            throw new \RuntimeException('Die PDF ist für den sicheren Import zu komplex.');
        }
        foreach ($streams as $streamIndex => $stream) {
            $content = $stream['stream'][0];
            if (strlen($content) > self::MAX_STREAM_BYTES) {
                throw new \RuntimeException('Ein PDF-Datenstrom überschreitet die Sicherheitsgrenze.');
            }
            $streamOffset = $stream['stream'][1];
            $dictionary = substr($pdf, max(0, $streamOffset - 1000), min(1000, $streamOffset));
            if (str_contains($dictionary, '/FlateDecode')) {
                $inflated = @gzuncompress($content, self::MAX_STREAM_BYTES);
                if (false === $inflated) {
                    $inflated = @gzinflate($content, self::MAX_STREAM_BYTES);
                }
                if (false === $inflated) {
                    continue;
                }
                $content = $inflated;
            }
            if (!str_contains($content, 'BT')) {
                continue;
            }
            if (1 > preg_match_all('/BT(?<block>.*?)ET/s', $content, $blocks, PREG_SET_ORDER)) {
                continue;
            }
            $blockCount += count($blocks);
            if ($blockCount > self::MAX_TEXT_BLOCKS) {
                throw new \RuntimeException('Die PDF enthält zu viele Textblöcke für den sicheren Import.');
            }
            foreach ($blocks as $block) {
                if (1 > preg_match_all('/\((?<literal>(?:\\\\.|[^\\\\)])*)\)|<(?<hex>[0-9A-Fa-f\s]+)>/', $block['block'], $strings, PREG_SET_ORDER)) {
                    continue;
                }
                $blockText = '';
                foreach ($strings as $string) {
                    $value = '' !== ($string['literal'] ?? '')
                        ? $this->decodePdfLiteral($string['literal'])
                        : $this->decodePdfHex($string['hex'] ?? '');
                    $value = $this->sanitizeExtractedText($value);
                    $blockText .= $value;
                    $trimmedValue = trim($value);
                    if ('' === $trimmedValue) {
                        continue;
                    }
                    $textBytes += strlen($trimmedValue) + 1;
                    if ($textBytes > self::MAX_EXTRACTED_TEXT_BYTES) {
                        throw new \RuntimeException('Der ausgelesene PDF-Text überschreitet die Sicherheitsgrenze.');
                    }
                    $text[] = $trimmedValue;
                }
                if ('' !== trim($blockText) && 1 === preg_match('/[-0-9.]+\s+[-0-9.]+\s+[-0-9.]+\s+[-0-9.]+\s+(?<x>[-0-9.]+)\s+(?<y>[-0-9.]+)\s+Tm/', $block['block'], $position)) {
                    $positionedItems[] = [
                        'stream' => (int) $streamIndex,
                        'x' => (float) $position['x'],
                        'y' => (float) $position['y'],
                        'text' => $blockText,
                    ];
                }
            }
        }

        return implode("\n", $text);
    }

    /**
     * @param list<array{stream:int,x:float,y:float,text:string}> $items
     * @return array{order_number:string,customer_number:string,project_number:string,order_date:string,reference:string,lines:list<array{number:int,description:string,quantity:int,unit:string}>}
     */
    private function parsePositionedItems(array $items): array
    {
        $result = [
            'order_number' => '',
            'customer_number' => '',
            'project_number' => '',
            'order_date' => '',
            'reference' => '',
            'lines' => [],
        ];
        if ([] === $items) {
            return $result;
        }

        $itemsByStream = [];
        foreach ($items as $item) {
            $itemsByStream[$item['stream']][] = $item;
        }
        $allLineTexts = [];
        foreach ($itemsByStream as $streamItems) {
            $lines = $this->groupPositionedItemsIntoLines($streamItems);
            foreach ($lines as $line) {
                $allLineTexts[] = $this->joinPositionedText($line['items']);
            }
            foreach ($this->extractPositionLines($lines) as $position) {
                if (count($result['lines']) >= self::MAX_IMPORT_LINES) {
                    break 2;
                }
                $result['lines'][] = $position;
            }
        }

        $layoutText = implode("\n", $allLineTexts);
        $field = static function (string $pattern, int $maximumLength) use ($layoutText): string {
            return 1 === preg_match($pattern, $layoutText, $matches) ? mb_substr(trim($matches[1]), 0, $maximumLength) : '';
        };
        $result['order_number'] = $field('/Document[ \t]*#?[ \t]*:[ \t]*([A-Z0-9][A-Z0-9._\/-]{0,63})/iu', 64);
        $result['customer_number'] = $field('/Customer[ \t]*#?[ \t]*:[ \t]*([A-Z0-9][A-Z0-9._\/-]{0,63})/iu', 64);
        $result['project_number'] = $field('/Project[ \t]*#?[ \t]*:[ \t]*([A-Z0-9][A-Z0-9._\/-]{0,63})/iu', 64);
        $result['order_date'] = $field('/Date[ \t]*:[ \t]*(\d{4}-\d{2}-\d{2})/iu', 10);
        $result['reference'] = $field('/Your[ \t]+Reference[ \t]*#?[ \t]*:[ \t]*(.+?)[ \t]+from[ \t]+\d{4}-\d{2}-\d{2}/iu', 255);

        return $result;
    }

    /**
     * @param list<array{stream:int,x:float,y:float,text:string}> $items
     * @return list<array{y:float,items:list<array{stream:int,x:float,y:float,text:string}>}>
     */
    private function groupPositionedItemsIntoLines(array $items): array
    {
        usort($items, static fn(array $left, array $right): int => $right['y'] <=> $left['y'] ?: $left['x'] <=> $right['x']);
        $lines = [];
        foreach ($items as $item) {
            $lastIndex = array_key_last($lines);
            if (null === $lastIndex || abs($lines[$lastIndex]['y'] - $item['y']) > 2.0) {
                $lines[] = ['y' => $item['y'], 'items' => [$item]];
                continue;
            }
            $lines[$lastIndex]['items'][] = $item;
        }
        foreach ($lines as &$line) {
            usort($line['items'], static fn(array $left, array $right): int => $left['x'] <=> $right['x']);
        }
        unset($line);

        return $lines;
    }

    /**
     * @param list<array{y:float,items:list<array{stream:int,x:float,y:float,text:string}>}> $lines
     * @return list<array{number:int,description:string,quantity:int,unit:string}>
     */
    private function extractPositionLines(array $lines): array
    {
        $headerIndex = null;
        $descriptionX = null;
        $quantityX = null;
        $unitPriceX = null;
        foreach ($lines as $index => $line) {
            $lineText = mb_strtolower($this->joinPositionedText($line['items']));
            if (!str_contains($lineText, 'description') || !str_contains($lineText, 'of units')) {
                continue;
            }
            foreach ($line['items'] as $item) {
                $text = mb_strtolower(trim($item['text']));
                if ('description' === $text) {
                    $descriptionX = $item['x'];
                } elseif ('#' === $text) {
                    $quantityX = $item['x'];
                } elseif ('unit' === $text) {
                    $unitPriceX = $item['x'];
                }
            }
            if (null !== $descriptionX && null !== $quantityX && null !== $unitPriceX) {
                $headerIndex = $index;
                break;
            }
        }
        if (null === $headerIndex || null === $descriptionX || null === $quantityX || null === $unitPriceX) {
            return [];
        }

        $result = [];
        $quantityEndX = ($quantityX + $unitPriceX) / 2;
        foreach (array_slice($lines, $headerIndex + 1) as $line) {
            $fullText = mb_strtolower($this->joinPositionedText($line['items']));
            if (str_contains($fullText, 'total amount')) {
                break;
            }
            $numberItems = array_values(array_filter($line['items'], static fn(array $item): bool => $item['x'] < $descriptionX - 20));
            $descriptionItems = array_values(array_filter($line['items'], static fn(array $item): bool => $item['x'] >= $descriptionX - 5 && $item['x'] < $quantityX - 20));
            $quantityItems = array_values(array_filter($line['items'], static fn(array $item): bool => $item['x'] >= $quantityX - 20 && $item['x'] < $quantityEndX));
            $numberText = trim($this->joinPositionedText($numberItems));
            $description = trim($this->joinPositionedText($descriptionItems));
            $quantityText = trim($this->joinPositionedText($quantityItems));
            if (1 !== preg_match('/^\d{1,6}$/', $numberText) || '' === $description || 1 !== preg_match('/^(\d{1,7})\s*(set|pcs?\.?|pieces?)$/iu', $quantityText, $quantityMatch)) {
                continue;
            }
            $result[] = [
                'number' => (int) $numberText,
                'description' => mb_substr($description, 0, 255),
                'quantity' => (int) $quantityMatch[1],
                'unit' => $this->normalizeUnit($quantityMatch[2]),
            ];
        }

        return $result;
    }

    /** @param list<array{stream:int,x:float,y:float,text:string}> $items */
    private function joinPositionedText(array $items): string
    {
        $text = implode('', array_column($items, 'text'));

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** @param array<int|string, string> $matches @return array{number:int,description:string,quantity:int,unit:string} */
    private function createLine(array $matches): array
    {
        return [
            'number' => (int) $matches[1],
            'description' => mb_substr(trim($matches[2]), 0, 255),
            'quantity' => (int) $matches[3],
            'unit' => $this->normalizeUnit($matches[4]),
        ];
    }

    private function normalizeUnit(string $unit): string
    {
        return (OrderPositionUnit::fromImportedValue($unit) ?? OrderPositionUnit::Piece)->value;
    }

    private function sanitizeExtractedText(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    }

    private function decodePdfLiteral(string $value): string
    {
        $value = preg_replace_callback('/\\\\([0-7]{1,3})/', static fn(array $match): string => chr(octdec($match[1])), $value) ?? $value;

        return strtr($value, [
            '\\n' => "\n",
            '\\r' => "\r",
            '\\t' => "\t",
            '\\b' => "\x08",
            '\\f' => "\x0c",
            '\\(' => '(',
            '\\)' => ')',
            '\\\\' => '\\',
        ]);
    }

    private function decodePdfHex(string $value): string
    {
        $value = preg_replace('/\s+/', '', $value) ?? '';
        if ('' === $value) {
            return '';
        }
        if (1 === strlen($value) % 2) {
            $value .= '0';
        }
        $decoded = hex2bin($value);
        if (false === $decoded) {
            return '';
        }
        if (str_starts_with($decoded, "\xFE\xFF")) {
            return mb_convert_encoding(substr($decoded, 2), 'UTF-8', 'UTF-16BE');
        }

        return $decoded;
    }
}
