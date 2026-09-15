<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Entity\Production\ProtocolFieldType;
use App\Entity\Production\ProtocolTemplate;
use App\Entity\Production\ProtocolTemplateField;
use App\Entity\Production\ProtocolTemplateRevision;
use App\Entity\Production\ProtocolTemplateSection;
use App\Services\Production\ProtocolTemplateExporter;

final class ProtocolTemplateImportExample
{
    public static function json(): string
    {
        $template = (new ProtocolTemplate())->setName('Imported example')->setDescription('Instructions')->setActive(false);
        $revision = (new ProtocolTemplateRevision())->setRevisionNumber(7)->setChangeNote('Source note');
        $template->addRevision($revision);
        $section = (new ProtocolTemplateSection())->setName('Measurements')->setDescription('Section notes')->setPosition(4);
        $revision->addSection($section);
        foreach (ProtocolFieldType::cases() as $position => $type) {
            $field = (new ProtocolTemplateField())->setLabel($type->value)->setType($type)->setPosition($position * 2)
                ->setHelpText("µV ✓\n<script>plain text</script>")->setLayoutColumns(3)->setStartNewRow(true);
            if (ProtocolFieldType::Choice === $type) {
                $field->setOptions(['Silver', 'Black']);
            }
            if (ProtocolFieldType::Decimal === $type) {
                $field->setUnit('µV');
            }
            $section->addField($field);
        }
        $revision->publish(null);

        return (new ProtocolTemplateExporter())->export([$revision]);
    }
}
