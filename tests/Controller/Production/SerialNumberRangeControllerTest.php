<?php

declare(strict_types=1);

namespace App\Tests\Controller\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\SerialNumberRange;
use App\Entity\Production\SystemTemplate;
use App\Entity\ProjectSystem\Project;
use App\Entity\UserSystem\User;
use App\Services\Production\SerialNumberManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SerialNumberRangeControllerTest extends WebTestCase
{
    public function testInvalidConfigurationIsRejectedWithoutServerErrors(): void
    {
        $client = $this->admin();
        foreach ([['nextNumber', ''], ['nextNumber', '0'], ['minimumDigits', ''], ['minimumDigits', '10'], ['prefix', 'AB'], ['prefix', 'ABCDE'], ['prefix', 'A-B']] as [$field, $value]) {
            $crawler = $client->request('GET', '/en/production/serial-number-ranges/new');
            $client->submit($crawler->selectButton('Speichern')->form([
                'serial_number_range[name]' => 'Invalid',
                'serial_number_range[prefix]' => 'TST',
                'serial_number_range['.$field.']' => $value,
            ]));
            self::assertResponseStatusCodeSame(422);
        }
    }

    private function admin(): KernelBrowser
    {
        $client = self::createClient();
        $client->catchExceptions(false);
        $admin = self::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->findOneBy([
            'name' => 'admin',
        ]);
        $admin->setNeedPwChange(false);
        $client->loginUser($admin);

        return $client;
    }

    public function testCreateAssignSuggestRegisterAndEdit(): void
    {
        $client = $this->admin();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $board = (new Project())->setName('Serial test board');
        $system = (new SystemTemplate())->setName('Serial test system');
        $em->persist($board);
        $em->persist($system);
        $em->flush();
        $boardId = $board->getId();
        $systemId = $system->getId();
        $crawler = $client->request('GET', '/en/production/serial-number-ranges/new');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Speichern')->form([
            'serial_number_range[name]' => 'Boards',
            'serial_number_range[prefix]' => 'bid',
            'serial_number_range[nextNumber]' => 1235,
            'serial_number_range[minimumDigits]' => 4,
            'serial_number_range[projects]' => [$boardId],
            'serial_number_range[systems]' => [$systemId],
        ]));
        self::assertResponseRedirects('/en/production/serial-number-ranges');
        $client->followRedirect();
        self::assertSelectorTextContains('table', 'BID');
        self::assertSelectorTextContains('table', 'Serial test board');
        $client->request('GET', '/en/production/serial-number-ranges/suggest/project/'.$boardId);
        self::assertResponseIsSuccessful();
        self::assertSame('1235', json_decode($client->getResponse()->getContent(), true)['number']);
        $crawler = $client->request('GET', '/en/production/build-instances/new');
        self::assertSelectorExists('[data-serial-prefix]');
        self::assertSelectorExists('[data-serial-confirmed]');
        $form = $crawler->selectButton('Save')
            ->form([
                'build_instance[serialNumber][prefix]' => 'BID',
                'build_instance[serialNumber][number]' => '1235',
                'build_instance[serialNumber][confirmed]' => '1',
                'build_instance[content]' => 'project_'.$boardId,
            ]);
        $client->submit($form);
        self::assertResponseRedirects();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $instance = $em->getRepository(BuildInstance::class)->findOneBy([
            'serialNumber' => 'BID-1235',
        ]);
        self::assertNotNull($instance);
        self::assertSame('BID', $instance->getSerialNumberRange()->getPrefix());
        $id = $instance->getId();
        $client->request('GET', '/en/production/serial-number-ranges/suggest/system/'.$systemId);
        self::assertSame('1236', json_decode($client->getResponse()->getContent(), true)['number']);
        $crawler = $client->request('GET', '/en/production/build-instances/'.$id.'/edit');
        self::assertInputValueSame('build_instance[serialNumber][prefix]', 'BID');
        self::assertInputValueSame('build_instance[serialNumber][number]', '1235');
        $client->submit($crawler->selectButton('Save')->form([
            'build_instance[serialNumber][confirmed]' => '1',
            'build_instance[notes]' => 'Edited',
        ]));
        self::assertResponseRedirects();
    }

    public function testConfirmationAndDuplicateAndAssignmentConflict(): void
    {
        $client = $this->admin();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $system = (new SystemTemplate())->setName('Confirm system');
        $range = (new SerialNumberRange())->setName('Boards')
            ->setPrefix('BID')
            ->addSystem($system);
        $em->persist($system);
        $em->persist($range);
        $em->flush();
        $systemId = $system->getId();
        $crawler = $client->request('GET', '/en/production/build-instances/new');
        $values = [
            'build_instance[serialNumber][prefix]' => 'BID',
            'build_instance[serialNumber][number]' => '0001',
            'build_instance[content]' => 'system_'.$systemId,
        ];
        $client->submit($crawler->selectButton('Save')->form($values));
        self::assertSelectorTextContains('.card-body', 'ausdrücklich bestätigen');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->getRepository(BuildInstance::class)->findOneBy([
            'serialNumber' => 'BID-0001',
        ]));
        $crawler = $client->request('GET', '/en/production/serial-number-ranges/new');
        $client->submit($crawler->selectButton('Speichern')->form([
            'serial_number_range[name]' => 'Other',
            'serial_number_range[prefix]' => 'CID',
            'serial_number_range[systems]' => [$systemId],
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.card-body', 'bereits dem Nummernkreis');
    }

    public function testAdminOnlyAndCsrfAndStaleCounter(): void
    {
        $client = $this->admin();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $range = (new SerialNumberRange())->setName('Guard')
            ->setPrefix('GRD');
        $em->persist($range);
        $em->flush();
        $id = $range->getId();
        $url = '/en/production/serial-number-ranges/'.$id.'/edit';
        $crawler = $client->request('GET', $url);
        $form = $crawler->selectButton('Speichern')
            ->form();
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('UPDATE production_serial_number_ranges SET next_number=2, version=version+1 WHERE id=?', [$id]);
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.card-body', 'inzwischen geändert');
        $crawler = $client->request('GET', $url);
        $form = $crawler->selectButton('Speichern')
            ->form([
                'serial_number_range[_token]' => 'invalid',
            ]);
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy([
            'name' => 'user',
        ]);
        $user->setNeedPwChange(false);
        $client->loginUser($user);
        $client->catchExceptions(true);
        $client->request('GET', '/en/production/serial-number-ranges');
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $url, $form->getPhpValues());
        self::assertResponseStatusCodeSame(403);
    }

    public function testSharedSequenceManualEntryDuplicateRollbackAndImmutableExistingIdentifier(): void
    {
        $this->admin();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $numbers = self::getContainer()->get(SerialNumberManager::class);
        $sel = (new SystemTemplate())->setName('SEL');
        $cse = (new SystemTemplate())->setName('CSE');
        $case = (new SystemTemplate())->setName('Case');
        $bid = (new SerialNumberRange())->setName('Boards')
            ->setPrefix('BID')
            ->addSystem($sel)
            ->addSystem($cse);
        $cid = (new SerialNumberRange())->setName('Cases')
            ->setPrefix('CID')
            ->addSystem($case);
        foreach ([$sel, $cse, $case, $bid, $cid] as $entity) {
            $em->persist($entity);
        } $em->flush();
        $db = $em->getConnection();
        $instance = (new BuildInstance())->setSystemTemplate($sel)
            ->setSerialNumber('BID-1249');
        $db->beginTransaction();
        $numbers->claim($instance, true);
        $em->persist($instance);
        $em->flush();
        $db->commit();
        self::assertSame('1250', $numbers->suggest($cse)['number']);
        self::assertSame('0001', $numbers->suggest($case)['number']);
        $db->beginTransaction();
        $numbers->claim((new BuildInstance())->setSystemTemplate($cse)->setSerialNumber('BID-1300'), true);
        $db->rollBack();
        self::assertSame('1250', $numbers->suggest($cse)['number']);
        $duplicate = (new BuildInstance())->setSystemTemplate($cse)
            ->setSerialNumber('BID-1249');
        $db->beginTransaction();
        try {
            $numbers->claim($duplicate, true);
            self::fail('Duplicate accepted');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('bereits vergeben', $error->getMessage());
        }
        $em->refresh($bid);
        $bid->setPrefix('BRD');
        $em->flush();
        $numbers->claim($instance, true, 'BID-1249');
        self::assertSame('BID-1249', $instance->getSerialNumber());
        $db->rollBack();
    }

    public function testWizardSuggestsRequiresConfirmationAndFinalizesReviewedNumber(): void
    {
        $client = $this->admin();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $system = (new SystemTemplate())->setName('Wizard system');
        $range = (new SerialNumberRange())->setName('Wizard')
            ->setPrefix('WIZ')
            ->addSystem($system);
        $site = (new \App\Entity\Parts\StorageLocation())->setName('Serial test location');
        foreach ([$system, $range, $site] as $entity) {
            $em->persist($entity);
        } $em->flush();
        $siteId = $site->getId();
        $crawler = $client->request('GET', '/en/production/build/new');
        $client->submit($crawler->filter('form[name="build_start"]')->form([
            'build_start[content]' => 'system_'.$system->getId(),
        ]));
        $client->followRedirect();
        $crawler = $client->followRedirect();
        self::assertInputValueSame('details[n0][prefix]', 'WIZ');
        self::assertSelectorExists('[data-build-details-table].align-top');
        self::assertInputValueSame('details[n0][number]', '0001');
        $client->submit($crawler->selectButton('Material auswählen')->form([
            'site_id' => $siteId,
        ]));
        self::assertSelectorTextContains('.card-body', 'Bitte die Seriennummer prüfen');
        $crawler = $client->getCrawler();
        $client->submit($crawler->selectButton('Material auswählen')->form([
            'site_id' => $siteId,
            'details[n0][confirmed]' => '1',
        ]));
        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        $client->submit($crawler->filter('form')->last()->form());
        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('table', 'WIZ-0001');
        $client->submit($crawler->selectButton('Bau starten und Material ausbuchen')->form());
        self::assertResponseRedirects();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertNotNull($em->getRepository(BuildInstance::class)->findOneBy([
            'serialNumber' => 'WIZ-0001',
        ]));
    }

    public function testNumberFormattingPrefixAndOrdinalUniqueness(): void
    {
        $this->admin();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $numbers = self::getContainer()->get(SerialNumberManager::class);
        $system = (new SystemTemplate())->setName('Formatting system');
        $range = (new SerialNumberRange())->setName('Format')
            ->setPrefix('FMT')
            ->addSystem($system);
        $em->persist($system);
        $em->persist($range);
        $em->flush();
        foreach (['FMT-1', 'FMT-00001', 'FMT-0000', 'FMT-1e3', 'CID-0001', 'FMT-1000000000'] as $serial) {
            try {
                $numbers->validate($system, $serial);
                self::fail('Invalid serial accepted: '.$serial);
            } catch (\RuntimeException $error) {
                self::assertNotEmpty($error->getMessage());
            }
        }
        $db = $em->getConnection();
        $db->beginTransaction();
        $instance = (new BuildInstance())->setSystemTemplate($system)
            ->setSerialNumber('FMT-0001');
        $numbers->claim($instance, true);
        $em->persist($instance);
        $em->flush();
        $em->refresh($range);
        $range->setMinimumDigits(3)
            ->setNextNumber(1)
            ->setPrefix('NEW');
        $em->flush();
        self::assertSame('002', $numbers->suggest($system)['number']);
        try {
            $numbers->claim((new BuildInstance())->setSystemTemplate($system)->setSerialNumber('NEW-001'), true);
            self::fail('Ordinal reused');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('inzwischen vergeben', $error->getMessage());
        }
        $db->rollBack();
    }
}
