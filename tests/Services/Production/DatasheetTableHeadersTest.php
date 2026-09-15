<?php

declare(strict_types=1);

namespace App\Tests\Services\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\DatasheetBlockType;
use App\Entity\Production\DatasheetTableColumn;
use App\Entity\Production\DatasheetTemplate;
use App\Entity\Production\DatasheetTemplateBlock;
use App\Entity\Production\DatasheetTemplateRevision;
use App\Entity\Production\ProtocolTemplate;
use App\Entity\Production\SystemTemplate;
use App\Entity\Production\SystemTemplateSlot;
use App\Services\Production\DatasheetRenderer;
use App\Services\Production\DatasheetTemplateEditor;
use App\Services\Production\DatasheetTemplateManager;
use App\Services\Production\ProtocolManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class DatasheetTableHeadersTest extends KernelTestCase
{
    public function testAutomaticHeadersUseSlotNamesRatherThanRepeatingPositionOne(): void
    {
        self::bootKernel();
        [$revision, $table] = $this->table();
        $parent = new BuildInstance();
        $slotA = (new SystemTemplateSlot())->setName('Amplifier');
        $slotB = (new SystemTemplateSlot())->setName('Power supply')->setPosition(1);
        (new BuildInstance())->setSerialNumber('A')->setInstalledSlot($slotA)->setInstalledSlotIndex(0)->setParent($parent);
        (new BuildInstance())->setSerialNumber('B')->setInstalledSlot($slotB)->setInstalledSlotIndex(0)->setParent($parent);
        $renderer = self::getContainer()->get(DatasheetRenderer::class);
        $view = $renderer->createView($revision, $parent);
        self::assertSame(['Amplifier', 'Power supply'], array_column($view['blocks'][0]['columns'], 'label'));
        self::assertSame(['', ''], array_column($view['blocks'][0]['columns'], 'warning'));
        $slotA->setMaxQuantity(2);
        (new BuildInstance())->setSerialNumber('C')->setInstalledSlot($slotA)->setInstalledSlotIndex(1)->setParent($parent);
        self::assertSame(['Amplifier 1', 'Amplifier 2', 'Power supply'], array_column($renderer->createView($revision, $parent)['blocks'][0]['columns'], 'label'));
        $table->setHeaderSourcePath('child.serial_number')->setHeaderFormat('Board {value}');
        self::assertSame(['Board A', 'Board C', 'Board B'], array_column($renderer->createView($revision, $parent)['blocks'][0]['columns'], 'label'));
    }

    public function testWarningsZeroEscapingAndRemovedHeaderRevision(): void
    {
        self::bootKernel();
        [$revision, $table] = $this->table();
        $table->setHeaderSourcePath('child.slot_name')->setHeaderFormat('Board {value}');
        $parent = new BuildInstance();
        (new BuildInstance())->setSerialNumber('A')->setParent($parent);
        $renderer = self::getContainer()->get(DatasheetRenderer::class);
        $view = $renderer->createView($revision, $parent);
        self::assertSame('Missing heading', $view['blocks'][0]['columns'][0]['warning']);
        self::assertSame('—', $view['blocks'][0]['columns'][0]['label']);
        foreach (['B', 'C'] as $serial) {
            (new BuildInstance())->setSerialNumber($serial)->setInstalledSlot((new SystemTemplateSlot())->setName('0'))->setParent($parent);
        }
        $view = $renderer->createView($revision, $parent);
        self::assertSame(['Board 0', 'Board 0', '—'], array_column($view['blocks'][0]['columns'], 'label'));
        self::assertSame(['Duplicate heading', 'Duplicate heading', 'Missing heading'], array_column($view['blocks'][0]['columns'], 'warning'));
        $twig = self::getContainer()->get(Environment::class);
        $html = $twig->render('production/datasheet/_document.html.twig', $view);
        self::assertStringContainsString('Warning: Duplicate heading', $html);
        self::assertStringNotContainsString('datasheet-revision', $html);
        self::assertStringContainsString('Revision 1', $html);
        self::assertSame([], $renderer->validateForRelease($view), 'Duplicates and missing headings are warnings, not new mandatory fields.');
        self::assertStringNotContainsString('Warning:', $twig->render('production/datasheet/_document.html.twig', $renderer->createView($revision, $parent, false)));
        $table->setHeaderSourcePath('child.serial_number')->setHeaderFormat('<script>{value}</script>');
        self::assertStringContainsString('&lt;script&gt;', $twig->render('production/datasheet/_document.html.twig', $renderer->createView($revision, $parent)));
        self::assertStringStartsWith('%PDF-', $renderer->renderPdf($revision, $parent));
    }

    public function testHeaderValidationAndRevisionCloning(): void
    {
        self::bootKernel();
        [$revision, $table] = $this->table();
        $editor = self::getContainer()->get(DatasheetTemplateEditor::class);
        foreach ([['headerSourcePath' => 'unsafe.expression'], ['headerFormat' => 'Board'], ['headerFormat' => '{value}{value}'], ['headerFormat' => '{unknown} {value}'], ['headerFormat' => str_repeat('x', 256).'{value}']] as $bad) {
            $payload = $editor->export($revision);
            $payload['blocks'][0] = array_replace($payload['blocks'][0], $bad);
            try {
                $editor->preview($revision, json_encode($payload, JSON_THROW_ON_ERROR));
                self::fail('Invalid header configuration accepted.');
            } catch (\DomainException) {
                self::assertSame('{value}', $table->getHeaderFormat());
            }
        }
        $table->setHeaderSourcePath('child.serial_number')->setHeaderFormat('Board {value}');
        $manager = self::getContainer()->get(DatasheetTemplateManager::class);
        $manager->publish($revision, null);
        $clone = $manager->getOrCreateDraft($revision->getTemplate());
        self::assertSame('child.serial_number', $clone->getBlocks()->first()->getHeaderSourcePath());
        self::assertSame('Board {value}', $clone->getBlocks()->first()->getHeaderFormat());
        $clone->getBlocks()->first()->setHeaderFormat('Channel {value}');
        self::assertSame('Board {value}', $table->getHeaderFormat());
        $this->expectException(\LogicException::class);
        $table->setHeaderSourcePath(null);
    }

    public function testProtocolOnlyUsedInHeaderParticipatesInSelectionAndSnapshot(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $manager = self::getContainer()->get(ProtocolManager::class);
        $system = (new SystemTemplate())->setName('Header source test');
        $template = (new ProtocolTemplate())->setName('Channel protocol')->addSystemTemplate($system);
        $protocol = $manager->createInitialDraft($template);
        $section = $manager->addSection($protocol)->setName('Installation');
        $field = $manager->addField($section)->setLabel('Channel');
        $parent = (new BuildInstance())->setSerialNumber('PARENT');
        $child = (new BuildInstance())->setSerialNumber('CHILD')->setSystemTemplate($system)->setParent($parent);
        foreach ([$system, $template, $parent, $child] as $entity) {
            $em->persist($entity);
        }
        $manager->publish($protocol, null);
        $em->flush();
        $first = $manager->createRun($child, $protocol, null);
        $first->getRows()->first()->getAnswers()->first()->setValue('0');
        $first->complete(null);
        $second = $manager->createRun($child, $protocol, null);
        $second->getRows()->first()->getAnswers()->first()->setValue('2');
        $second->complete(null);
        $em->flush();
        $parentId = $parent->getId();
        $childId = $child->getId();
        $em->clear();
        $parent = $em->find(BuildInstance::class, $parentId);
        $child = $em->find(BuildInstance::class, $childId);
        [$revision, $table] = $this->table();
        $table->setHeaderSourcePath('child.protocol.'.$template->getId().'.'.$field->getStableKey())->setHeaderFormat('Channel {value}');
        $renderer = self::getContainer()->get(DatasheetRenderer::class);
        $key = $child->getId().'_'.$template->getId();
        self::assertCount(2, $renderer->collectRunChoices($revision, $parent)[$key]['runs']);
        self::assertNotEmpty($renderer->validateForRelease($renderer->createView($revision, $parent)));
        $view = $renderer->createView($revision, $parent, false, [], [$key => $first->getId()]);
        self::assertSame('Channel 0', $view['blocks'][0]['columns'][0]['label']);
        self::assertSame([], $renderer->validateForRelease($view));
        $snapshot = $renderer->createSourceSnapshot($view, [$key => $first->getId()]);
        self::assertSame([$first->getId()], $snapshot['source_protocol_run_ids']);
        self::assertSame($table->getHeaderSourcePath(), $snapshot['blocks'][0]['header_source']);
        self::assertSame($first->getId(), $snapshot['blocks'][0]['columns'][0]['source_run_id']);
        self::assertNotEmpty($renderer->validateForRelease($renderer->createView($revision, $parent, false, [], [$key => -1])));
    }

    /** @return array{DatasheetTemplateRevision, DatasheetTemplateBlock} */
    private function table(): array
    {
        $template = (new DatasheetTemplate())->setName('Headers')->setProductTitle('Header test');
        $revision = new DatasheetTemplateRevision();
        $template->addRevision($revision);
        $block = (new DatasheetTemplateBlock())->setType(DatasheetBlockType::ChildTable)->setLabel('Components');
        $block->addColumn((new DatasheetTableColumn())->setLabel('Serial')->setSourcePath('child.serial_number'));
        $revision->addBlock($block);

        return [$revision, $block];
    }
}
