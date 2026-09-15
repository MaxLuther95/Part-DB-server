<?php

declare(strict_types=1);

namespace App\Tests\Services\Production;

use App\Entity\Production\ProtocolFieldType;
use App\Entity\Production\ProtocolTemplate;
use App\Entity\Production\ProtocolTemplateField;
use App\Entity\Production\ProtocolTemplateRevision;
use App\Entity\Production\ProtocolTemplateSection;
use App\Services\Production\ProtocolTemplateExporter;
use PHPUnit\Framework\TestCase;

final class ProtocolTemplateExporterTest extends TestCase
{
    public function testExportPreservesAllFieldTypesAndSettingsWithoutSerializingEntities(): void
    {
        $template = (new ProtocolTemplate())->setName('SEL / µA')->setDescription('Template notes')->setActive(false);
        $revision = (new ProtocolTemplateRevision())->setRevisionNumber(3)->setChangeNote('Example change');
        $template->addRevision($revision);
        $section = (new ProtocolTemplateSection())->setName('Measurements')->setDescription('Instructions')->setPosition(2);
        $empty = (new ProtocolTemplateSection())->setName('Empty section')->setPosition(0);
        $revision->addSection($section)->addSection($empty);
        foreach (array_reverse(ProtocolFieldType::cases(), true) as $position => $type) {
            $field = (new ProtocolTemplateField())->setLabel($type->value)->setType($type)
                ->setPosition($position)->setHelpText("Check ✓\nSecond line <script>plain text</script>")
                ->setLayoutColumns(9)->setStartNewRow(true)->setRequired(true);
            if (ProtocolFieldType::Choice === $type) {
                $field->setOptions(['Silver', 'Black']);
            }
            if (ProtocolFieldType::Decimal === $type) {
                $field->setUnit('µV');
            }
            $section->addField($field);
        }

        $exporter = new ProtocolTemplateExporter();
        $json = $exporter->export([$revision]);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['format', 'format_version', 'exported_at', 'templates'], array_keys($data));
        self::assertSame(ProtocolTemplateExporter::FORMAT, $data['format']);
        self::assertSame(1, $data['format_version']);
        $exported = $data['templates'][0];
        self::assertSame(['name', 'description', 'active', 'revisions'], array_keys($exported));
        self::assertSame('SEL / µA', $exported['name']);
        self::assertSame('Template notes', $exported['description']);
        self::assertFalse($exported['active']);
        $exportedRevision = $exported['revisions'][0];
        self::assertSame(['number', 'status', 'change_note', 'published_at', 'sections'], array_keys($exportedRevision));
        self::assertSame(3, $exportedRevision['number']);
        self::assertSame('draft', $exportedRevision['status']);
        self::assertSame('Example change', $exportedRevision['change_note']);
        self::assertNull($exportedRevision['published_at']);
        self::assertSame([], $exportedRevision['sections'][0]['fields']);
        $exportedSection = $exportedRevision['sections'][1];
        self::assertSame(['key', 'name', 'description', 'position', 'fields'], array_keys($exportedSection));
        self::assertSame($section->getStableKey(), $exportedSection['key']);
        self::assertSame('Instructions', $exportedSection['description']);
        self::assertSame(array_column(ProtocolFieldType::cases(), 'value'), array_column($exportedSection['fields'], 'type'));
        foreach ($exportedSection['fields'] as $field) {
            self::assertSame(['key', 'label', 'type', 'unit', 'help_text', 'position', 'layout_columns', 'start_new_row', 'required', 'options'], array_keys($field));
            self::assertSame(9, $field['layout_columns']);
            self::assertTrue($field['start_new_row']);
            self::assertSame('static_note' !== $field['type'], $field['required']);
            self::assertSame("Check ✓\nSecond line <script>plain text</script>", $field['help_text']);
            self::assertSame('choice' === $field['type'] ? ['Silver', 'Black'] : null, $field['options']);
            self::assertSame('decimal' === $field['type'] ? 'µV' : null, $field['unit']);
        }
        self::assertSame('draft', $revision->getStatus()->value);
        self::assertSame($section, $revision->getSections()->first(), 'Export must not reorder the managed collection.');
    }

    public function testSelectionPreservesRevisionStatesAndDoesNotMergeEqualTemplateNames(): void
    {
        $first = (new ProtocolTemplate())->setName('Same name');
        $second = (new ProtocolTemplate())->setName('Same name');
        $retired = new ProtocolTemplateRevision();
        $published = (new ProtocolTemplateRevision())->setRevisionNumber(2);
        $unselected = (new ProtocolTemplateRevision())->setRevisionNumber(3);
        $draft = new ProtocolTemplateRevision();
        $first->addRevision($retired)->addRevision($published)->addRevision($unselected);
        $second->addRevision($draft);
        $retired->publish(null);
        $retired->retire();
        $published->publish(null);
        $data = json_decode((new ProtocolTemplateExporter())->export([$published, $draft, $retired, $published]), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(2, $data['templates']);
        self::assertCount(2, $data['templates'][0]['revisions']);
        self::assertSame([1, 2], array_column($data['templates'][0]['revisions'], 'number'));
        self::assertSame(['retired', 'published'], array_column($data['templates'][0]['revisions'], 'status'));
        self::assertNotNull($data['templates'][0]['revisions'][1]['published_at']);
        self::assertSame('draft', $data['templates'][1]['revisions'][0]['status']);
    }

    public function testEmptyExportIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ProtocolTemplateExporter())->export([]);
    }

    public function testOversizedSelectionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ProtocolTemplateExporter())->export(array_fill(0, ProtocolTemplateExporter::MAX_REVISIONS + 1, new ProtocolTemplateRevision()));
    }
}
