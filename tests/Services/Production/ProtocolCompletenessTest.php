<?php

declare(strict_types=1);

namespace App\Tests\Services\Production;

use App\Entity\Production\{ProtocolAnswer, ProtocolFieldType, ProtocolRun, ProtocolRunSectionRow, ProtocolTemplateField, ProtocolTemplateSection};
use App\Services\Production\ProtocolManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProtocolCompletenessTest extends KernelTestCase
{
    public static function values(): iterable
    {
        yield 'zero integer' => [ProtocolFieldType::Integer, 0, true, 0];
        yield 'zero decimal' => [ProtocolFieldType::Decimal, '0.000', true, 0];
        yield 'false boolean' => [ProtocolFieldType::Boolean, false, true, 0];
        yield 'whitespace required' => [ProtocolFieldType::Text, '   ', true, 1];
        yield 'missing required' => [ProtocolFieldType::Text, null, true, 1];
        yield 'missing optional' => [ProtocolFieldType::Text, null, false, 0];
    }

    #[DataProvider('values')]
    public function testUsesExistingRequiredFlagAndDoesNotTreatZeroAsMissing(ProtocolFieldType $type, string|int|bool|null $value, bool $required, int $expectedWarnings): void
    {
        self::bootKernel();
        $run = new ProtocolRun();
        $section = (new ProtocolTemplateSection())->setName('Measurements');
        $field = (new ProtocolTemplateField())->setLabel('Reading')->setType($type)->setRequired($required);
        $section->addField($field);
        $row = (new ProtocolRunSectionRow())->setSection($section);
        $run->addRow($row);
        $answer = (new ProtocolAnswer())->setField($field);
        $row->addAnswer($answer);
        $answer->setValue($value);
        self::assertCount($expectedWarnings, self::getContainer()->get(ProtocolManager::class)->validateForCompletion($run));
    }
}
