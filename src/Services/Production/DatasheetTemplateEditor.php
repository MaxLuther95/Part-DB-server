<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Entity\Production\DatasheetBlockType;
use App\Entity\Production\DatasheetFontFamily;
use App\Entity\Production\DatasheetTableColumn;
use App\Entity\Production\DatasheetTemplateBlock;
use App\Entity\Production\DatasheetTemplateRevision;
use App\Entity\Production\DatasheetTextAlignment;
use App\Entity\Production\DatasheetTextSize;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The editor deliberately exchanges a small, versioned document schema instead
 * of posting browser-generated HTML. Every value is normalized and checked
 * again before it reaches the entities or the PDF renderer.
 *
 * @phpstan-type EditorRow array{
 *     key: ?string,
 *     label: string,
 *     sourcePath: ?string,
 *     unit: string,
 *     required: bool
 * }
 * @phpstan-type EditorBlock array{
 *     key: ?string,
 *     type: string,
 *     label: string,
 *     text: string,
 *     sourcePath: ?string,
 *     textSize: string,
 *     fontFamily: string,
 *     textAlignment: string,
 *     textBold: bool,
 *     textItalic: bool,
 *     textUnderlined: bool,
 *     layoutColumns: int,
 *     startNewRow: bool,
 *     required: bool,
 *     hideIfEmpty: bool,
 *     minimumRows: int,
 *     maximumRows: ?int,
 *     columns: list<EditorRow>
 * }
 * @phpstan-type EditorPayload array{
 *     schemaVersion: int,
 *     baseRevisionHash: string,
 *     productTitle: string,
 *     changeNote: string,
 *     blocks: list<EditorBlock>
 * }
 */
final readonly class DatasheetTemplateEditor
{
    public const SCHEMA_VERSION = 1;

    private const MAX_BLOCKS = 100;
    private const MAX_TABLE_ROWS = 100;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private DatasheetSourceCatalog $sourceCatalog,
    ) {
    }

    /**
     * @return EditorPayload
     */
    public function export(DatasheetTemplateRevision $revision): array
    {
        $blocks = $revision->getBlocks()
            ->toArray();
        usort($blocks, static fn (DatasheetTemplateBlock $left, DatasheetTemplateBlock $right): int => $left->getPosition() <=> $right->getPosition());

        $payload = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'baseRevisionHash' => '',
            'productTitle' => $revision->getTemplate()?->getProductTitle() ?? '',
            'changeNote' => $revision->getChangeNote() ?? '',
            'blocks' => array_map($this->exportBlock(...), $blocks),
        ];
        $payload['baseRevisionHash'] = $this->hash($payload);

        return $payload;
    }

    /**
     * @throws \DomainException when the browser payload is incomplete or was tampered with
     */
    public function save(DatasheetTemplateRevision $revision, string $json): void
    {
        $payload = $this->decode($json);
        $revision->assertEditable();
        $template = $revision->getTemplate() ?? throw new \LogicException('The datasheet revision has no template.');
        if (! hash_equals($this->export($revision)['baseRevisionHash'], $payload['baseRevisionHash'])) {
            throw new \DomainException('Der Entwurf wurde zwischenzeitlich in einem anderen Fenster geändert. Bitte lade die Seite neu und prüfe den aktuellen Stand.');
        }
        $this->assertKnownKeys($revision, $payload);

        $this->entityManager->wrapInTransaction(function () use ($revision, $template, $payload): void {
            $existingBlocks = $revision->getBlocks()
                ->toArray();

            // Free the unique position ranges before applying the submitted
            // order. This also makes arbitrary reorder operations safe on
            // MariaDB, which checks unique constraints row by row.
            foreach ($existingBlocks as $blockIndex => $block) {
                $block->setPosition(1000 + $blockIndex);
                foreach ($block->getColumns() as $rowIndex => $row) {
                    $row->setPosition(1000 + $rowIndex);
                }
            }
            $this->entityManager->flush();

            $blocksByKey = [];
            foreach ($existingBlocks as $block) {
                $blocksByKey[$block->getStableKey()] = $block;
            }
            $retainedBlocks = [];

            foreach ($payload['blocks'] as $blockPosition => $blockData) {
                $key = $blockData['key'];
                if (null !== $key && ! isset($blocksByKey[$key])) {
                    throw new \DomainException('Der Entwurf enthält einen unbekannten Baustein. Bitte lade die Seite neu.');
                }
                $block = null === $key ? new DatasheetTemplateBlock() : $blocksByKey[$key];
                if (null === $key) {
                    $revision->addBlock($block);
                }
                $retainedBlocks[$block->getStableKey()] = true;
                $this->applyBlock($block, $blockData, $blockPosition);
            }

            foreach ($existingBlocks as $block) {
                if (! isset($retainedBlocks[$block->getStableKey()])) {
                    $revision->removeBlock($block);
                }
            }

            $template->setProductTitle($payload['productTitle']);
            $revision->setChangeNote($payload['changeNote']);
            $this->entityManager->flush();
        });
    }

    /**
     * @param EditorPayload $payload
     */
    private function assertKnownKeys(DatasheetTemplateRevision $revision, array $payload): void
    {
        $blocks = [];
        foreach ($revision->getBlocks() as $block) {
            $rows = [];
            foreach ($block->getColumns() as $row) {
                $rows[$row->getStableKey()] = true;
            }
            $blocks[$block->getStableKey()] = $rows;
        }

        foreach ($payload['blocks'] as $block) {
            if (null === $block['key']) {
                foreach ($block['columns'] as $row) {
                    if (null !== $row['key']) {
                        throw new \DomainException('Ein neuer Baustein enthält eine unbekannte Tabellenzeile. Bitte lade die Seite neu.');
                    }
                }
                continue;
            }
            if (! isset($blocks[$block['key']])) {
                throw new \DomainException('Der Entwurf enthält einen unbekannten Baustein. Bitte lade die Seite neu.');
            }
            foreach ($block['columns'] as $row) {
                if (null !== $row['key'] && ! isset($blocks[$block['key']][$row['key']])) {
                    throw new \DomainException('Der Entwurf enthält eine unbekannte Tabellenzeile. Bitte lade die Seite neu.');
                }
            }
        }
    }

    /**
     * @return EditorPayload
     */
    private function decode(string $json): array
    {
        if (2_000_000 < strlen($json)) {
            throw new \DomainException('Der Datenblattentwurf ist zu groß.');
        }
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \DomainException('Die Editordaten sind kein gültiges JSON-Dokument.', previous: $exception);
        }
        if (! is_array($data)) {
            throw new \DomainException('Die Editordaten haben ein ungültiges Format.');
        }
        if (self::SCHEMA_VERSION !== ($data['schemaVersion'] ?? null)) {
            throw new \DomainException('Die Version der Editordaten wird nicht unterstützt. Bitte lade die Seite neu.');
        }
        $baseRevisionHash = $this->string($data, 'baseRevisionHash', 64);
        if (1 !== preg_match('/^[a-f0-9]{64}$/D', $baseRevisionHash)) {
            throw new \DomainException('Die Revisionssignatur des Entwurfs ist ungültig. Bitte lade die Seite neu.');
        }

        $productTitle = $this->string($data, 'productTitle', 255);
        if ('' === $productTitle) {
            throw new \DomainException('Die englische Produktüberschrift darf nicht leer sein.');
        }
        $changeNote = $this->string($data, 'changeNote', 255);
        $blocksData = $data['blocks'] ?? null;
        if (! is_array($blocksData) || ! array_is_list($blocksData)) {
            throw new \DomainException('Die Bausteinliste hat ein ungültiges Format.');
        }
        if (self::MAX_BLOCKS < count($blocksData)) {
            throw new \DomainException(sprintf('Eine Datenblattvorlage darf höchstens %d Bausteine enthalten.', self::MAX_BLOCKS));
        }

        $blocks = [];
        $keys = [];
        foreach ($blocksData as $index => $blockData) {
            if (! is_array($blockData)) {
                throw new \DomainException(sprintf('Baustein %d hat ein ungültiges Format.', $index + 1));
            }
            $block = $this->decodeBlock($blockData, $index);
            if (null !== $block['key']) {
                if (isset($keys[$block['key']])) {
                    throw new \DomainException('Ein Baustein kommt im Entwurf mehrfach vor.');
                }
                $keys[$block['key']] = true;
            }
            $blocks[] = $block;
        }

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'baseRevisionHash' => $baseRevisionHash,
            'productTitle' => $productTitle,
            'changeNote' => $changeNote,
            'blocks' => $blocks,
        ];
    }

    /**
     * @param array<mixed> $data
     *
     * @return EditorBlock
     */
    private function decodeBlock(array $data, int $index): array
    {
        $number = $index + 1;
        $typeValue = $this->string($data, 'type', 32);
        $type = DatasheetBlockType::tryFrom($typeValue);
        if (null === $type) {
            throw new \DomainException(sprintf('Baustein %d hat einen unbekannten Typ.', $number));
        }
        $key = $this->nullableString($data, 'key', 36);
        $sourcePath = $this->nullableString($data, 'sourcePath', 255);
        if (DatasheetBlockType::Value === $type && null !== $sourcePath && ! $this->sourceCatalog->isKnownRootSource($sourcePath)) {
            throw new \DomainException(sprintf('Baustein %d verwendet eine unbekannte Datenquelle.', $number));
        }

        $textSize = $this->enumValue($data, 'textSize', DatasheetTextSize::cases(), $number);
        $fontFamily = $this->enumValue($data, 'fontFamily', DatasheetFontFamily::cases(), $number);
        $textAlignment = $this->enumValue($data, 'textAlignment', DatasheetTextAlignment::cases(), $number);
        $layoutColumns = $this->integer($data, 'layoutColumns');
        if (! in_array($layoutColumns, [3, 6, 9, 12], true)) {
            throw new \DomainException(sprintf('Baustein %d hat eine ungültige Breite.', $number));
        }
        $minimumRows = $this->integer($data, 'minimumRows');
        $maximumRows = $this->nullableInteger($data, 'maximumRows');
        if (0 > $minimumRows || self::MAX_TABLE_ROWS < $minimumRows
            || (null !== $maximumRows && (1 > $maximumRows || self::MAX_TABLE_ROWS < $maximumRows))
            || (null !== $maximumRows && $maximumRows < $minimumRows)) {
            throw new \DomainException(sprintf('Baustein %d hat ungültige Tabellengrenzen.', $number));
        }

        $rowsData = $data['columns'] ?? null;
        if (! is_array($rowsData) || ! array_is_list($rowsData)) {
            throw new \DomainException(sprintf('Baustein %d enthält eine ungültige Tabellenkonfiguration.', $number));
        }
        if (self::MAX_TABLE_ROWS < count($rowsData)) {
            throw new \DomainException(sprintf('Eine Komponententabelle darf höchstens %d Datenzeilen enthalten.', self::MAX_TABLE_ROWS));
        }
        $rows = [];
        $rowKeys = [];
        if (DatasheetBlockType::ChildTable === $type) {
            foreach ($rowsData as $rowIndex => $rowData) {
                if (! is_array($rowData)) {
                    throw new \DomainException(sprintf('Tabellenzeile %d in Baustein %d hat ein ungültiges Format.', $rowIndex + 1, $number));
                }
                $row = $this->decodeRow($rowData, $number, $rowIndex);
                if (null !== $row['key']) {
                    if (isset($rowKeys[$row['key']])) {
                        throw new \DomainException(sprintf('Eine Tabellenzeile in Baustein %d kommt mehrfach vor.', $number));
                    }
                    $rowKeys[$row['key']] = true;
                }
                $rows[] = $row;
            }
        }

        return [
            'key' => $key,
            'type' => $type->value,
            'label' => $this->string($data, 'label', 255),
            'text' => $this->string($data, 'text', 10000),
            'sourcePath' => DatasheetBlockType::Value === $type ? $sourcePath : null,
            'textSize' => $textSize,
            'fontFamily' => $fontFamily,
            'textAlignment' => $textAlignment,
            'textBold' => $this->boolean($data, 'textBold'),
            'textItalic' => $this->boolean($data, 'textItalic'),
            'textUnderlined' => $this->boolean($data, 'textUnderlined'),
            'layoutColumns' => $layoutColumns,
            'startNewRow' => $this->boolean($data, 'startNewRow'),
            'required' => $this->boolean($data, 'required'),
            'hideIfEmpty' => $this->boolean($data, 'hideIfEmpty'),
            'minimumRows' => $minimumRows,
            'maximumRows' => $maximumRows,
            'columns' => $rows,
        ];
    }

    /**
     * @param array<mixed> $data
     *
     * @return EditorRow
     */
    private function decodeRow(array $data, int $blockNumber, int $index): array
    {
        $sourcePath = $this->nullableString($data, 'sourcePath', 255);
        if (null !== $sourcePath && ! $this->sourceCatalog->isKnownChildSource($sourcePath)) {
            throw new \DomainException(sprintf('Tabellenzeile %d in Baustein %d verwendet eine unbekannte Datenquelle.', $index + 1, $blockNumber));
        }

        return [
            'key' => $this->nullableString($data, 'key', 36),
            'label' => $this->string($data, 'label', 255),
            'sourcePath' => $sourcePath,
            'unit' => $this->string($data, 'unit', 32),
            'required' => $this->boolean($data, 'required'),
        ];
    }

    /**
     * @param EditorBlock $data
     */
    private function applyBlock(DatasheetTemplateBlock $block, array $data, int $position): void
    {
        $type = DatasheetBlockType::from($data['type']);
        $block->setType($type)
            ->setLabel($data['label'])
            ->setText($data['text'])
            ->setSourcePath($data['sourcePath'])
            ->setTextSize(DatasheetTextSize::from($data['textSize']))
            ->setFontFamily(DatasheetFontFamily::from($data['fontFamily']))
            ->setTextAlignment(DatasheetTextAlignment::from($data['textAlignment']))
            ->setTextBold($data['textBold'])
            ->setTextItalic($data['textItalic'])
            ->setTextUnderlined($data['textUnderlined'])
            ->setPosition($position)
            ->setLayoutColumns($data['layoutColumns'])
            ->setStartNewRow($data['startNewRow'])
            ->setRequired($data['required'])
            ->setHideIfEmpty($data['hideIfEmpty'])
            ->setMinimumRows($data['minimumRows'])
            ->setMaximumRows($data['maximumRows']);

        $existingRows = $block->getColumns()
            ->toArray();
        $rowsByKey = [];
        foreach ($existingRows as $row) {
            $rowsByKey[$row->getStableKey()] = $row;
        }
        $retainedRows = [];
        foreach ($data['columns'] as $rowPosition => $rowData) {
            $key = $rowData['key'];
            if (null !== $key && ! isset($rowsByKey[$key])) {
                throw new \DomainException('Der Entwurf enthält eine unbekannte Tabellenzeile. Bitte lade die Seite neu.');
            }
            $row = null === $key ? new DatasheetTableColumn() : $rowsByKey[$key];
            if (null === $key) {
                $block->addColumn($row);
            }
            $retainedRows[$row->getStableKey()] = true;
            $row->setLabel($rowData['label'])
                ->setSourcePath($rowData['sourcePath'] ?? '')
                ->setUnit($rowData['unit'])
                ->setRequired($rowData['required'])
                ->setPosition($rowPosition);
        }
        foreach ($existingRows as $row) {
            if (! isset($retainedRows[$row->getStableKey()])) {
                $block->removeColumn($row);
            }
        }
    }

    /**
     * @return EditorBlock
     */
    private function exportBlock(DatasheetTemplateBlock $block): array
    {
        $rows = $block->getColumns()
            ->toArray();
        usort($rows, static fn (DatasheetTableColumn $left, DatasheetTableColumn $right): int => $left->getPosition() <=> $right->getPosition());

        return [
            'key' => $block->getStableKey(),
            'type' => $block->getType()
                ->value,
            'label' => $block->getLabel() ?? '',
            'text' => $block->getText() ?? '',
            'sourcePath' => $block->getSourcePath(),
            'textSize' => $block->getTextSize()
                ->value,
            'fontFamily' => $block->getFontFamily()
                ->value,
            'textAlignment' => $block->getTextAlignment()
                ->value,
            'textBold' => $block->isTextBold(),
            'textItalic' => $block->isTextItalic(),
            'textUnderlined' => $block->isTextUnderlined(),
            'layoutColumns' => $block->getLayoutColumns(),
            'startNewRow' => $block->isStartNewRow(),
            'required' => $block->isRequired(),
            'hideIfEmpty' => $block->isHideIfEmpty(),
            'minimumRows' => $block->getMinimumRows(),
            'maximumRows' => $block->getMaximumRows(),
            'columns' => array_map(static fn (DatasheetTableColumn $row): array => [
                'key' => $row->getStableKey(),
                'label' => $row->getLabel(),
                'sourcePath' => '' === $row->getSourcePath() ? null : $row->getSourcePath(),
                'unit' => $row->getUnit() ?? '',
                'required' => $row->isRequired(),
            ], $rows),
        ];
    }

    /**
     * @param array<mixed> $data
     */
    private function string(array $data, string $key, int $maximumLength): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value)) {
            throw new \DomainException(sprintf('Das Feld „%s“ hat ein ungültiges Format.', $key));
        }
        $value = trim($value);
        if ($maximumLength < mb_strlen($value)) {
            throw new \DomainException(sprintf('Das Feld „%s“ ist zu lang.', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private function nullableString(array $data, string $key, int $maximumLength): ?string
    {
        $value = $data[$key] ?? null;
        if (null === $value || '' === $value) {
            return null;
        }
        if (! is_string($value) || $maximumLength < mb_strlen($value)) {
            throw new \DomainException(sprintf('Das Feld „%s“ hat ein ungültiges Format.', $key));
        }

        return trim($value);
    }

    /**
     * @param array<mixed> $data
     */
    private function integer(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (! is_int($value)) {
            throw new \DomainException(sprintf('Das Feld „%s“ muss eine Ganzzahl sein.', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private function nullableInteger(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        if (null === $value) {
            return null;
        }
        if (! is_int($value)) {
            throw new \DomainException(sprintf('Das Feld „%s“ muss eine Ganzzahl sein.', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private function boolean(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;
        if (! is_bool($value)) {
            throw new \DomainException(sprintf('Das Feld „%s“ muss ein Wahrheitswert sein.', $key));
        }

        return $value;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param array<mixed> $data
     * @param list<T>      $cases
     */
    private function enumValue(array $data, string $key, array $cases, int $blockNumber): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value)) {
            throw new \DomainException(sprintf('Baustein %d hat für „%s“ einen ungültigen Wert.', $blockNumber, $key));
        }
        foreach ($cases as $case) {
            if ($case->value === $value) {
                return $value;
            }
        }

        throw new \DomainException(sprintf('Baustein %d hat für „%s“ einen unbekannten Wert.', $blockNumber, $key));
    }

    /**
     * @param EditorPayload $payload
     */
    private function hash(array $payload): string
    {
        $payload['baseRevisionHash'] = '';

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
