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
    private const UNIT_PATTERN = '(?:sets?|pcs?\.?|pieces?|stk\.?|stück|stueck|psch\.?|pauschal)';
    private const DATE_PATTERN = '(?:\d{4}-\d{2}-\d{2}|\d{2}\.\d{2}\.\d{4})';
    private const HEADER_LABELS = [
        'order_number' => '(?:Document[ \t]*#?|(?:Dokument|Auftrags?)(?:[- \t]*(?:Nr\.?|Nummer))?)',
        'customer_number' => '(?:Customer[ \t]*#?|Kunden(?:[- \t]*(?:Nr\.?|Nummer))?)',
        'customer_name' => '(?:Customer[ \t]+Name|Kundenname)',
        'project_number' => '(?:Project[ \t]*#?|Projekt(?:[- \t]*(?:Nr\.?|Nummer))?)',
        'order_date' => '(?:Date|Datum)',
        'reference' => '(?:Your[ \t]+Reference[ \t]*#?|Ihre[ \t]+Referenz(?:[- \t]*(?:Nr\.?|Nummer))?)',
    ];

    public function __construct(private AttachmentSubmitHandler $attachmentSubmitHandler)
    {
    }

    /** @return array{order_number:string,customer_number:string,customer_name:string,project_number:string,order_date:string,reference:string,notes:string,lines:list<array{number:int,description:string,notes:string,quantity:int,unit:string}>,raw_text:string} */
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
        foreach (array_keys(self::HEADER_LABELS) as $field) {
            // An explicitly empty visual field must override incidental raw-stream text.
            if (array_key_exists($field, $layoutResult)) {
                $result[$field] = $layoutResult[$field];
            }
        }
        if ('' !== ($layoutResult['notes'] ?? '')) {
            $result['notes'] = $layoutResult['notes'];
        }
        if ([] !== ($layoutResult['lines'] ?? [])) {
            $result['lines'] = $layoutResult['lines'];
        }

        return $result;
    }

    /** @return array{order_number:string,customer_number:string,customer_name:string,project_number:string,order_date:string,reference:string,notes:string,lines:list<array{number:int,description:string,notes:string,quantity:int,unit:string}>,raw_text:string} */
    public function parseText(string $text): array
    {
        if (strlen($text) > self::MAX_EXTRACTED_TEXT_BYTES) {
            throw new \RuntimeException('Der ausgelesene PDF-Text überschreitet die Sicherheitsgrenze.');
        }
        $text = $this->sanitizeExtractedText(str_replace(["\r\n", "\r"], "\n", $text));
        $body = [];
        foreach (explode("\n", $text) as $row) {
            if ($this->isTotalLine(trim($row))) {
                break;
            }
            $body[] = $row;
        }
        $positionText = implode("\n", $body);
        $lines = [];
        $pattern = '/^\h*(\d{1,6})\h+([^\r\n]{1,500}?)\h+(\d{1,7})\h+('.self::UNIT_PATTERN.')\h*$/imu';
        preg_match_all($pattern, $positionText, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if ([] === $matches) {
            $pattern = '/^\h*(\d{1,6})\h*\n+\h*([^\n]{1,500}?)\h*\n+\h*(\d{1,7})\h*\n+\h*('.self::UNIT_PATTERN.')\h*$/imu';
            preg_match_all($pattern, $positionText, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        }
        if (count($matches) > self::MAX_IMPORT_LINES) {
            throw new \RuntimeException('Die PDF enthält zu viele Auftragspositionen.');
        }
        foreach ($matches as $index => $match) {
            $line = $this->createLine(array_column($match, 0));
            $start = $match[0][1] + strlen($match[0][0]);
            $end = $matches[$index + 1][0][1] ?? strlen($positionText);
            foreach (explode("\n", substr($text, $start, $end - $start)) as $detail) {
                $detail = trim($detail);
                if ($this->isTotalLine($detail) || $this->isFooterLine($detail) || $this->isSubtotalLine($detail)) {
                    break;
                }
                if ('' === $detail || $this->isPageHeaderLine($detail) || $this->isPriceLine($detail)) {
                    continue;
                }
                $this->appendPositionNote($line, $detail);
            }
            $lines[] = $line;
        }

        return array_replace(array_fill_keys(array_keys(self::HEADER_LABELS), ''), $this->parseHeaderFields($text), [
            'notes' => $this->extractNotes([explode("\n", $text)]),
            'lines' => $lines,
            'raw_text' => $text,
        ]);
    }

    /** @return array<string, string> Only labels actually found in the text are returned. */
    private function parseHeaderFields(string $text): array
    {
        $result = [];
        foreach (self::HEADER_LABELS as $field => $label) {
            if (1 !== preg_match('/(?<![\p{L}\p{N}])'.$label.'[ \t]*:[ \t]*([^\r\n]*)/iu', $text, $match)) {
                continue;
            }
            $value = trim($match[1]);
            if (in_array($field, ['order_number', 'customer_number', 'project_number'], true)) {
                $value = 1 === preg_match('/^([A-Z0-9][A-Z0-9._\/-]{0,63})(?=\s|$)/iu', $value, $identifier) ? $identifier[1] : '';
            } elseif ('order_date' === $field) {
                $value = $this->normalizeDate($value);
            } elseif ('reference' === $field) {
                $value = preg_replace('/[ \t]+(?:from|von|vom)[ \t]+'.self::DATE_PATTERN.'[ \t]*$/iu', '', $value) ?? $value;
            }
            $result[$field] = mb_substr($value, 0, in_array($field, ['reference', 'customer_name'], true) ? 255 : 64);
        }

        return $result;
    }

    private function normalizeDate(string $value): string
    {
        foreach (['Y-m-d', 'd.m.Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $value);
            if (false !== $date && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return '';
    }

    /** @param list<array{stream:int,x:float,y:float,text:string}> $positionedItems */
    private function extractTextFromSimplePdf(string $path, array &$positionedItems): string
    {
        $maximumBytes = $this->attachmentSubmitHandler->getMaximumEffectiveUploadSize();
        $pdf = file_get_contents($path, false, null, 0, $maximumBytes + 1);
        if (false === $pdf || strlen($pdf) > $maximumBytes || !str_starts_with($pdf, '%PDF-')) {
            throw new \RuntimeException('Die hochgeladene Datei ist keine gültige PDF-Datei.');
        }

        $fontDecoder = new PdfImportFontDecoder();
        $fonts = $fontDecoder->readFonts($pdf);

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
            // Literal strings may themselves contain BT/ET. Consume them as tokens
            // before looking for the text object's closing operator.
            $matched = preg_match_all('/\bBT(?<block>(?:\((?:\\\\.|[^\\\\)])*\)|(?:(?!\bET\b)[^(]))*)\bET/s', $content, $blocks, PREG_SET_ORDER);
            if (false === $matched) {
                throw new \RuntimeException('Die PDF-Textblöcke konnten nicht sicher getrennt werden.');
            }
            if (0 === $matched) {
                continue;
            }
            $blockCount += count($blocks);
            if ($blockCount > self::MAX_TEXT_BLOCKS) {
                throw new \RuntimeException('Die PDF enthält zu viele Textblöcke für den sicheren Import.');
            }
            foreach ($blocks as $block) {
                $font = null;
                if (1 === preg_match('/\/([A-Za-z0-9_-]+)\s+[-\d.]+\s+Tf/', $block['block'], $fontMatch)) {
                    $font = $fonts[$fontMatch[1]] ?? null;
                }
                if (1 > preg_match_all('/\((?<literal>(?:\\\\.|[^\\\\)])*)\)|<(?<hex>[0-9A-Fa-f\s]+)>/', $block['block'], $strings, PREG_SET_ORDER)) {
                    continue;
                }
                $blockText = '';
                foreach ($strings as $string) {
                    $value = '' !== ($string['literal'] ?? '')
                        ? $this->decodePdfLiteral($string['literal'])
                        : $this->decodePdfHex($string['hex'] ?? '');
                    if (null !== $font && 1 !== preg_match('/^\s*fe\s*ff/i', $string['hex'] ?? '')) {
                        $value = $fontDecoder->decode($value, $font);
                    }
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
     * @return array{order_number?:string,customer_number?:string,customer_name?:string,project_number?:string,order_date?:string,reference?:string,notes:string,lines:list<array{number:int,description:string,notes:string,quantity:int,unit:string}>}
     */
    private function parsePositionedItems(array $items): array
    {
        $result = ['notes' => '', 'lines' => []];
        if ([] === $items) {
            return $result;
        }

        $itemsByStream = [];
        foreach ($items as $item) {
            $itemsByStream[$item['stream']][] = $item;
        }
        $allLineTexts = [];
        $pages = [];
        $positionPages = [];
        foreach ($itemsByStream as $streamItems) {
            $lines = $this->groupPositionedItemsIntoLines($streamItems);
            $positionPages[] = $lines;
            $page = [];
            foreach ($lines as $line) {
                $page[] = $this->joinPositionedText($line['items']);
            }
            $pages[] = $page;
            array_push($allLineTexts, ...$page);

        }

        $result['lines'] = $this->extractPositionLines($positionPages);
        $layoutText = implode("\n", $allLineTexts);
        $result = array_merge($result, $this->parseHeaderFields($layoutText));
        $result['notes'] = $this->extractNotes($pages);

        return $result;
    }

    /** @param list<list<string>> $pages */
    private function extractNotes(array $pages): string
    {
        $afterTotal = false;
        $notes = [];
        foreach ($pages as $page) {
            foreach ($page as $line) {
                $line = trim($line);
                if ($this->isTotalLine($line)) {
                    $afterTotal = true;
                    continue;
                }
                if (!$afterTotal || '' === $line || 1 === preg_match('/^(?:page|seite)\s+\d+/iu', $line)) {
                    continue;
                }
                // Footer columns are not order notes. Continue on the next page.
                if ($this->isFooterLine($line)) {
                    break;
                }
                if ([] === $notes && 1 === preg_match('/^(?:(?:EUR|USD|GBP|CHF|€|\$)\s*)?[\d.,\s]+\s*(?:EUR|USD|GBP|CHF|€|\$)?$/u', $line)) {
                    continue;
                }
                $notes[] = $line;
            }
        }
        $text = implode("\n", $notes);
        if (mb_strlen($text) > 50000) {
            throw new \RuntimeException('Die erkannten Auftragsnotizen überschreiten die zulässige Länge von 50.000 Zeichen.');
        }

        return $text;
    }

    private function isTotalLine(string $line): bool
    {
        return 1 === preg_match('/^(?:total\s+amount|grand\s+total|gesamtbetrag|gesamtsumme|endbetrag)(?=[\s:\d€$]|$)/iu', $line);
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
     * @param list<list<array{y:float,items:list<array{stream:int,x:float,y:float,text:string}>}>> $pages
     * @return list<array{number:int,description:string,notes:string,quantity:int,unit:string}>
     */
    private function extractPositionLines(array $pages): array
    {
        $result = [];
        $columns = null;
        foreach ($pages as $lines) {
            $header = $this->findPositionColumns($lines);
            if (null !== $header) {
                $columns = $header;
                $lines = array_slice($lines, $header['index'] + 1);
            }
            if (null === $columns) {
                continue;
            }
            $descriptionX = $columns['description'];
            $quantityX = $columns['quantity'];
            $quantityEndX = $columns['quantity_end'];
            foreach ($lines as $line) {
                $fullText = $this->joinPositionedText($line['items']);
                if ($this->isTotalLine($fullText)) {
                    return $result;
                }
                if ($this->isFooterLine($fullText) || $this->isSubtotalLine($fullText)) {
                    break; // A position may continue on the next page.
                }
                if ($this->isPageHeaderLine($fullText)) {
                    continue;
                }
                $numberItems = array_values(array_filter($line['items'], static fn(array $item): bool => $item['x'] < $descriptionX - 20));
                $descriptionItems = array_values(array_filter($line['items'], static fn(array $item): bool => $item['x'] >= $descriptionX - 5 && $item['x'] < $quantityX - 20));
                $quantityItems = array_values(array_filter($line['items'], static fn(array $item): bool => $item['x'] >= $quantityX - 20 && $item['x'] < $quantityEndX));
                $numberText = $this->joinPositionedText($numberItems);
                $description = $this->joinPositionedText($descriptionItems);
                $quantityText = $this->joinPositionedText($quantityItems);
                if (1 === preg_match('/^\d{1,6}$/', $numberText) && '' !== $description && 1 === preg_match('/^(\d{1,7})\s*('.self::UNIT_PATTERN.')$/iu', $quantityText, $quantityMatch)) {
                    if (count($result) >= self::MAX_IMPORT_LINES) {
                        throw new \RuntimeException('Die PDF enthält zu viele Auftragspositionen.');
                    }
                    $result[] = [
                        'number' => (int) $numberText,
                        'description' => mb_substr($description, 0, 255),
                        'notes' => '',
                        'quantity' => (int) $quantityMatch[1],
                        'unit' => $this->normalizeUnit($quantityMatch[2]),
                    ];
                    continue;
                }
                if (1 === preg_match('/^\d{1,6}$/', $numberText) && '' !== $description) {
                    throw new \RuntimeException('Eine PDF-Position enthält keine sicher erkennbare Anzahl oder Einheit.');
                }
                // Continuation text starts in the description column. Do not absorb
                // prices, tax columns, or an unrecognized numbered position.
                $last = array_key_last($result);
                $firstX = $line['items'][0]['x'];
                if (null !== $last && '' === $numberText && '' !== $description
                    && $firstX >= $descriptionX - 5 && $firstX <= $descriptionX + 20) {
                    $this->appendPositionNote($result[$last], $fullText);
                }
            }
        }

        return $result;
    }

    /**
     * @param list<array{y:float,items:list<array{stream:int,x:float,y:float,text:string}>}> $lines
     * @return array{index:int,description:float,quantity:float,quantity_end:float}|null
     */
    private function findPositionColumns(array $lines): ?array
    {
        $headerIndex = null;
        $descriptionX = null;
        $quantityX = null;
        $unitX = null;
        $unitPriceX = null;
        foreach ($lines as $index => $line) {
            $lineText = mb_strtolower($this->joinPositionedText($line['items']));
            if ((!str_contains($lineText, 'description') || !str_contains($lineText, 'of units'))
                && (!str_contains($lineText, 'bezeichnung') || !str_contains($lineText, 'menge'))) {
                continue;
            }
            $descriptionX = $quantityX = $unitX = $unitPriceX = null;
            foreach ($line['items'] as $item) {
                $text = mb_strtolower(trim($item['text']));
                if (in_array($text, ['description', 'bezeichnung'], true)) {
                    $descriptionX = $item['x'];
                } elseif (in_array($text, ['#', '# of units', 'menge'], true)) {
                    $quantityX = $item['x'];
                } elseif ('einh.' === $text) {
                    $unitX = $item['x'];
                } elseif (in_array($text, ['unit', 'unit price', 'mwst.', 'einzelpreis'], true)) {
                    $unitPriceX = null === $unitPriceX ? $item['x'] : min($unitPriceX, $item['x']);
                }
            }
            if (null !== $descriptionX && null !== $quantityX && null !== $unitPriceX) {
                $headerIndex = $index;
                break;
            }
        }
        if (null === $headerIndex || null === $descriptionX || null === $quantityX || null === $unitPriceX) {
            return null;
        }

        return ['index' => $headerIndex, 'description' => $descriptionX, 'quantity' => $quantityX,
            'quantity_end' => (($unitX ?? $quantityX) + $unitPriceX) / 2];
    }

    /** @param array{number:int,description:string,notes:string,quantity:int,unit:string} $line */
    private function appendPositionNote(array &$line, string $text): void
    {
        $notes = '' === $line['notes'] ? $text : $line['notes']."\n".$text;
        if (mb_strlen($notes) > 50000) {
            throw new \RuntimeException('Die erkannten Positionsnotizen überschreiten die zulässige Länge von 50.000 Zeichen.');
        }
        $line['notes'] = $notes;
    }

    private function isFooterLine(string $line): bool
    {
        return 1 === preg_match('/(?:IBAN|BIC|VAT\s+ID|Bank\s+name|Registered\s+Seat|Handelsregister|Geschäftsführer|Sitz|USt\.?[ \t]*ID\.?|BLZ|Kto\.?[ \t]*Nr\.?)\s*:/iu', $line);
    }

    private function isPageHeaderLine(string $line): bool
    {
        return 1 === preg_match('/^(?:page|seite)\s+\d+|^(?:description.*of units|bezeichnung.*menge)/iu', $line)
            || [] !== $this->parseHeaderFields($line);
    }

    private function isSubtotalLine(string $line): bool
    {
        return 1 === preg_match('/^(?:subtotal|net\s+(?:amount|total)|zwischensumme|nettobetrag|nettosumme|summe\s+netto|(?:plus|zzgl\.?)\s+(?:VAT|MwSt\.?))(?=[\s:\d€$]|$)/iu', $line);
    }

    private function isPriceLine(string $line): bool
    {
        return 1 === preg_match('/^(?:(?:EUR|USD|GBP|CHF|€|\$)\s*)?[\d.,\s]+\s*(?:EUR|USD|GBP|CHF|€|\$)?$/u', $line);
    }

    /** @param list<array{stream:int,x:float,y:float,text:string}> $items */
    private function joinPositionedText(array $items): string
    {
        $text = implode('', array_column($items, 'text'));

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** @param array<int|string, string> $matches @return array{number:int,description:string,notes:string,quantity:int,unit:string} */
    private function createLine(array $matches): array
    {
        return [
            'number' => (int) $matches[1],
            'description' => mb_substr(trim($matches[2]), 0, 255),
            'notes' => '',
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

        $value = \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;

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
