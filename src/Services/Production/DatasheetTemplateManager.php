<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Entity\Production\DatasheetBlockType;
use App\Entity\Production\DatasheetTableColumn;
use App\Entity\Production\DatasheetTemplate;
use App\Entity\Production\DatasheetTemplateBlock;
use App\Entity\Production\DatasheetTemplateRevision;
use App\Entity\Production\ProtocolRevisionStatus;
use App\Entity\UserSystem\User;

final readonly class DatasheetTemplateManager
{
    public function __construct(
        private DatasheetSourceCatalog $sourceCatalog,
    ) {
    }

    public function createInitialDraft(DatasheetTemplate $template): DatasheetTemplateRevision
    {
        if (0 !== $template->getRevisions()->count()) {
            throw new \LogicException('The datasheet template already has a revision.');
        }
        $revision = (new DatasheetTemplateRevision())->setRevisionNumber(1);
        $template->addRevision($revision);

        return $revision;
    }

    public function getOrCreateDraft(DatasheetTemplate $template): DatasheetTemplateRevision
    {
        if (null !== $draft = $template->getDraftRevision()) {
            return $draft;
        }
        $draft = (new DatasheetTemplateRevision())->setRevisionNumber($template->getNextRevisionNumber());
        $template->addRevision($draft);
        $published = $template->getPublishedRevision();
        if (null === $published) {
            return $draft;
        }
        foreach ($published->getBlocks() as $sourceBlock) {
            $block = (new DatasheetTemplateBlock($sourceBlock->getStableKey()))
                ->setType($sourceBlock->getType())
                ->setLabel($sourceBlock->getLabel())
                ->setText($sourceBlock->getText())
                ->setSourcePath($sourceBlock->getSourcePath())
                ->setHeaderSourcePath($sourceBlock->getHeaderSourcePath())
                ->setHeaderFormat($sourceBlock->getHeaderFormat())
                ->setTextSize($sourceBlock->getTextSize())
                ->setFontFamily($sourceBlock->getFontFamily())
                ->setTextAlignment($sourceBlock->getTextAlignment())
                ->setTextBold($sourceBlock->isTextBold())
                ->setTextItalic($sourceBlock->isTextItalic())
                ->setTextUnderlined($sourceBlock->isTextUnderlined())
                ->setPosition($sourceBlock->getPosition())
                ->setLayoutColumns($sourceBlock->getLayoutColumns())
                ->setStartNewRow($sourceBlock->isStartNewRow())
                ->setRequired($sourceBlock->isRequired())
                ->setHideIfEmpty($sourceBlock->isHideIfEmpty())
                ->setMinimumRows($sourceBlock->getMinimumRows())
                ->setMaximumRows($sourceBlock->getMaximumRows());
            $draft->addBlock($block);
            foreach ($sourceBlock->getColumns() as $sourceColumn) {
                $block->addColumn(
                    (new DatasheetTableColumn($sourceColumn->getStableKey()))
                        ->setLabel($sourceColumn->getLabel())
                        ->setSourcePath($sourceColumn->getSourcePath())
                        ->setUnit($sourceColumn->getUnit())
                        ->setPosition($sourceColumn->getPosition())
                        ->setRequired($sourceColumn->isRequired())
                );
            }
        }

        return $draft;
    }

    public function addBlock(DatasheetTemplateRevision $revision): DatasheetTemplateBlock
    {
        $block = (new DatasheetTemplateBlock())->setPosition($this->nextPosition($revision->getBlocks()->toArray()));
        $revision->addBlock($block);

        return $block;
    }

    /**
     * @return list<string>
     */
    public function validateForPublishing(DatasheetTemplateRevision $revision): array
    {
        if (ProtocolRevisionStatus::Draft !== $revision->getStatus()) {
            return ['Nur ein Entwurf kann veröffentlicht werden.'];
        }
        $errors = [];
        foreach ($revision->getBlocks() as $block) {
            if (DatasheetBlockType::Value === $block->getType() && ! $this->sourceCatalog->isKnownRootSource($block->getSourcePath())) {
                $errors[] = sprintf('Der Wert „%s“ benötigt eine gültige Datenquelle.', $block->getLabel() ?? 'Ohne Bezeichnung');
            }
            if (in_array($block->getType(), [DatasheetBlockType::Heading, DatasheetBlockType::StaticText, DatasheetBlockType::EditableNote], true)
                && null === $block->getLabel() && null === $block->getText()) {
                $errors[] = 'Ein Textbaustein ist vollständig leer.';
            }
            if (DatasheetBlockType::ChildTable === $block->getType()) {
                if (null !== $block->getHeaderSourcePath() && ! $this->sourceCatalog->isKnownChildSource($block->getHeaderSourcePath())) {
                    $errors[] = sprintf('Die Spaltenüberschrift der Tabelle „%s“ benötigt eine gültige Datenquelle.', $block->getLabel() ?? 'Ohne Bezeichnung');
                }
                if (0 > $block->getMinimumRows() || 100 < $block->getMinimumRows()) {
                    $errors[] = sprintf('Bei der Tabelle „%s“ muss die Mindestzahl der Komponentenspalten zwischen 0 und 100 liegen.', $block->getLabel() ?? 'Ohne Bezeichnung');
                }
                if (null !== $block->getMaximumRows() && (1 > $block->getMaximumRows() || 100 < $block->getMaximumRows())) {
                    $errors[] = sprintf('Bei der Tabelle „%s“ muss die Höchstzahl der Komponentenspalten zwischen 1 und 100 liegen.', $block->getLabel() ?? 'Ohne Bezeichnung');
                }
                if (null !== $block->getMaximumRows() && $block->getMaximumRows() < $block->getMinimumRows()) {
                    $errors[] = sprintf('Bei der Tabelle „%s“ ist die maximale Zeilenzahl kleiner als die Mindestzahl.', $block->getLabel() ?? 'Ohne Bezeichnung');
                }
                if (0 === $block->getColumns()->count()) {
                    $errors[] = sprintf('Die Tabelle „%s“ benötigt mindestens eine Datenzeile.', $block->getLabel() ?? 'Ohne Bezeichnung');
                }
                foreach ($block->getColumns() as $column) {
                    if (! $this->sourceCatalog->isKnownChildSource($column->getSourcePath())) {
                        $errors[] = sprintf('Die Tabellenzeile „%s“ benötigt eine gültige Datenquelle.', $column->getLabel());
                    }
                }
            }
        }

        return $errors;
    }

    public function publish(DatasheetTemplateRevision $revision, ?User $user): void
    {
        $errors = $this->validateForPublishing($revision);
        if ([] !== $errors) {
            throw new \DomainException(implode("\n", $errors));
        }
        $published = $revision->getTemplate()?->getPublishedRevision();
        if (null !== $published && $published !== $revision) {
            $published->retire();
        }
        $revision->publish($user);
    }

    /**
     * @param list<DatasheetTemplateBlock> $items
     */
    private function nextPosition(array $items): int
    {
        $position = 0;
        foreach ($items as $item) {
            $position = max($position, $item->getPosition() + 1);
        }

        return $position;
    }
}
