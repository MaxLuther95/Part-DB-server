<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\DatasheetBlockType;
use App\Entity\Production\DatasheetTemplateRevision;
use Jbtronics\DompdfFontLoaderBundle\Services\DompdfFactoryInterface;
use Twig\Environment;

final readonly class DatasheetRenderer
{
    public function __construct(
        private Environment $twig,
        private DompdfFactoryInterface $dompdfFactory,
        private DatasheetSourceCatalog $sourceCatalog,
    ) {
    }

    /**
     * @param array<string, string>                                $editableNotes   keyed by block stable key
     * @param array<string, int>                                   $runSelections   keyed by instance/template selection key
     * @param list<array{title: string, text: string, width: int}> $additionalNotes
     *
     * @return array{revision: DatasheetTemplateRevision, instance: ?BuildInstance, draft: bool, fixed_fields: list<array{label: string, source: string, value: string}>, blocks: list<array<string, mixed>>, additional_notes: list<array{title: string, text: string, width: int}>}
     */
    public function createView(DatasheetTemplateRevision $revision, ?BuildInstance $instance = null, bool $draft = true, array $editableNotes = [], array $runSelections = [], array $additionalNotes = []): array
    {
        $blocks = [];
        foreach ($revision->getBlocks() as $block) {
            $view = [
                'block' => $block,
                'value' => null,
                'source_run_id' => null,
                'columns' => [],
                'rows' => [],
            ];
            if (DatasheetBlockType::Value === $block->getType()) {
                $selectionKey = null === $instance ? null : $this->sourceCatalog->selectionKey($block->getSourcePath(), $instance);
                $selectedRunId = null === $selectionKey ? null : ($runSelections[$selectionKey] ?? null);
                $view['value'] = $this->sourceCatalog->resolve($block->getSourcePath(), $instance, $selectedRunId);
                $view['source_run_id'] = null === $instance ? null : $this->sourceCatalog->resolvedRunId($block->getSourcePath(), $instance, $selectedRunId);
            } elseif (DatasheetBlockType::EditableNote === $block->getType()) {
                $view['value'] = array_key_exists($block->getStableKey(), $editableNotes)
                    ? trim($editableNotes[$block->getStableKey()])
                    : ($block->getText() ?? '');
            } elseif (DatasheetBlockType::ChildTable === $block->getType()) {
                $previewColumns = max(1, min(12, $block->getMinimumRows()));
                $children = null === $instance ? array_fill(0, $previewColumns, null) : $instance->getChildren()
                    ->toArray();
                usort($children, static function (?BuildInstance $left, ?BuildInstance $right): int {
                    if (null === $left || null === $right) {
                        return 0;
                    }

                    return [$left->getInstalledSlot()?->getPosition() ?? PHP_INT_MAX, $left->getInstalledSlotIndex() ?? PHP_INT_MAX, $left->getId() ?? PHP_INT_MAX]
                        <=> [$right->getInstalledSlot()?->getPosition() ?? PHP_INT_MAX, $right->getInstalledSlotIndex() ?? PHP_INT_MAX, $right->getId() ?? PHP_INT_MAX];
                });
                foreach ($children as $index => $child) {
                    $view['columns'][] = [
                        'instance' => $child,
                        'label' => null === $child ? sprintf('Position %d', $index + 1) : $this->childColumnLabel($child, $index),
                    ];
                }
                foreach ($block->getColumns() as $definition) {
                    $cells = [];
                    foreach ($children as $child) {
                        $selectionKey = null === $child ? null : $this->sourceCatalog->selectionKey($definition->getSourcePath(), $child);
                        $selectedRunId = null === $selectionKey ? null : ($runSelections[$selectionKey] ?? null);
                        $cells[] = [
                            'instance' => $child,
                            'value' => $this->resolveWithSelection($definition->getSourcePath(), $child, $runSelections),
                            'source_run_id' => null === $child ? null : $this->sourceCatalog->resolvedRunId($definition->getSourcePath(), $child, $selectedRunId),
                        ];
                    }
                    $view['rows'][] = [
                        'definition' => $definition,
                        'cells' => $cells,
                    ];
                }
            }
            $view['hidden'] = null !== $instance && $block->isHideIfEmpty() && ! $block->isRequired() && $this->isEmptyBlock($view, $block->getType());
            $blocks[] = $view;
        }

        return [
            'revision' => $revision,
            'instance' => $instance,
            'draft' => $draft,
            'fixed_fields' => $this->fixedFields($instance),
            'blocks' => $blocks,
            'additional_notes' => $additionalNotes,
        ];
    }

    /**
     * @param array<string, string>                                $editableNotes
     * @param array<string, int>                                   $runSelections
     * @param list<array{title: string, text: string, width: int}> $additionalNotes
     */
    public function renderPdf(DatasheetTemplateRevision $revision, ?BuildInstance $instance = null, bool $draft = true, array $editableNotes = [], array $runSelections = [], array $additionalNotes = []): string
    {
        $html = $this->twig->render('production/datasheet/pdf.html.twig', $this->createView($revision, $instance, $draft, $editableNotes, $runSelections, $additionalNotes));
        $dompdf = $this->dompdfFactory->create();
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        return $dompdf->output() ?? throw new \RuntimeException('The datasheet PDF could not be generated.');
    }

    /**
     * @return array<string, array{instance: BuildInstance, runs: list<\App\Entity\Production\ProtocolRun>}>
     */
    public function collectRunChoices(DatasheetTemplateRevision $revision, BuildInstance $instance): array
    {
        $choices = [];
        foreach ($revision->getBlocks() as $block) {
            if (DatasheetBlockType::Value === $block->getType()) {
                $this->addRunChoice($choices, $block->getSourcePath(), $instance);
            }
            if (DatasheetBlockType::ChildTable !== $block->getType()) {
                continue;
            }
            foreach ($instance->getChildren() as $child) {
                foreach ($block->getColumns() as $column) {
                    $this->addRunChoice($choices, $column->getSourcePath(), $child);
                }
            }
        }

        return $choices;
    }

    /**
     * @param array<string, mixed> $view
     */
    public function validateForRelease(array $view): array
    {
        $errors = [];
        foreach ($view['blocks'] as $item) {
            $block = $item['block'];
            if (DatasheetBlockType::Value === $block->getType()) {
                $value = (string) ($item['value'] ?? '');
                if (in_array($value, [DatasheetSourceCatalog::AMBIGUOUS_VALUE, DatasheetSourceCatalog::INVALID_SELECTION], true)) {
                    $errors[] = sprintf('Für „%s“ muss ein eindeutiger Laufzettel ausgewählt werden.', $block->getLabel() ?? 'Wert');
                } elseif (DatasheetSourceCatalog::AMBIGUOUS_CHILD_POSITION === $value) {
                    $errors[] = sprintf('Für „%s“ ist die Einbauposition nicht eindeutig belegt.', $block->getLabel() ?? 'Wert');
                } elseif ($block->isRequired() && '' === $value) {
                    $errors[] = sprintf('Der erforderliche Wert „%s“ fehlt.', $block->getLabel() ?? 'Wert');
                }
            }
            if (DatasheetBlockType::EditableNote === $block->getType() && $block->isRequired() && '' === (string) ($item['value'] ?? '')) {
                $errors[] = sprintf('Die erforderliche Notiz „%s“ fehlt.', $block->getLabel() ?? 'Notiz');
            }
            if (DatasheetBlockType::ChildTable !== $block->getType()) {
                continue;
            }
            $componentCount = count($item['columns']);
            $minimumComponents = max($block->getMinimumRows(), $block->isRequired() ? 1 : 0);
            if ($componentCount < $minimumComponents) {
                $errors[] = sprintf('Die Tabelle „%s“ benötigt mindestens %d Komponentenspalten; gefunden wurden %d.', $block->getLabel() ?? 'Tabelle', $minimumComponents, $componentCount);
            }
            if (null !== $block->getMaximumRows() && $componentCount > $block->getMaximumRows()) {
                $errors[] = sprintf('Die Tabelle „%s“ erlaubt höchstens %d Komponentenspalten; gefunden wurden %d.', $block->getLabel() ?? 'Tabelle', $block->getMaximumRows(), $componentCount);
            }
            foreach ($item['rows'] as $row) {
                $definition = $row['definition'];
                foreach ($row['cells'] as $cell) {
                    $value = (string) ($cell['value'] ?? '');
                    if (in_array($value, [DatasheetSourceCatalog::AMBIGUOUS_VALUE, DatasheetSourceCatalog::INVALID_SELECTION], true)) {
                        $errors[] = sprintf('Für „%s“ bei %s muss ein eindeutiger Laufzettel ausgewählt werden.', $definition->getLabel(), $cell['instance']?->getDisplayIdentifier() ?? 'einer Komponente');
                    } elseif (DatasheetSourceCatalog::AMBIGUOUS_CHILD_POSITION === $value) {
                        $errors[] = sprintf('Für „%s“ bei %s ist die Einbauposition nicht eindeutig belegt.', $definition->getLabel(), $cell['instance']?->getDisplayIdentifier() ?? 'einer Komponente');
                    } elseif ($definition->isRequired() && '' === $value) {
                        $errors[] = sprintf('Der erforderliche Tabellenwert „%s“ fehlt bei %s.', $definition->getLabel(), $cell['instance']?->getDisplayIdentifier() ?? 'einer Komponente');
                    }
                }
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param array<string, mixed> $view
     * @param array<string, int>   $runSelections
     *
     * @return array<string, mixed>
     */
    public function createSourceSnapshot(array $view, array $runSelections): array
    {
        $snapshotBlocks = [];
        $sourceRunIds = [];
        foreach ($view['blocks'] as $item) {
            $block = $item['block'];
            $snapshot = [
                'key' => $block->getStableKey(),
                'type' => $block->getType()
                    ->value,
                'label' => $block->getLabel(),
                'source' => $block->getSourcePath(),
                'value' => $item['value'],
                'source_run_id' => $item['source_run_id'],
            ];
            if (is_int($item['source_run_id'])) {
                $sourceRunIds[] = $item['source_run_id'];
            }
            if (DatasheetBlockType::ChildTable === $block->getType()) {
                $snapshot['columns'] = array_map(static fn (array $column): array => [
                    'instance_id' => $column['instance']?->getId(),
                    'serial_number' => $column['instance']?->getDisplayIdentifier(),
                    'label' => $column['label'],
                ], $item['columns']);
                $snapshot['rows'] = [];
                foreach ($item['rows'] as $row) {
                    $cells = [];
                    foreach ($row['cells'] as $cell) {
                        $cells[] = [
                            'instance_id' => $cell['instance']?->getId(),
                            'value' => $cell['value'],
                            'source_run_id' => $cell['source_run_id'],
                        ];
                        if (is_int($cell['source_run_id'])) {
                            $sourceRunIds[] = $cell['source_run_id'];
                        }
                    }
                    $snapshot['rows'][] = [
                        'key' => $row['definition']->getStableKey(),
                        'label' => $row['definition']->getLabel(),
                        'source' => $row['definition']->getSourcePath(),
                        'cells' => $cells,
                    ];
                }
            }
            $snapshotBlocks[] = $snapshot;
        }

        return [
            'template_id' => $view['revision']->getTemplate()?->getId(),
            'template_revision_id' => $view['revision']->getId(),
            'template_revision' => $view['revision']->getRevisionNumber(),
            'build_instance_id' => $view['instance']?->getId(),
            'serial_number' => $view['instance']?->getDisplayIdentifier(),
            'source_protocol_run_ids' => array_values(array_unique($sourceRunIds)),
            'generated_at' => (new \DateTimeImmutable('now'))->format(DATE_ATOM),
            'fixed_fields' => $view['fixed_fields'],
            'blocks' => $snapshotBlocks,
            'additional_notes' => $view['additional_notes'],
        ];
    }

    /**
     * The identification strip is part of the common Magnicon data-sheet
     * standard and therefore intentionally not represented by removable
     * template blocks.
     *
     * @return list<array{label: string, source: string, value: string}>
     */
    private function fixedFields(?BuildInstance $instance): array
    {
        return array_map(fn (array $field): array => [
            ...$field,
            'value' => $this->sourceCatalog->resolve($field['source'], $instance),
        ], [
            [
                'label' => 'Customer',
                'source' => 'instance.customer_name',
            ],
            [
                'label' => 'Project',
                'source' => 'instance.project_number',
            ],
            [
                'label' => 'Order no.',
                'source' => 'instance.order_number',
            ],
        ]);
    }

    private function childColumnLabel(BuildInstance $child, int $fallbackIndex): string
    {
        if (null !== $child->getInstalledSlotIndex()) {
            return sprintf('Position %d', $child->getInstalledSlotIndex() + 1);
        }

        return $child->getContentName() ?? sprintf('Position %d', $fallbackIndex + 1);
    }

    /**
     * @param array<string, mixed> $view
     */
    private function isEmptyBlock(array $view, DatasheetBlockType $type): bool
    {
        if (DatasheetBlockType::ChildTable === $type) {
            return [] === $view['columns'];
        }
        if (in_array($type, [DatasheetBlockType::Value, DatasheetBlockType::EditableNote], true)) {
            return '' === trim((string) ($view['value'] ?? ''));
        }

        return false;
    }

    /**
     * @param array<string, int> $runSelections
     */
    private function resolveWithSelection(?string $sourcePath, ?BuildInstance $instance, array $runSelections): string
    {
        if (null === $instance) {
            return $this->sourceCatalog->resolve($sourcePath, null);
        }
        $selectionKey = $this->sourceCatalog->selectionKey($sourcePath, $instance);

        return $this->sourceCatalog->resolve($sourcePath, $instance, null === $selectionKey ? null : ($runSelections[$selectionKey] ?? null));
    }

    /**
     * @param array<string, array{instance: BuildInstance, runs: list<\App\Entity\Production\ProtocolRun>}> $choices
     */
    private function addRunChoice(array &$choices, ?string $sourcePath, BuildInstance $instance): void
    {
        $key = $this->sourceCatalog->selectionKey($sourcePath, $instance);
        if (null === $key || isset($choices[$key])) {
            return;
        }
        $runs = $this->sourceCatalog->completedRuns($sourcePath, $instance);
        if (1 < count($runs)) {
            $choices[$key] = [
                'instance' => $instance,
                'runs' => $runs,
            ];
        }
    }
}
