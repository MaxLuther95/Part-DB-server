<?php

declare(strict_types=1);

namespace App\Tests\Entity\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\ProtocolAnswer;
use App\Entity\Production\ProtocolFieldType;
use App\Entity\Production\ProtocolRevisionStatus;
use App\Entity\Production\ProtocolRun;
use App\Entity\Production\ProtocolRunSectionRow;
use App\Entity\Production\ProtocolRunStatus;
use App\Entity\Production\ProtocolTemplate;
use App\Entity\Production\ProtocolTemplateField;
use App\Entity\Production\ProtocolTemplateRevision;
use App\Entity\Production\ProtocolTemplateSection;
use App\Entity\UserSystem\User;
use PHPUnit\Framework\TestCase;

final class ProtocolEntitiesTest extends TestCase
{
    public function testNotesAreOptionalPrivateToEachRunAndFreezeOnCompletion(): void
    {
        $run = new ProtocolRun();
        $another = new ProtocolRun();
        self::assertSame('', $run->getNotes());
        $run->setNotes("First line\nSecond line");
        self::assertSame('', $another->getNotes());
        $run->setNotes(null);
        self::assertSame('', $run->getNotes());
        $run->setNotes('Internal measurement context');
        $run->complete(null);
        self::assertSame('Internal measurement context', $run->getNotes());
        $this->expectException(\LogicException::class);
        $run->setNotes('Changed after completion');
    }

    public function testOversizedNotesAreRejectedWithoutLosingPreviousNotes(): void
    {
        $run = (new ProtocolRun())->setNotes('Keep this');
        try {
            $run->setNotes(str_repeat('x', 10001));
            self::fail('Oversized notes must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame('Keep this', $run->getNotes());
        }
    }

    public function testHeaderDefaultsAndEditorSnapshot(): void
    {
        $creator = (new User())->setName('creator');
        $editor = (new User())->setName('editor');
        $run = (new ProtocolRun())->setStartedBy($creator);
        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $run->getProtocolDate()?->format('Y-m-d'));
        self::assertSame('creator', $run->getLastEditedByName());
        $run->setProtocolDate(new \DateTimeImmutable('2026-09-08 16:30'));
        $run->touch($editor);
        self::assertSame('2026-09-08 00:00', $run->getProtocolDate()?->format('Y-m-d H:i'));
        self::assertSame($creator, $run->getStartedBy());
        self::assertSame($editor, $run->getLastEditedBy());
        self::assertSame('editor', $run->getLastEditedByName());
        $run->complete($editor);
        $editor->setName('renamed');
        $run->invalidate('Correction required', $creator);
        self::assertSame('editor', $run->getLastEditedByName());
        self::assertSame('2026-09-08', $run->getProtocolDate()?->format('Y-m-d'));
    }

    public function testCompletedRunDateCannotChange(): void
    {
        $run = new ProtocolRun();
        $run->complete(null);
        $this->expectException(\LogicException::class);
        $run->setProtocolDate(new \DateTimeImmutable('2020-01-01'));
    }

    public function testCompletedRunEditorCannotChange(): void
    {
        $run = new ProtocolRun();
        $run->complete(null);
        $this->expectException(\LogicException::class);
        $run->setStartedBy((new User())->setName('forged'));
    }

    public function testPublishedTemplateRevisionIsImmutable(): void
    {
        [$revision, $section, $field] = $this->createDraftDefinition();

        $revision->publish(null);

        self::assertSame(ProtocolRevisionStatus::Published, $revision->getStatus());
        self::assertNotNull($revision->getPublishedAt());
        $this->expectException(\LogicException::class);
        $field->setLabel('Rückwirkend geändert');
    }

    public function testCompletedRunValuesAreImmutable(): void
    {
        [$revision, $section, $field] = $this->createDraftDefinition();
        $revision->publish(null);
        $run = (new ProtocolRun())
            ->setBuildInstance(new BuildInstance())
            ->setRevision($revision)
            ->setRunNumber(1);
        $row = (new ProtocolRunSectionRow())->setSection($section);
        $run->addRow($row);
        $answer = (new ProtocolAnswer())->setField($field);
        $row->addAnswer($answer);
        $answer->setValue('1292');

        $run->complete(null);

        self::assertSame(ProtocolRunStatus::Completed, $run->getStatus());
        self::assertSame('1292', $answer->getValue());
        $this->expectException(\LogicException::class);
        $answer->setValue('1293');
    }

    public function testCompletedRunCanOnlyBeInvalidatedWithReason(): void
    {
        [$revision] = $this->createDraftDefinition();
        $revision->publish(null);
        $run = (new ProtocolRun())->setBuildInstance(new BuildInstance())
            ->setRevision($revision);
        $run->complete(null);

        try {
            $run->invalidate(' ', null);
            self::fail('An invalidation reason must be required.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        $run->invalidate('Messaufbau war fehlerhaft.', null);
        self::assertSame(ProtocolRunStatus::Invalid, $run->getStatus());
        self::assertSame('Messaufbau war fehlerhaft.', $run->getInvalidReason());
        self::assertNotNull($run->getInvalidatedAt());
    }

    public function testAnswersUseDedicatedTypedValues(): void
    {
        [, $section, $field] = $this->createDraftDefinition();
        $field->setType(ProtocolFieldType::Decimal);
        $row = (new ProtocolRunSectionRow())->setSection($section);
        $answer = (new ProtocolAnswer())->setField($field);
        $row->addAnswer($answer);

        $answer->setValue('12.345000000');

        self::assertSame('12.345000000', $answer->getValue());
        self::assertFalse($answer->isEmpty());
        $answer->clearValue();
        self::assertTrue($answer->isEmpty());
    }

    public function testTestResultOnlyAcceptsKnownStates(): void
    {
        [, $section, $field] = $this->createDraftDefinition();
        $field->setType(ProtocolFieldType::TestResult);
        $row = (new ProtocolRunSectionRow())->setSection($section);
        $answer = (new ProtocolAnswer())->setField($field);
        $row->addAnswer($answer);

        $answer->setValue('not_applicable');
        self::assertSame('not_applicable', $answer->getValue());

        try {
            $answer->setValue('unknown');
            self::fail('Unknown test states must be rejected.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        self::assertSame('not_applicable', $answer->getValue());
    }

    public function testStaticNotesHaveLayoutButCannotStoreAnswers(): void
    {
        [, $section, $field] = $this->createDraftDefinition();
        $field->setType(ProtocolFieldType::StaticNote)
            ->setHelpText('Sichtprüfung nach Montage')
            ->setLayoutColumns(12)
            ->setStartNewRow(true);

        self::assertFalse($field->isInputField());
        self::assertFalse($field->isRequired());
        self::assertSame(12, $field->getLayoutColumns());
        self::assertTrue($field->isStartNewRow());

        $this->expectException(\InvalidArgumentException::class);
        (new ProtocolAnswer())->setField($field);
    }

    public function testNewInputFieldsAreRequiredByDefault(): void
    {
        $field = new ProtocolTemplateField();

        self::assertTrue($field->isRequired());
        $field->setType(ProtocolFieldType::StaticNote)
            ->setRequired(true);
        self::assertFalse($field->isRequired());
    }

    /**
     * @return array{ProtocolTemplateRevision, ProtocolTemplateSection, ProtocolTemplateField}
     */
    private function createDraftDefinition(): array
    {
        $template = (new ProtocolTemplate())->setName('SEL Prüfung');
        $revision = (new ProtocolTemplateRevision())->setRevisionNumber(1);
        $template->addRevision($revision);
        $section = (new ProtocolTemplateSection())->setName('Identifikation');
        $revision->addSection($section);
        $field = (new ProtocolTemplateField())->setLabel('Board ID')
            ->setRequired(true);
        $section->addField($field);

        return [$revision, $section, $field];
    }
}
