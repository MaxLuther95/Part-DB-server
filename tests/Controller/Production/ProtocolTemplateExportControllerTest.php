<?php

declare(strict_types=1);

namespace App\Tests\Controller\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\SystemTemplate;
use App\Entity\Production\ProtocolTemplate;
use App\Entity\Production\ProtocolTemplateRevision;
use App\Entity\UserSystem\User;
use App\Services\Production\ProtocolManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProtocolTemplateExportControllerTest extends WebTestCase
{
    public function testFilenamesPreserveUnicodeAndSafelyHandleSpecialCharacters(): void
    {
        $client = self::createClient();
        $client->catchExceptions(false);
        $this->login($client, 'admin');
        foreach ([
            ['Prüfung SEL', 'draft', 'Laufzettel_Prüfung SEL_Entwurf.json'],
            ['Kabel Messung', 'published', 'Laufzettel_Kabel Messung_Veröffentlicht.json'],
            ['SEL', 'retired', 'Laufzettel_SEL_Zurückgezogen.json'],
            ["Prüfung / SEL\\Kabel\r\nX-Evil: 100%?", 'draft', 'Laufzettel_Prüfung _ SEL_Kabel_X-Evil_ 100_Entwurf.json'],
            [' /\\:*?<>|% ', 'draft', 'Laufzettel_Vorlage_Entwurf.json'],
            [str_repeat('ä', 240), 'draft', 'Laufzettel_'.str_repeat('ä', 90).'_Entwurf.json'],
        ] as [$name, $status, $expected]) {
            $em = self::getContainer()->get(EntityManagerInterface::class);
            $template = (new ProtocolTemplate())->setName($name);
            $revision = new ProtocolTemplateRevision();
            $template->addRevision($revision);
            if ('draft' !== $status) {
                $revision->publish(null);
            }
            if ('retired' === $status) {
                $revision->retire();
            }
            $em->persist($template);
            $em->flush();
            $client->request('GET', '/de/production/protocol-revisions/'.$revision->getId().'/export');
            self::assertResponseIsSuccessful();
            $header = $client->getResponse()->headers->get('content-disposition');
            self::assertNotNull($header);
            if (preg_match("/filename\\*=utf-8''([^;]+)/", $header, $matches)) {
                $filename = rawurldecode($matches[1]);
            } else {
                preg_match('/filename=(?:"([^"]+)"|([^;]+))/', $header, $matches);
                $filename = '' !== $matches[1] ? $matches[1] : $matches[2];
            }
            self::assertSame($expected, $filename);
            self::assertLessThanOrEqual(255, strlen($filename));
            self::assertDoesNotMatchRegularExpression('~[/\\\\\r\n]~', $filename);
            self::assertFalse($client->getResponse()->headers->has('x-evil'));
            $data = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(trim($name), $data['templates'][0]['name']);
        }
    }

    public function testAdminCanDownloadOneRevisionOrAnExplicitBulkSelectionWithoutRunData(): void
    {
        $client = self::createClient();
        $client->catchExceptions(false);
        $this->login($client, 'admin');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $manager = self::getContainer()->get(ProtocolManager::class);
        $template = (new ProtocolTemplate())->setName('Export HTTP test');
        $system = (new SystemTemplate())->setName('DO-NOT-EXPORT-SYSTEM-ASSIGNMENT');
        $entityManager->persist($system);
        $template->addSystemTemplate($system);
        $revision = $manager->createInitialDraft($template);
        $section = $manager->addSection($revision)->setName('Measurements');
        $field = $manager->addField($section)->setLabel('Result');
        $instance = (new BuildInstance())->setSerialNumber('DO-NOT-EXPORT-SERIAL');
        $instance->setSystemTemplate($system);
        $entityManager->persist($template);
        $entityManager->persist($instance);
        $manager->publish($revision, null);
        $entityManager->flush();
        $run = $manager->createRun($instance, $revision, null);
        $run->getRowForSection($section)->getAnswerForField($field)->setValue('DO-NOT-EXPORT-MEASUREMENT');
        $run->setNotes('DO-NOT-EXPORT-PRIVATE-NOTES');
        $run->complete(null);
        $draft = $manager->getOrCreateDraft($template);
        $entityManager->persist($draft);
        $entityManager->flush();
        $revisionId = $revision->getId();
        $draftId = $draft->getId();
        $runId = $run->getId();

        $crawler = $client->request('GET', '/en/production/protocol-templates/'.$template->getId());
        self::assertCount(1, $crawler->filter('a[href$="/protocol-revisions/'.$revisionId.'/export"]'));
        $downloadLink = $crawler->filter('a[href$="/protocol-revisions/'.$revisionId.'/export"]');
        self::assertSame('false', $downloadLink->attr('data-turbo'));
        self::assertSame('_top', $downloadLink->attr('data-turbo-frame'));
        self::assertCount(1, $downloadLink->filter('[download]'));
        $client->click($downloadLink->link());
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json; charset=UTF-8');
        self::assertResponseHeaderSame('x-content-type-options', 'nosniff');
        self::assertStringContainsString('attachment;', $client->getResponse()->headers->get('content-disposition'));
        self::assertResponseHeaderSame('content-disposition', 'attachment; filename="Laufzettel_Export HTTP test_Published.json"');
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('cache-control'));
        $json = $client->getResponse()->getContent();
        self::assertStringNotContainsString('DO-NOT-EXPORT', $json);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $data['templates'][0]['revisions']);
        self::assertSame('published', $data['templates'][0]['revisions'][0]['status']);

        $crawler = $client->request('GET', '/en/production/protocol-templates');
        $crawler = $client->click($crawler->filter('a[href$="/protocol-templates/export"]')->link());
        self::assertResponseIsSuccessful();
        $downloadForm = $crawler->filter('form[name="protocol_template_export"]');
        self::assertSame('false', $downloadForm->attr('data-turbo'));
        self::assertSame('_top', $downloadForm->attr('data-turbo-frame'));
        self::assertCount(0, $crawler->filter('input[name="protocol_template_export[revisions][]"][checked]'));
        $form = $crawler->selectButton('Download JSON')->form();
        foreach ([$revisionId, $draftId] as $id) {
            self::assertCount(1, $crawler->filter('input[name="protocol_template_export[revisions][]"][value="'.$id.'"]'));
        }
        $submitted = $form->getPhpValues();
        $submitted['protocol_template_export']['revisions'] = [(string) $revisionId];
        $client->request('POST', '/en/production/protocol-templates/export', $submitted);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-disposition', 'attachment; filename="Laufzettel_Export HTTP test_Published.json"');
        $submitted['protocol_template_export']['revisions'] = [(string) $revisionId, (string) $draftId];
        $client->request('POST', '/en/production/protocol-templates/export', $submitted);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json; charset=UTF-8');
        self::assertResponseHeaderSame('content-disposition', 'attachment; filename=Laufzettel_Sammelexport.json');
        $data = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['published', 'draft'], array_column($data['templates'][0]['revisions'], 'status'));

        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame('completed', $connection->fetchOne('SELECT status FROM production_protocol_runs WHERE id = ?', [$runId]));
        self::assertSame('draft', $connection->fetchOne('SELECT status FROM production_protocol_template_revisions WHERE id = ?', [$draftId]));
        self::assertSame('DO-NOT-EXPORT-MEASUREMENT', $connection->fetchOne('SELECT text_value FROM production_protocol_answers WHERE field_id = ?', [$field->getId()]));
    }

    public function testSelectionGroupsByTemplateIdentityAndKeepsChosenGroupsOpenAfterErrors(): void
    {
        $client = self::createClient();
        $this->login($client, 'admin');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $templates = [];
        foreach ([1, 3] as $count) {
            $template = (new ProtocolTemplate())->setName('Same visible name');
            for ($number = 1; $number <= $count; ++$number) {
                $template->addRevision((new ProtocolTemplateRevision())->setRevisionNumber($number));
            }
            $em->persist($template);
            $templates[] = $template;
        }
        $em->flush();
        $singleId = $templates[0]->getId();
        $multipleId = $templates[1]->getId();
        $revisionId = $templates[1]->getRevisions()->first()->getId();
        $crawler = $client->request('GET', '/en/production/protocol-templates/export');
        self::assertSelectorExists('div[data-export-template="'.$singleId.'"] input[type="checkbox"]');
        self::assertSelectorNotExists('div[data-export-template="'.$singleId.'"] details');
        self::assertSelectorExists('details[data-export-template="'.$multipleId.'"]:not([open])');
        self::assertCount(3, $crawler->filter('details[data-export-template="'.$multipleId.'"] input[type="checkbox"]'));
        self::assertSelectorTextContains('details[data-export-template="'.$multipleId.'"] summary', 'Same visible name');
        $data = $crawler->selectButton('Download JSON')->form()->getPhpValues();
        $data['protocol_template_export']['revisions'] = [(string) $revisionId];
        $data['protocol_template_export']['_token'] = 'invalid';
        $client->request('POST', '/en/production/protocol-templates/export', $data);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('details[data-export-template="'.$multipleId.'"][open]');
        self::assertSelectorExists('input[value="'.$revisionId.'"][checked]');
        self::assertFalse($client->getResponse()->headers->has('content-disposition'));
    }

    public function testInvalidSelectionAndCsrfNeverReturnAnExport(): void
    {
        $client = self::createClient();
        $this->login($client, 'admin');
        foreach ([[], ['2147483647']] as $selection) {
            $crawler = $client->request('GET', '/en/production/protocol-templates/export');
            $data = $crawler->selectButton('Download JSON')->form()->getPhpValues();
            $data['protocol_template_export']['revisions'] = $selection;
            $client->request('POST', '/en/production/protocol-templates/export', $data);
            self::assertResponseStatusCodeSame(422);
            self::assertFalse($client->getResponse()->headers->has('content-disposition'));
        }
        $client->request('POST', '/en/production/protocol-templates/export', [
            'protocol_template_export' => ['_token' => 'invalid', 'revisions' => []],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertFalse($client->getResponse()->headers->has('content-disposition'));
        $client->request('GET', '/en/production/protocol-revisions/2147483647/export');
        self::assertResponseStatusCodeSame(404);
    }

    public function testOrdinaryReadersCannotExport(): void
    {
        $client = self::createClient();
        $this->login($client, 'user');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $template = (new ProtocolTemplate())->setName('Restricted export');
        $revision = new ProtocolTemplateRevision();
        $template->addRevision($revision);
        $entityManager->persist($template);
        $entityManager->flush();
        $revisionId = $revision->getId();
        foreach (['GET', 'POST'] as $method) {
            $client->request($method, '/en/production/protocol-templates/export');
            self::assertResponseStatusCodeSame(403);
        }
        $client->request('GET', '/en/production/protocol-revisions/'.$revisionId.'/export');
        self::assertResponseStatusCodeSame(403);
        $crawler = $client->request('GET', '/en/production/protocol-templates');
        self::assertCount(0, $crawler->filter('a[href$="/protocol-templates/export"]'));
    }

    public function testAnonymousRequestsCannotExport(): void
    {
        $client = self::createClient();
        $client->request('GET', '/en/production/protocol-templates/export');
        self::assertResponseStatusCodeSame(401);
        self::assertFalse($client->getResponse()->headers->has('content-disposition'));
    }

    public function testAdministratorStillNeedsTemplateReadPermission(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $entityManager->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $admin->getPermissions()->setPermissionValue('production_protocol_templates', 'read', false);
        $entityManager->flush();
        $this->login($client, 'admin');
        $client->request('GET', '/en/production/protocol-templates/export');
        self::assertResponseStatusCodeSame(403);
    }

    private function login(KernelBrowser $client, string $name): void
    {
        $user = self::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(User::class, $user);
        $user->setNeedPwChange(false);
        $client->loginUser($user);
    }
}
