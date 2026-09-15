<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Entity\Production\DatasheetBlockType;
use App\Entity\Production\BuildInstance;
use App\Entity\Production\SystemTemplate;
use App\Entity\Production\DatasheetTableColumn;
use App\Entity\Production\DatasheetTemplate;
use App\Entity\Production\DatasheetTemplateBlock;
use App\Entity\Production\DatasheetTemplateRevision;

/** Synthetic data only. Never persisted in the user's production database. */
final class MultipageDatasheetExample
{
    public static function instance(): BuildInstance
    {
        $instance = (new BuildInstance())->setSerialNumber('TEST-CID001')
            ->setSystemTemplate((new SystemTemplate())->setName('Synthetic electronics'));
        for ($i = 0; $i < 3; ++$i) {
            (new BuildInstance())->setSerialNumber('TEST-BID00'.($i + 1))->setInstalledSlotIndex($i)->setParent($instance);
        }

        return $instance;
    }

    public static function revision(): DatasheetTemplateRevision
    {
        $revision = (new DatasheetTemplateRevision())->setTemplate(
            (new DatasheetTemplate())->setName('Multipage layout test')->setProductTitle('Electronics Data Sheet – Layout Test')
        );
        $add = static function (DatasheetBlockType $type, string $label) use ($revision): DatasheetTemplateBlock {
            $block = (new DatasheetTemplateBlock())->setType($type)->setLabel($label)->setPosition($revision->getBlocks()->count());
            $revision->addBlock($block);

            return $block;
        };
        $add(DatasheetBlockType::Heading, 'Synthetic electronics example');
        $add(DatasheetBlockType::StaticText, 'Scope')->setText('Layout verification only. All entries are synthetic; this is not a released customer data sheet.');
        $add(DatasheetBlockType::Value, 'Board #')->setSourcePath('instance.serial_number')->setLayoutColumns(6);
        $add(DatasheetBlockType::Value, 'Product')->setSourcePath('instance.product_name')->setLayoutColumns(6);
        $table = $add(DatasheetBlockType::ChildTable, 'Component measurements')->setMinimumRows(3)->setStartNewRow(true);
        for ($i = 1; $i <= 80; ++$i) {
            $table->addColumn((new DatasheetTableColumn())->setPosition($i)->setLabel(sprintf('Measurement %02d', $i))->setUnit('mV')->setSourcePath('child.serial_number'));
        }
        $add(DatasheetBlockType::Separator, '');
        $add(DatasheetBlockType::PageBreak, '');
        $add(DatasheetBlockType::Heading, 'Installation and operation');
        $text = [];
        for ($i = 1; $i <= 90; ++$i) {
            $text[] = sprintf('Instruction %02d: Check connections and record the installation conditions before operating the device.', $i);
        }
        $add(DatasheetBlockType::StaticText, 'Handling instructions')->setText(implode("\n", $text));
        $add(DatasheetBlockType::EditableNote, 'Final inspection notes')->setText('End of synthetic document. All measurements and installation notes have been checked.');

        return $revision;
    }
}
