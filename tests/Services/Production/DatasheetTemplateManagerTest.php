<?php

declare(strict_types=1);

namespace App\Tests\Services\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\Customer;
use App\Entity\Production\CustomerProject;
use App\Entity\Production\DatasheetBlockType;
use App\Entity\Production\DatasheetFontFamily;
use App\Entity\Production\DatasheetTableColumn;
use App\Entity\Production\DatasheetTemplate;
use App\Entity\Production\DatasheetTextAlignment;
use App\Entity\Production\DatasheetTextSize;
use App\Entity\Production\ProductionProject;
use App\Entity\Production\ProjectPosition;
use App\Entity\Production\SystemTemplate;
use App\Services\Production\DatasheetRenderer;
use App\Services\Production\DatasheetSourceCatalog;
use App\Services\Production\DatasheetTemplateEditor;
use App\Services\Production\DatasheetTemplateManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DatasheetTemplateManagerTest extends KernelTestCase
{
    private DatasheetTemplateManager $manager;
    private DatasheetRenderer $renderer;
    private DatasheetSourceCatalog $sourceCatalog;
    private DatasheetTemplateEditor $editor;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->manager = $container->get(DatasheetTemplateManager::class);
        $this->renderer = $container->get(DatasheetRenderer::class);
        $this->sourceCatalog = $container->get(DatasheetSourceCatalog::class);
        $this->editor = $container->get(DatasheetTemplateEditor::class);
    }

    public function testNewTemplateStartsWithOnlyTheFixedDocumentFrame(): void
    {
        $template = (new DatasheetTemplate())->setName('Electronics')
            ->setProductTitle('Electronics Data Sheet');
        $revision = $this->manager->createInitialDraft($template);

        self::assertCount(0, $revision->getBlocks());
        self::assertSame(1, $revision->getRevisionNumber());
        self::assertSame('Electronics Data Sheet', $revision->getTemplate()?->getProductTitle());
        self::assertSame([], $this->manager->validateForPublishing($revision));

        $view = $this->renderer->createView($revision);
        self::assertSame(['Customer', 'Project', 'Order no.'], array_column($view['fixed_fields'], 'label'));

        $pdf = $this->renderer->renderPdf($revision);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(1000, strlen($pdf));
    }

    public function testPublishedRevisionIsImmutableAndCloneKeepsStableKeys(): void
    {
        $template = (new DatasheetTemplate())->setName('Electronics')
            ->setProductTitle('Electronics Data Sheet');
        $published = $this->manager->createInitialDraft($template);
        $firstBlock = $this->manager->addBlock($published)
            ->setType(DatasheetBlockType::Heading)
            ->setLabel('Identification');
        $firstBlock->setTextSize(DatasheetTextSize::Large)
            ->setFontFamily(DatasheetFontFamily::Serif)
            ->setTextAlignment(DatasheetTextAlignment::Center)
            ->setTextBold(true)
            ->setTextItalic(true)
            ->setTextUnderlined(true);
        $blockKey = $firstBlock->getStableKey();
        $this->manager->publish($published, null);

        try {
            $published->getBlocks()
                ->first()
                ->setLabel('Changed');
            self::fail('A published datasheet revision must be immutable.');
        } catch (\LogicException) {
            self::assertSame('Identification', $published->getBlocks()->first()->getLabel());
        }
        $draft = $this->manager->getOrCreateDraft($template);
        self::assertSame(2, $draft->getRevisionNumber());
        self::assertSame($blockKey, $draft->getBlocks()->first()->getStableKey());
        self::assertNotSame($published->getBlocks()->first(), $draft->getBlocks()->first());

        self::assertSame(DatasheetTextSize::Large, $draft->getBlocks()->first()->getTextSize());
        self::assertSame(DatasheetFontFamily::Serif, $draft->getBlocks()->first()->getFontFamily());
        self::assertSame(DatasheetTextAlignment::Center, $draft->getBlocks()->first()->getTextAlignment());
        self::assertTrue($draft->getBlocks()->first()->isTextBold());
        self::assertTrue($draft->getBlocks()->first()->isTextItalic());
        self::assertTrue($draft->getBlocks()->first()->isTextUnderlined());
    }

    public function testUnknownSourceCannotBePublished(): void
    {
        $template = (new DatasheetTemplate())->setName('Invalid')
            ->setProductTitle('Invalid');
        $revision = $this->manager->createInitialDraft($template);
        $this->manager->addBlock($revision)
            ->setType(DatasheetBlockType::Value)
            ->setLabel('Unsafe')
            ->setSourcePath('unsafe.expression.phpinfo');

        self::assertNotEmpty($this->manager->validateForPublishing($revision));
        $this->expectException(\DomainException::class);
        $this->manager->publish($revision, null);
    }

    public function testEditorRefusesToOverwriteAChangedDraft(): void
    {
        $template = (new DatasheetTemplate())->setName('Concurrent editor')
            ->setProductTitle('Initial title');
        $revision = $this->manager->createInitialDraft($template);
        $stalePayload = $this->editor->export($revision);

        $this->manager->addBlock($revision)
            ->setType(DatasheetBlockType::Heading)
            ->setLabel('Change from another browser window');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('zwischenzeitlich');
        $this->editor->save($revision, json_encode($stalePayload, JSON_THROW_ON_ERROR));
    }

    public function testInstanceAndChildSourcesResolveWithoutExpressions(): void
    {
        $parent = (new BuildInstance())->setSerialNumber('CID688');
        $child = (new BuildInstance())->setSerialNumber('BID1292')
            ->setParent($parent);

        self::assertSame('CID688', $this->sourceCatalog->resolve('instance.serial_number', $parent));
        self::assertSame('BID1292', $this->sourceCatalog->resolve('child.serial_number', $child));
        self::assertSame('', $this->sourceCatalog->resolve('unsafe.expression', $parent));
    }

    public function testSingleValueCanResolveAnInstalledBoardByPhysicalPosition(): void
    {
        $instance = (new BuildInstance())->setSerialNumber('CID688');
        (new BuildInstance())->setSerialNumber('BID1293')
            ->setInstalledSlotIndex(1)
            ->setParent($instance);
        (new BuildInstance())->setSerialNumber('BID1292')
            ->setInstalledSlotIndex(0)
            ->setParent($instance);

        self::assertTrue($this->sourceCatalog->isKnownRootSource('child_position.1.serial_number'));
        self::assertSame('Eingebaute Komponente · Position 1 · Seriennummer / ID', $this->sourceCatalog->describe('child_position.1.serial_number'));
        self::assertSame('BID1292', $this->sourceCatalog->resolve('child_position.1.serial_number', $instance));
        self::assertSame('BID1293', $this->sourceCatalog->resolve('child_position.2.serial_number', $instance));
        self::assertSame('', $this->sourceCatalog->resolve('child_position.3.serial_number', $instance));
    }

    public function testComponentMatrixUsesComponentsAsColumnsAndConfiguredValuesAsRows(): void
    {
        $template = (new DatasheetTemplate())->setName('System')
            ->setProductTitle('System Data Sheet');
        $revision = $this->manager->createInitialDraft($template);
        $table = $this->manager->addBlock($revision)
            ->setType(DatasheetBlockType::ChildTable)
            ->setLabel('Installed components');
        $table->addColumn((new DatasheetTableColumn())->setLabel('Board #')->setSourcePath('child.serial_number'));
        $table->addColumn((new DatasheetTableColumn())->setLabel('Type')->setSourcePath('child.product_name')->setPosition(1));

        $parent = (new BuildInstance())->setSerialNumber('CID688');
        $componentType = (new SystemTemplate())->setName('SEL');
        (new BuildInstance())->setSerialNumber('1249')
            ->setSystemTemplate($componentType)
            ->setInstalledSlotIndex(0)
            ->setParent($parent);
        (new BuildInstance())->setSerialNumber('1255')
            ->setSystemTemplate($componentType)
            ->setInstalledSlotIndex(1)
            ->setParent($parent);

        $view = $this->renderer->createView($revision, $parent);
        $matrix = $view['blocks'][0];
        self::assertSame(['Position 1', 'Position 2'], array_column($matrix['columns'], 'label'));
        self::assertSame(['Board #', 'Type'], array_map(static fn (array $row): string => $row['definition']->getLabel(), $matrix['rows']));
        self::assertSame(['1249', '1255'], array_column($matrix['rows'][0]['cells'], 'value'));
        self::assertSame(['SEL', 'SEL'], array_column($matrix['rows'][1]['cells'], 'value'));
    }

    public function testFixedFrameSeparatesCustomerProjectAndOrderNumber(): void
    {
        $customer = (new Customer())->setName('Example customer')
            ->setCustomerNumber('C-42');
        $project = (new ProductionProject())->setName('Amplifier family')
            ->setProjectNumber('P-17');
        $order = (new CustomerProject())->setName('Customer delivery')
            ->setProjectNumber('O-88')
            ->setCustomer($customer)
            ->setProductionProject($project);
        $position = (new ProjectPosition())->setName('Cable assembly')
            ->setCustomerProject($order);
        $instance = (new BuildInstance())->setSerialNumber('CC0452')
            ->setProjectPosition($position);
        $template = (new DatasheetTemplate())->setName('Cable')
            ->setProductTitle('Cable Data Sheet');

        $view = $this->renderer->createView($this->manager->createInitialDraft($template), $instance);

        self::assertSame(['Example customer', 'P-17', 'O-88'], array_column($view['fixed_fields'], 'value'));
        self::assertTrue($this->sourceCatalog->isKnownRootSource('instance.location'));
        self::assertTrue($this->sourceCatalog->isKnownRootSource('instance.planned_delivery_date'));
        self::assertTrue($this->sourceCatalog->isKnownChildSource('child.order_date'));
    }
}
