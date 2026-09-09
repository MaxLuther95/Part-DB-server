<?php

declare(strict_types=1);

namespace App\Tests\Controller\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\DatasheetBlockType;
use App\Entity\Production\DatasheetDocument;
use App\Entity\Production\DatasheetFontFamily;
use App\Entity\Production\DatasheetTableColumn;
use App\Entity\Production\DatasheetTemplate;
use App\Entity\Production\DatasheetTemplateRevision;
use App\Entity\Production\DatasheetTextAlignment;
use App\Entity\Production\DatasheetTextSize;
use App\Entity\Production\SystemTemplate;
use App\Entity\Production\SystemTemplateSlot;
use App\Entity\UserSystem\User;
use App\Services\Production\DatasheetDocumentStorage;
use App\Services\Production\DatasheetTemplateManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class DatasheetTemplateControllerTest extends WebTestCase
{
    public function testSchemaBackedDesignerChangesEveryPropertyAndKeepsIdentityWhenReordered(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $client->catchExceptions(false);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $manager = self::getContainer()->get(DatasheetTemplateManager::class);
        $admin = $entityManager->getRepository(User::class)->findOneBy([
            'name' => 'admin',
        ]);
        self::assertInstanceOf(User::class, $admin);
        $admin->setNeedPwChange(false);
        $client->loginUser($admin);

        $template = (new DatasheetTemplate())
            ->setName('Unified editor '.bin2hex(random_bytes(4)))
            ->setProductTitle('Initial title');
        $revision = $manager->createInitialDraft($template);
        $heading = $manager->addBlock($revision)
            ->setType(DatasheetBlockType::Heading)
            ->setLabel('Initial heading');
        $value = $manager->addBlock($revision)
            ->setType(DatasheetBlockType::Value)
            ->setLabel('Initial value')
            ->setSourcePath('instance.serial_number');
        $entityManager->persist($template);
        $entityManager->flush();
        $revisionId = $revision->getId();
        $headingKey = $heading->getStableKey();
        $valueKey = $value->getStableKey();

        $crawler = $client->request('GET', '/en/production/datasheet-revisions/'.$revisionId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Bausteine');
        self::assertSelectorTextContains('body', 'Eigenschaften');
        self::assertSelectorExists('[data-block-type="child_table"]');
        self::assertSelectorExists('.datasheet-designer-workspace');
        self::assertSelectorExists('.datasheet-designer-page');
        self::assertSelectorExists('#datasheet-designer-product-title');
        self::assertSelectorExists('#datasheet-designer-source');
        self::assertSelectorTextContains('body', '25 %');
        self::assertCount(0, $crawler->filter('form[data-controller="production--datasheet-designer"] .col-form-label'));
        self::assertCount(0, $crawler->filter('form[data-controller="production--datasheet-designer"] .col-sm-3'));

        $form = $crawler->selectButton('Entwurf speichern')
            ->form();
        $payload = json_decode($crawler->filter('textarea[name="editor_payload"]')->text(), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame(1, $payload['schemaVersion']);
        self::assertCount(2, $payload['blocks']);
        self::assertStringContainsString('child_position.1.serial_number', $crawler->filter('textarea[data-production--datasheet-designer-target="catalog"]')->text());

        $payload['productTitle'] = 'Configurable Electronics Data Sheet';
        $payload['changeNote'] = 'Complete designer test';
        $payload['blocks'][0]['label'] = 'Styled heading';
        $payload['blocks'][0]['textSize'] = DatasheetTextSize::ExtraLarge->value;
        $payload['blocks'][0]['fontFamily'] = DatasheetFontFamily::Serif->value;
        $payload['blocks'][0]['textAlignment'] = DatasheetTextAlignment::Center->value;
        $payload['blocks'][0]['textBold'] = true;
        $payload['blocks'][0]['textItalic'] = true;
        $payload['blocks'][0]['textUnderlined'] = true;
        $payload['blocks'][0]['layoutColumns'] = 6;
        $matrix = [
            'key' => null,
            'type' => DatasheetBlockType::ChildTable->value,
            'label' => 'Installed electronics',
            'text' => '',
            'sourcePath' => null,
            'textSize' => DatasheetTextSize::Normal->value,
            'fontFamily' => DatasheetFontFamily::SansSerif->value,
            'textAlignment' => DatasheetTextAlignment::Left->value,
            'textBold' => false,
            'textItalic' => false,
            'textUnderlined' => false,
            'layoutColumns' => 12,
            'startNewRow' => true,
            'required' => false,
            'hideIfEmpty' => false,
            'minimumRows' => 0,
            'maximumRows' => 3,
            'columns' => [
                [
                    'key' => null,
                    'label' => 'Board #',
                    'sourcePath' => 'child.serial_number',
                    'unit' => '',
                    'required' => true,
                ],
            ],
        ];
        $payload['blocks'] = [$matrix, $payload['blocks'][1], $payload['blocks'][0]];
        $form['editor_payload'] = json_encode($payload, JSON_THROW_ON_ERROR);
        $client->submit($form);
        self::assertResponseRedirects('/en/production/datasheet-revisions/'.$revisionId);

        $entityManager->clear();
        $savedRevision = $entityManager->find(DatasheetTemplateRevision::class, $revisionId);
        self::assertInstanceOf(DatasheetTemplateRevision::class, $savedRevision);
        self::assertSame('Configurable Electronics Data Sheet', $savedRevision->getTemplate()?->getProductTitle());
        self::assertSame('Complete designer test', $savedRevision->getChangeNote());
        self::assertCount(3, $savedRevision->getBlocks());
        $savedMatrix = $savedRevision->getBlocks()
            ->first();
        self::assertSame(DatasheetBlockType::ChildTable, $savedMatrix->getType());
        self::assertTrue($savedMatrix->isStartNewRow());
        self::assertCount(1, $savedMatrix->getColumns());
        self::assertSame('Board #', $savedMatrix->getColumns()->first()->getLabel());
        self::assertTrue($savedMatrix->getColumns()->first()->isRequired());
        self::assertSame($valueKey, $savedRevision->getBlocks()->get(1)->getStableKey());

        $savedHeading = $savedRevision->getBlocks()
            ->last();
        self::assertSame($headingKey, $savedHeading->getStableKey());
        self::assertSame('Styled heading', $savedHeading->getLabel());
        self::assertSame(DatasheetTextSize::ExtraLarge, $savedHeading->getTextSize());
        self::assertSame(DatasheetFontFamily::Serif, $savedHeading->getFontFamily());
        self::assertSame(DatasheetTextAlignment::Center, $savedHeading->getTextAlignment());
        self::assertTrue($savedHeading->isTextBold());
        self::assertTrue($savedHeading->isTextItalic());
        self::assertTrue($savedHeading->isTextUnderlined());
        self::assertSame(6, $savedHeading->getLayoutColumns());

        $crawler = $client->request('GET', '/en/production/datasheet-revisions/'.$revisionId);
        $payload = json_decode($crawler->filter('textarea[name="editor_payload"]')->text(), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $payload['productTitle'] = 'Must not be persisted';
        $payload['blocks'][1]['sourcePath'] = 'unsafe.expression.phpinfo';
        $form = $crawler->selectButton('Entwurf speichern')
            ->form();
        $form['editor_payload'] = json_encode($payload, JSON_THROW_ON_ERROR);
        $client->submit($form);
        self::assertResponseRedirects('/en/production/datasheet-revisions/'.$revisionId);

        $entityManager->clear();
        $unchangedRevision = $entityManager->find(DatasheetTemplateRevision::class, $revisionId);
        self::assertInstanceOf(DatasheetTemplateRevision::class, $unchangedRevision);
        self::assertSame('Configurable Electronics Data Sheet', $unchangedRevision->getTemplate()?->getProductTitle());
        self::assertSame('instance.serial_number', $unchangedRevision->getBlocks()->get(1)->getSourcePath());
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'unbekannte Datenquelle');
    }

    public function testEditorPreviewReleaseAndProtectedDownloadWorkflow(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $client->catchExceptions(false);
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $manager = $container->get(DatasheetTemplateManager::class);
        $admin = $entityManager->getRepository(User::class)->findOneBy([
            'name' => 'admin',
        ]);
        self::assertInstanceOf(User::class, $admin);
        $admin->setNeedPwChange(false);
        $client->loginUser($admin);

        $filesystem = new Filesystem();
        $storageDirectory = sys_get_temp_dir().'/partdb-datasheet-http-'.bin2hex(random_bytes(8));
        $container->set(DatasheetDocumentStorage::class, new DatasheetDocumentStorage($storageDirectory, $filesystem));

        try {
            $template = (new DatasheetTemplate())
                ->setName('HTTP Electronics '.bin2hex(random_bytes(4)))
                ->setProductTitle('Electronics Data Sheet');
            $revision = $manager->createInitialDraft($template);
            $manager->addBlock($revision)
                ->setType(DatasheetBlockType::Value)->setLabel('Case ID')->setSourcePath('instance.serial_number');
            $matrix = $manager->addBlock($revision)
                ->setType(DatasheetBlockType::ChildTable)->setLabel('Electronics and measurements');
            $matrix->addColumn((new DatasheetTableColumn())->setLabel('Board #')->setSourcePath('child.serial_number'));
            $note = $manager->addBlock($revision)
                ->setType(DatasheetBlockType::EditableNote)->setLabel('Additional information');
            $caseType = (new SystemTemplate())->setName('Case electronics');
            $boardType = (new SystemTemplate())->setName('SEL electronics');
            $slot = (new SystemTemplateSlot())->setName('Board position')
                ->setMaxQuantity(3);
            $caseType->addSlot($slot);
            $instance = (new BuildInstance())->setSerialNumber('CID-HTTP-'.bin2hex(random_bytes(4)))->setSystemTemplate($caseType);
            $entityManager->persist($template);
            $entityManager->persist($caseType);
            $entityManager->persist($boardType);
            $entityManager->persist($instance);
            for ($index = 0; $index < 3; ++$index) {
                $child = (new BuildInstance())->setSerialNumber('BID-HTTP-'.($index + 1))->setSystemTemplate($boardType)->setParent($instance)->setInstalledSlot($slot)->setInstalledSlotIndex($index);
                $entityManager->persist($child);
            }
            $manager->publish($revision, $admin);
            $entityManager->flush();

            $client->request('GET', '/en/production/datasheet-templates');
            self::assertResponseIsSuccessful();
            $crawler = $client->request('GET', '/en/production/datasheet-templates/'.$template->getId());
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Electronics Data Sheet');

            $client->request('GET', '/en/production/datasheet-revisions/'.$revision->getId().'/preview.pdf');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'application/pdf');
            self::assertStringStartsWith('%PDF-', (string) $client->getResponse()->getContent());

            $crawler = $client->request('GET', sprintf('/en/production/build-instances/%d/datasheets/%d/prepare', $instance->getId(), $template->getId()));
            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter('textarea[name^="notes["]'));
            self::assertSelectorTextContains('button.btn-success', 'Offizielles Datenblatt freigeben');

            $form = $crawler->selectButton('Offizielles Datenblatt freigeben')
                ->form();
            $values = $form->getPhpValues();
            $values['notes'][$note->getStableKey()] = 'Customer-visible note.';
            $values['additional_notes'][0] = [
                'title' => 'Test conditions',
                'text' => 'Measured at room temperature.',
                'width' => '6',
            ];
            $values['runs']['999_999'] = '999';
            $client->request('POST', $form->getUri(), $values);
            self::assertResponseRedirects('/en/production/build-instances/'.$instance->getId());

            $document = $entityManager->getRepository(DatasheetDocument::class)->findOneBy([
                'buildInstance' => $instance,
                'template' => $template,
            ]);
            self::assertInstanceOf(DatasheetDocument::class, $document);
            self::assertSame(1, $document->getDocumentRevision());
            self::assertSame('Customer-visible note.', $document->getSourceSnapshot()['blocks'][2]['value']);
            self::assertSame(['Position 1', 'Position 2', 'Position 3'], array_column($document->getSourceSnapshot()['blocks'][1]['columns'], 'label'));
            self::assertSame('Measured at room temperature.', $document->getSourceSnapshot()['additional_notes'][0]['text']);
            self::assertSame([], $document->getSourceSnapshot()['source_protocol_run_ids']);
            self::assertFileExists($storageDirectory.'/'.$document->getStoredFilename());

            $client->request('GET', '/en/production/datasheets/'.$document->getId().'/download');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'application/pdf');
            self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        } finally {
            $filesystem->remove($storageDirectory);
        }
    }
}
