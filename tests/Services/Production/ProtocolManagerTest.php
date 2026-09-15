<?php

declare(strict_types=1);

namespace App\Tests\Services\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\ProtocolFieldType;
use App\Entity\Production\ProtocolRevisionStatus;
use App\Entity\Production\ProtocolRun;
use App\Entity\Production\ProtocolRunStatus;
use App\Entity\Production\ProtocolTemplate;
use App\Entity\Production\ProtocolTemplateField;
use App\Entity\Production\ProtocolTemplateRevision;
use App\Entity\Production\ProtocolTemplateSection;
use App\Form\Production\ProtocolRunType;
use App\Services\Production\ProtocolManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

final class ProtocolManagerTest extends KernelTestCase
{
    public function testPublishingReplacementPreservesCompletedAndDraftRuns(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $manager = self::getContainer()->get(ProtocolManager::class);
        $template = (new ProtocolTemplate())->setName('Revision test '.bin2hex(random_bytes(4)));
        $original = $manager->createInitialDraft($template);
        $section = $manager->addSection($original)->setName('Measurements');
        $field = $manager->addField($section)->setLabel('Result')->setType(ProtocolFieldType::Text);
        $instance = (new BuildInstance())->setSerialNumber('REV-'.bin2hex(random_bytes(6)));
        $system = (new \App\Entity\Production\SystemTemplate())->setName('Revision test system');
        $instance->setSystemTemplate($system);
        $template->addSystemTemplate($system);
        $entityManager->persist($system);
        $entityManager->persist($template);
        $entityManager->persist($instance);
        $manager->publish($original, null);
        $entityManager->flush();

        $completed = $manager->createRun($instance, $original, null);
        $completed->getRowForSection($section)->getAnswerForField($field)->setValue('Original measurement');
        $completed->complete(null);
        $draftRun = $manager->createRun($instance, $original, null);
        $replacement = $manager->getOrCreateDraft($template);
        $replacement->getSections()->first()->getFields()->first()->setLabel('New result label');
        $manager->publish($replacement, null);
        $entityManager->flush();

        self::assertSame(ProtocolRevisionStatus::Retired, $original->getStatus());
        self::assertSame($replacement, $template->getPublishedRevision());
        $newRun = $manager->createRun($instance, $template->getPublishedRevision(), null);
        self::assertSame($replacement, $newRun->getRevision());
        self::assertSame(3, $newRun->getRunNumber());

        // An in-progress run can still be edited and finished against its old revision.
        $draftRun->getRowForSection($section)->getAnswerForField($field)->setValue('Finished later');
        self::assertSame([], $manager->validateForCompletion($draftRun));
        $draftRun->complete(null);
        $entityManager->flush();
        $completedId = $completed->getId();
        $draftId = $draftRun->getId();
        $originalId = $original->getId();
        $entityManager->clear();

        foreach ([$completedId => 'Original measurement', $draftId => 'Finished later'] as $id => $value) {
            $reloaded = $entityManager->find(ProtocolRun::class, $id);
            self::assertSame($originalId, $reloaded->getRevision()->getId());
            self::assertSame(ProtocolRevisionStatus::Retired, $reloaded->getRevision()->getStatus());
            self::assertSame(ProtocolRunStatus::Completed, $reloaded->getStatus());
            $answer = $reloaded->getRows()->first()->getAnswers()->first();
            self::assertSame($value, $answer->getValue());
            self::assertSame('Result', $answer->getField()->getLabel());
        }
    }

    public function testInvalidPublicationDoesNotRetireCurrentRevision(): void
    {
        $template = new ProtocolTemplate();
        $original = new ProtocolTemplateRevision();
        $template->addRevision($original);
        $original->publish(null);
        $invalidDraft = (new ProtocolTemplateRevision())->setRevisionNumber(2);
        $template->addRevision($invalidDraft);
        self::bootKernel();
        $manager = self::getContainer()->get(ProtocolManager::class);

        try {
            $manager->publish($invalidDraft, null);
            self::fail('An empty draft must not be published.');
        } catch (\DomainException) {
            self::assertSame(ProtocolRevisionStatus::Published, $original->getStatus());
            self::assertSame($original, $template->getPublishedRevision());
            self::assertSame(ProtocolRevisionStatus::Draft, $invalidDraft->getStatus());
        }
    }

    public function testMovingSectionsAndFieldsPersistsCollisionFreeOrdering(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $manager = $container->get(ProtocolManager::class);

        $template = (new ProtocolTemplate())->setName('Sortiertest '.bin2hex(random_bytes(4)));
        $revision = $manager->createInitialDraft($template);
        $firstSection = $manager->addSection($revision)
            ->setName('A');
        $secondSection = $manager->addSection($revision)
            ->setName('B');
        $firstField = $manager->addField($firstSection)
            ->setLabel('A1');
        $secondField = $manager->addField($firstSection)
            ->setLabel('A2');
        $entityManager->persist($template);
        $entityManager->flush();

        $manager->moveSection($secondSection, -1);
        $manager->moveField($secondField, -1);
        $entityManager->refresh($firstSection);
        $entityManager->refresh($secondSection);
        $entityManager->refresh($firstField);
        $entityManager->refresh($secondField);

        self::assertSame(1, $firstSection->getPosition());
        self::assertSame(0, $secondSection->getPosition());
        self::assertSame(1, $firstField->getPosition());
        self::assertSame(0, $secondField->getPosition());
    }

    public function testNumberedRunsStaticNotesLayoutAndDynamicFormRoundTrip(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $manager = $container->get(ProtocolManager::class);

        $template = (new ProtocolTemplate())->setName('Integrationstest '.bin2hex(random_bytes(4)));
        $revision = (new ProtocolTemplateRevision())->setRevisionNumber(1);
        $template->addRevision($revision);
        $section = (new ProtocolTemplateSection())->setName('Kanäle');
        $revision->addSection($section);
        $measurement = (new ProtocolTemplateField())->setLabel('I0')
            ->setType(ProtocolFieldType::Decimal)->setRequired(true);
        $result = (new ProtocolTemplateField())->setLabel('Prüfung')
            ->setType(ProtocolFieldType::TestResult)->setPosition(1)
            ->setLayoutColumns(3);
        $note = (new ProtocolTemplateField())->setLabel('Abschlussprüfung')
            ->setType(ProtocolFieldType::StaticNote)->setPosition(2)
            ->setHelpText('Nur nach vollständiger Montage ausfüllen.')
            ->setLayoutColumns(12)
            ->setStartNewRow(true);
        $section->addField($measurement)
            ->addField($result)
            ->addField($note);
        $buildInstance = (new BuildInstance())->setSerialNumber('TEST-'.bin2hex(random_bytes(6)));
        $system = (new \App\Entity\Production\SystemTemplate())->setName('Measurement test system');
        $buildInstance->setSystemTemplate($system);
        $template->addSystemTemplate($system);
        $entityManager->persist($system);
        $entityManager->persist($template);
        $entityManager->persist($buildInstance);
        $manager->publish($revision, null);
        $entityManager->flush();

        $firstRun = $manager->createRun($buildInstance, $revision, null);
        self::assertSame(1, $firstRun->getRunNumber());
        self::assertCount(1, $firstRun->getRows());
        self::assertCount(2, $firstRun->getRowForSection($section)?->getAnswers());
        self::assertNull($firstRun->getRowForSection($section)?->getAnswerForField($note));

        $form = $container->get(FormFactoryInterface::class)->create(ProtocolRunType::class, null, [
            'protocol_run' => $firstRun,
            'csrf_protection' => false,
        ]);
        $submitted = ['protocol_date' => '2026-09-10', 'edit_version' => (string) $firstRun->getVersion()];
        foreach ($firstRun->getRows() as $row) {
            foreach ($row->getAnswers() as $answer) {
                $submitted['answer_'.$answer->getId()] = ProtocolFieldType::Decimal === $answer->getField()?->getType() ? '12,345000000012300' : 'pass';
            }
        }
        $form->submit($submitted);
        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame([], $manager->validateForCompletion($firstRun));

        $firstRun->complete(null);
        $entityManager->flush();
        self::assertSame(ProtocolRunStatus::Completed, $firstRun->getStatus());

        $secondRun = $manager->createRun($buildInstance, $revision, null);
        self::assertSame(2, $secondRun->getRunNumber());
        self::assertSame(ProtocolRunStatus::Draft, $secondRun->getStatus());
        $answerId = $firstRun->getRowForSection($section)->getAnswerForField($measurement)->getId();
        $entityManager->clear();
        self::assertSame('12.345000000012300', $entityManager->find(\App\Entity\Production\ProtocolAnswer::class, $answerId)->getValue());
    }
}
