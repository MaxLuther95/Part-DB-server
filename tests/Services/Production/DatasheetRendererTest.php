<?php

declare(strict_types=1);

namespace App\Tests\Services\Production;

use App\Services\Production\DatasheetRenderer;
use App\Entity\Production\DatasheetBlockType;
use App\Entity\Production\DatasheetTemplate;
use App\Entity\Production\DatasheetTemplateRevision;
use App\Entity\Production\DatasheetTemplateBlock;
use App\Entity\Production\DatasheetTextSize;
use App\Services\Production\DatasheetSourceCatalog;
use App\Tests\Fixtures\MultipageDatasheetExample;
use Dompdf\Adapter\CPDF;
use Dompdf\Dompdf;
use Jbtronics\DompdfFontLoaderBundle\Services\DompdfFactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class DatasheetRendererTest extends KernelTestCase
{
    public function testSpacersRenderBlankAtEachConfiguredHeight(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        foreach (DatasheetTextSize::cases() as $index => $size) {
            $dompdf = new Dompdf();
            $heights = [];
            $dompdf->setCallbacks([['event' => 'begin_frame', 'f' => static function ($frame) use (&$heights): void {
                $node = $frame->get_node();
                if ($node instanceof \DOMElement && 'datasheet-spacer' === $node->getAttribute('class')) {
                    self::assertSame('', $node->textContent, 'A spacer must not print a placeholder.');
                    $heights[] = $frame->get_style()->height;
                }
            }]]);
            $factory = $this->createMock(DompdfFactoryInterface::class);
            $factory->method('create')->willReturn($dompdf);
            $renderer = new DatasheetRenderer($container->get(Environment::class), $factory, $container->get(DatasheetSourceCatalog::class), self::$kernel->getProjectDir());
            $revision = (new DatasheetTemplateRevision())->setTemplate((new DatasheetTemplate())->setName('Spacing test')->setProductTitle('Spacing test'));
            $revision->addBlock((new DatasheetTemplateBlock())->setType(DatasheetBlockType::StaticText)->setText('Before the blank line')->setPosition(0));
            $revision->addBlock((new DatasheetTemplateBlock())->setType(DatasheetBlockType::Spacer)->setTextSize($size)->setPosition(1));
            $revision->addBlock((new DatasheetTemplateBlock())->setType(DatasheetBlockType::StaticText)->setText('After the blank line')->setPosition(2));
            self::assertStringStartsWith('%PDF-', $renderer->renderPdf($revision));
            self::assertCount(1, $heights);
            self::assertEqualsWithDelta(($index + 1) * 4 * 72 / 25.4, $heights[0], .01);
            self::assertSame(1, $dompdf->getCanvas()->get_page_count());
        }
    }

    public function testZeroValuesAreNotReplacedByMissingValueMarkers(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $instance = MultipageDatasheetExample::instance()->setSerialNumber('0');
        $instance->getChildren()->first()->setSerialNumber('0');
        $view = $container->get(DatasheetRenderer::class)->createView(MultipageDatasheetExample::revision(), $instance);
        $html = $container->get(Environment::class)->render('production/datasheet/_document.html.twig', $view);
        self::assertStringContainsString('<div class="datasheet-value">0</div>', $html);
        self::assertStringContainsString('<td>0</td>', $html);
    }

    public function testMultipageOutputRepeatsLogoAndKeepsAllContent(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $dompdf = new Dompdf();
        $factory = $this->createMock(DompdfFactoryInterface::class);
        $factory->method('create')->willReturn($dompdf);
        $renderer = new DatasheetRenderer($container->get(Environment::class), $factory, $container->get(DatasheetSourceCatalog::class), self::$kernel->getProjectDir());
        $pdf = $renderer->renderPdf(MultipageDatasheetExample::revision());
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertFalse($dompdf->getOptions()->getIsRemoteEnabled());
        self::assertFalse($dompdf->getOptions()->getIsPhpEnabled());
        $canvas = $dompdf->getCanvas();
        self::assertInstanceOf(CPDF::class, $canvas);
        self::assertGreaterThanOrEqual(5, $canvas->get_page_count());
        self::assertLessThanOrEqual(12, $canvas->get_page_count(), 'No empty page per overflowing line or row.');
        $objects = $canvas->get_cpdf()->objects;
        $pages = array_filter($objects, static fn (array $object): bool => 'page' === $object['t']);
        self::assertCount($canvas->get_page_count(), $pages);
        foreach ($pages as $page) {
            $commands = implode('', array_map(static fn (int $id): string => $objects[$id]['c'], $page['info']['contents']));
            self::assertMatchesRegularExpression('~/I\d+ Do~', $commands, 'Every page must paint the logo, including the first.');
        }
        $html = $container->get(Environment::class)->render('production/datasheet/pdf.html.twig', [
            ...$renderer->createView(MultipageDatasheetExample::revision()),
            'logo_uri' => 'data:image/jpeg;base64,'.base64_encode(file_get_contents(self::$kernel->getProjectDir().'/public/img/magnicon-logo.jpg')),
        ]);
        self::assertStringContainsString('Measurement 80', $html);
        self::assertStringContainsString('Instruction 90', $html);
        self::assertStringContainsString('End of synthetic document.', $html);
        self::assertStringContainsString('data:image/jpeg;base64,', $html);
        self::assertStringNotContainsString('#24536f', $html);
        self::assertStringContainsString('.datasheet-table th, .datasheet-table td { border:', $html);
    }
}
