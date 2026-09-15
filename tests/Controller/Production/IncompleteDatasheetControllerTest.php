<?php

declare(strict_types=1);

namespace App\Tests\Controller\Production;

use App\Entity\Production\DatasheetDocument;
use App\Services\Production\DatasheetTemplateManager;
use App\Services\Production\ProtocolManager;
use App\Services\Production\DatasheetDocumentStorage;
use App\Tests\Fixtures\IncompleteDatasheetScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class IncompleteDatasheetControllerTest extends WebTestCase
{
    public function testWarningPreservesAllInputsAndExplicitAcceptanceReleasesPdf(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $client->catchExceptions(false);
        $services = self::getContainer();
        $em = $services->get(EntityManagerInterface::class);
        $scenario = IncompleteDatasheetScenario::create($em, $services->get(ProtocolManager::class), $services->get(DatasheetTemplateManager::class));
        $filesystem = new Filesystem();
        $directory = sys_get_temp_dir().'/partdb-incomplete-datasheet-'.bin2hex(random_bytes(8));
        $services->set(DatasheetDocumentStorage::class, new DatasheetDocumentStorage($directory, $filesystem));
        try {
            $client->loginUser($scenario['run']->getStartedBy());
            $url = '/en/production/build-instances/'.$scenario['instance']->getId().'/datasheets/'.$scenario['template']->getId();
            $crawler = $client->request('GET', $url.'/prepare');
            $values = $crawler->selectButton('Offizielles Datenblatt freigeben')->form()->getPhpValues();
            $values['notes'][$scenario['requiredNote']->getStableKey()] = '';
            $values['notes'][$scenario['optionalNote']->getStableKey()] = '<script>Customer note</script>';
            $values['runs'][$scenario['selectionKey']] = (string) $scenario['second']->getId();
            $values['additional_notes'][0] = ['title' => 'Conditions', 'text' => 'Keep this note', 'width' => '6'];
            $client->request('POST', $url.'/release', $values);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('[data-incomplete-warning]', 'Device comment');
            self::assertSelectorTextContains('[data-incomplete-warning]', 'Required customer statement');
            self::assertSelectorTextSame('textarea[name="notes['.$scenario['requiredNote']->getStableKey().']"]', '');
            self::assertSelectorTextSame('textarea[name="notes['.$scenario['optionalNote']->getStableKey().']"]', '<script>Customer note</script>');
            self::assertSelectorTextSame('textarea[name="additional_notes[0][text]"]', 'Keep this note');
            self::assertSelectorExists('select[name="runs['.$scenario['selectionKey'].']"] option[value="'.$scenario['second']->getId().'"][selected]');
            self::assertSelectorExists('select[name="additional_notes[0][width]"] option[value="6"][selected]');
            self::assertSame(0, $em->getRepository(DatasheetDocument::class)->count([]));

            $values = $client->getCrawler()->selectButton('Offizielles Datenblatt freigeben')->form()->getPhpValues();
            $client->request('POST', $url.'/release', $values);
            self::assertResponseStatusCodeSame(422, 'Seeing a warning is not acceptance.');
            $values['_accept_incomplete'] = '1';
            $client->request('POST', $url.'/release', $values);
            self::assertResponseRedirects('/en/production/build-instances/'.$scenario['instance']->getId());
            $document = $em->getRepository(DatasheetDocument::class)->findOneBy(['buildInstance' => $scenario['instance']]);
            self::assertInstanceOf(DatasheetDocument::class, $document);
            self::assertNotEmpty($document->getSourceSnapshot()['accepted_incomplete_fields']);
            self::assertSame([$scenario['second']->getId()], $document->getSourceSnapshot()['source_protocol_run_ids']);
            self::assertSame('Keep this note', $document->getSourceSnapshot()['additional_notes'][0]['text']);
            self::assertSame('', $document->getSourceSnapshot()['blocks'][2]['value']);
            $client->request('GET', '/en/production/datasheets/'.$document->getId().'/download');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'application/pdf');
            self::assertStringStartsWith('%PDF-', file_get_contents($directory.'/'.$document->getStoredFilename()));
        } finally {
            $filesystem->remove($directory);
        }
    }

    public function testChangedInputsAndAmbiguousSourcesCannotReuseConfirmation(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $scenario = IncompleteDatasheetScenario::create($em, self::getContainer()->get(ProtocolManager::class), self::getContainer()->get(DatasheetTemplateManager::class));
        $client->loginUser($scenario['run']->getStartedBy());
        $url = '/en/production/build-instances/'.$scenario['instance']->getId().'/datasheets/'.$scenario['template']->getId();
        $crawler = $client->request('GET', $url.'/prepare');
        $values = $crawler->selectButton('Offizielles Datenblatt freigeben')->form()->getPhpValues();
        $values['runs'][$scenario['selectionKey']] = (string) $scenario['run']->getId();
        $client->request('POST', $url.'/release', $values);
        self::assertResponseStatusCodeSame(422);
        $values = $client->getCrawler()->selectButton('Offizielles Datenblatt freigeben')->form()->getPhpValues();
        $values['_accept_incomplete'] = '1';
        $values['notes'][$scenario['optionalNote']->getStableKey()] = 'Changed after warning';
        $client->request('POST', $url.'/release', $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('textarea[name="notes['.$scenario['optionalNote']->getStableKey().']"]', 'Changed after warning');
        self::assertSelectorNotExists('[name="_accept_incomplete"][checked]');
        $values = $client->getCrawler()->selectButton('Offizielles Datenblatt freigeben')->form()->getPhpValues();
        $values['_accept_incomplete'] = '1';
        $values['runs'][$scenario['selectionKey']] = '999999999';
        $client->request('POST', $url.'/release', $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[data-release-errors]');
        self::assertSelectorTextContains('textarea[name="notes['.$scenario['optionalNote']->getStableKey().']"]', 'Changed after warning');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(0, $em->getRepository(DatasheetDocument::class)->count([]));
    }
}
